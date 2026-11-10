<?php
/**
 * TiendaWeb - Utilidades de las pruebas automáticas (sin dependencias externas).
 *
 * - Aserciones sencillas que cuentan aciertos y fallas.
 * - Un servidor PHP de prueba con su propia carpeta de datos (nunca toca la
 *   base real del negocio).
 * - Un "navegador" mínimo con cookies para probar las páginas como un usuario.
 */

declare(strict_types=1);

final class Pruebas
{
    public static int $ok = 0;
    public static array $fallas = [];
    private static string $grupo = '';

    public static function grupo(string $nombre): void
    {
        self::$grupo = $nombre;
        echo "\n== $nombre\n";
    }

    public static function igual(mixed $esperado, mixed $real, string $que): void
    {
        if ($esperado == $real) {
            self::$ok++;
            echo "  ok    $que\n";
        } else {
            self::$fallas[] = self::$grupo . ' › ' . $que;
            echo "  FALLA $que\n        esperado: " . var_export($esperado, true) . "\n        obtenido: "
                . mb_substr(var_export($real, true), 0, 300) . "\n";
        }
    }

    public static function contiene(string $aguja, string $pajar, string $que): void
    {
        self::igual(true, str_contains($pajar, $aguja), $que . ($pajar === '' ? ' (respuesta vacía)' : ''));
    }

    public static function verdadero(bool $condicion, string $que): void
    {
        self::igual(true, $condicion, $que);
    }
}

/** Servidor PHP de prueba con una carpeta de datos temporal. */
final class ServidorPrueba
{
    public string $url;
    public string $datos;
    /** @var resource */
    private $proceso;

    public function __construct(string $raiz, ?string $baseInicial = null)
    {
        $this->datos = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tiendaweb-pruebas-' . bin2hex(random_bytes(4));
        mkdir($this->datos . DIRECTORY_SEPARATOR . 'local' . DIRECTORY_SEPARATOR . 'TiendaWeb', 0775, true);
        if ($baseInicial !== null) {
            copy($baseInicial, $this->datos . DIRECTORY_SEPARATOR . 'tiendaweb.sqlite');
        }
        $puerto = self::puertoLibre();
        $this->url = "http://127.0.0.1:$puerto";

        $ini = php_ini_loaded_file();
        $cmd = [PHP_BINARY];
        if ($ini) {
            array_push($cmd, '-c', $ini);
        }
        // Los errores de PHP van a un archivo propio de la prueba (cualquiera que sea el php.ini) para revisarlos al final.
        array_push($cmd, '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'error_reporting=-1',
            '-d', 'error_log=' . $this->datos . DIRECTORY_SEPARATOR . 'local' . DIRECTORY_SEPARATOR . 'TiendaWeb' . DIRECTORY_SEPARATOR . 'errores-php.log',
            '-S', "127.0.0.1:$puerto", '-t', $raiz);
        $entorno = array_merge(getenv(), [
            'TIENDAWEB_DATOS' => $this->datos,
            'LOCALAPPDATA'    => $this->datos . DIRECTORY_SEPARATOR . 'local',
        ]);
        $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $this->proceso = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']],
            $tuberias, dirname(PHP_BINARY), $entorno);
        for ($i = 0; $i < 50; $i++) {   // espera a que responda (hasta 5 s)
            $s = @fsockopen('127.0.0.1', $puerto, $errno, $errstr, 0.1);
            if ($s) {
                fclose($s);
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('No arrancó el servidor de prueba.');
    }

    public function base(): string
    {
        return $this->datos . DIRECTORY_SEPARATOR . 'tiendaweb.sqlite';
    }

    /** Consulta directa a la base de prueba (para verificar lo que se guardó). */
    public function sql(string $consulta, array $params = []): array
    {
        $pdo = new PDO('sqlite:' . $this->base(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $st = $pdo->prepare($consulta);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function valor(string $consulta, array $params = []): mixed
    {
        $fila = $this->sql($consulta, $params)[0] ?? [];
        return $fila ? reset($fila) : null;
    }

    public function registroErrores(): string
    {
        $archivo = $this->datos . DIRECTORY_SEPARATOR . 'local' . DIRECTORY_SEPARATOR . 'TiendaWeb' . DIRECTORY_SEPARATOR . 'errores-php.log';
        return is_file($archivo) ? (string) file_get_contents($archivo) : '';
    }

    public function detener(): void
    {
        $estado = proc_get_status($this->proceso);
        if (PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /F /T /PID ' . (int) $estado['pid'] . ' >NUL 2>&1');
        } else {
            proc_terminate($this->proceso);
        }
        proc_close($this->proceso);
        usleep(300000);
        self::borrar($this->datos);
    }

    private static function puertoLibre(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $nombre = stream_socket_get_name($s, false);
        fclose($s);
        return (int) substr($nombre, strrpos($nombre, ':') + 1);
    }

    private static function borrar(string $carpeta): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($carpeta, FilesystemIterator::SKIP_DOTS),
                     RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($carpeta);
    }
}

/** Navegador mínimo con cookies (una sesión por instancia). */
final class Navegador
{
    private array $cookies = [];
    public int $estado = 0;
    public ?string $redireccion = null;

    public function __construct(private readonly string $base)
    {
    }

    public function get(string $ruta, array $cabeceras = []): string
    {
        return $this->pedir('GET', $ruta, null, $cabeceras);
    }

    public function post(string $ruta, array $campos): string
    {
        return $this->pedir('POST', $ruta, http_build_query($campos), ['Content-Type: application/x-www-form-urlencoded']);
    }

    /** POST JSON con el token CSRF de la sesión. Devuelve la respuesta decodificada. */
    public function json(string $ruta, array $cuerpo): array
    {
        $r = $this->pedir('POST', $ruta, json_encode($cuerpo), [
            'Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $this->csrf(),
        ]);
        return json_decode($r, true) ?? ['ok' => false, 'mensaje' => 'Respuesta no JSON: ' . mb_substr($r, 0, 200)];
    }

    /** Token CSRF de la sesión (lo lee de cualquier página que lo tenga). */
    public function csrf(string $pagina = 'index.php'): string
    {
        $html = $this->get($pagina);
        if (preg_match('/name="csrf-token" content="([0-9a-f]{64})"/', $html, $m)
            || preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    /** Envía un formulario con el token CSRF incluido. */
    public function enviar(string $ruta, array $campos, string $paginaToken = 'index.php'): string
    {
        return $this->post($ruta, ['csrf_token' => $this->csrf($paginaToken)] + $campos);
    }

    /** Envía un formulario con un archivo (multipart), con el token CSRF incluido. */
    public function subir(string $ruta, array $campos, string $campoArchivo, string $nombreArchivo, string $contenido): string
    {
        $limite = '----tw' . bin2hex(random_bytes(8));
        $cuerpo = '';
        foreach (['csrf_token' => $this->csrf()] + $campos as $k => $v) {
            $cuerpo .= "--$limite\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
        }
        $cuerpo .= "--$limite\r\nContent-Disposition: form-data; name=\"$campoArchivo\"; filename=\"$nombreArchivo\"\r\n"
            . "Content-Type: text/csv\r\n\r\n$contenido\r\n--$limite--\r\n";
        return $this->pedir('POST', $ruta, $cuerpo, ["Content-Type: multipart/form-data; boundary=$limite"]);
    }

    /** Envía un formulario con varios archivos: $archivos = [campo => [nombre, contenido]]. */
    public function subirArchivos(string $ruta, array $campos, array $archivos): string
    {
        $limite = '----tw' . bin2hex(random_bytes(8));
        $cuerpo = '';
        foreach (['csrf_token' => $this->csrf()] + $campos as $k => $v) {
            $cuerpo .= "--$limite
Content-Disposition: form-data; name=\"$k\"

$v
";
        }
        foreach ($archivos as $campo => [$nombre, $contenido]) {
            $cuerpo .= "--$limite
Content-Disposition: form-data; name=\"$campo\"; filename=\"$nombre\"
"
                . "Content-Type: application/octet-stream

$contenido
";
        }
        return $this->pedir('POST', $ruta, $cuerpo . "--$limite--
", ["Content-Type: multipart/form-data; boundary=$limite"]);
    }

    private function pedir(string $metodo, string $ruta, ?string $cuerpo, array $cabeceras): string
    {
        if ($this->cookies) {
            $cabeceras[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        $contexto = stream_context_create(['http' => [
            'method' => $metodo, 'header' => implode("\r\n", $cabeceras), 'content' => $cuerpo ?? '',
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20,
        ]]);
        $respuesta = @file_get_contents($this->base . '/' . ltrim($ruta, '/'), false, $contexto);
        $this->estado = 0;
        $this->redireccion = null;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) {
                $this->estado = (int) $m[1];
            } elseif (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) {
                $this->cookies[$m[1]] = $m[2];
            } elseif (preg_match('/^Location:\s*(.+)$/i', $h, $m)) {
                $this->redireccion = trim($m[1]);
            }
        }
        return (string) $respuesta;
    }
}

/** Servidor de correo falso (tests/smtp_falso.php): guarda los mensajes en una carpeta temporal. */
final class SmtpFalso
{
    public int $puerto;
    public string $carpeta;
    /** @var resource */
    private $proceso;

    public function __construct()
    {
        $this->carpeta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tiendaweb-smtp-' . bin2hex(random_bytes(4));
        mkdir($this->carpeta);
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $nombre = stream_socket_get_name($s, false);
        fclose($s);
        $this->puerto = (int) substr($nombre, strrpos($nombre, ':') + 1);
        $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $this->proceso = proc_open([PHP_BINARY, __DIR__ . '/smtp_falso.php', (string) $this->puerto, $this->carpeta],
            [0 => ['pipe', 'r'], 1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']], $tuberias);
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $this->puerto, $errno, $errstr, 0.1);
            if ($c) {
                fclose($c);   // el servidor atiende esta conexión vacía y sigue esperando
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('No arrancó el servidor de correo falso.');
    }

    /** Mensajes recibidos (texto completo de cada uno), en orden. */
    public function correos(): array
    {
        $archivos = glob($this->carpeta . DIRECTORY_SEPARATOR . 'correo-*.eml') ?: [];
        natsort($archivos);
        return array_map('file_get_contents', array_values($archivos));
    }

    public function detener(): void
    {
        $estado = proc_get_status($this->proceso);
        if (PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /F /T /PID ' . (int) $estado['pid'] . ' >NUL 2>&1');
        } else {
            proc_terminate($this->proceso);
        }
        proc_close($this->proceso);
        array_map('unlink', glob($this->carpeta . DIRECTORY_SEPARATOR . '*') ?: []);
        @rmdir($this->carpeta);
    }
}

/** Renglones de la primera hoja de un .xlsx de TiendaWeb (sin compresión): [[valor, ...], ...]. */
function renglones_excel(string $bytes): array
{
    $ini = strpos($bytes, '<worksheet');
    $fin = strpos($bytes, '</worksheet>');
    if ($ini === false || $fin === false) {
        return [];
    }
    $hoja = simplexml_load_string(substr($bytes, $ini, $fin - $ini + 12));
    $renglones = [];
    foreach ($hoja->sheetData->row as $row) {
        $r = [];
        foreach ($row->c as $c) {
            $r[] = (string) $c['t'] === 'inlineStr' ? (string) $c->is->t : (string) $c->v;
        }
        $renglones[] = $r;
    }
    return $renglones;
}
