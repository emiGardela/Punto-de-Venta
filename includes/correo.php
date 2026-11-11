<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * TiendaWeb - Envío de correos por SMTP (Gmail, Outlook, el del proveedor de Internet…).
 *
 * Sin librerías externas: habla SMTP directamente, con SSL (puerto 465) o
 * STARTTLS (puerto 587). Los certificados se revisan con los de Windows.
 */

declare(strict_types=1);

/** Servidores conocidos para llenar la configuración con un clic: [servidor, puerto, seguridad, ayuda]. */
const SERVIDORES_CORREO = [
    'gmail'   => ['Gmail', 'smtp.gmail.com', '465', 'ssl', 'Necesitas una "contraseña de aplicación": en tu cuenta de Google → Seguridad → Verificación en 2 pasos → Contraseñas de aplicaciones.'],
    'outlook' => ['Outlook / Hotmail', 'smtp-mail.outlook.com', '587', 'tls', 'Usa tu correo y contraseña de Outlook. Si tienes verificación en 2 pasos, crea una contraseña de aplicación.'],
    'yahoo'   => ['Yahoo', 'smtp.mail.yahoo.com', '465', 'ssl', 'Necesitas una contraseña de aplicación: Información de la cuenta → Seguridad de la cuenta.'],
];

/** ¿Ya está configurado el correo para mandar facturas? */
function correo_configurado(): bool
{
    return ajuste('correo_servidor') !== '' && filter_var(ajuste('correo_usuario'), FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Manda un correo. $adjuntos: [['nombre' => 'factura.pdf', 'contenido' => bytes, 'tipo' => 'application/pdf'], ...]
 * Lanza RuntimeException con un mensaje claro si algo falla.
 */
function enviar_correo(string $para, string $asunto, string $texto, array $adjuntos = [], ?array $aj = null): void
{
    $aj ??= ajustes();
    $de = $aj['correo_usuario'];
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('El correo del cliente no es válido: ' . $para);
    }
    if ($aj['correo_servidor'] === '' || !filter_var($de, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Primero configura el correo en Configuración → "Correo para mandar facturas".');
    }
    $smtp = new ClienteSmtp($aj['correo_servidor'], (int) $aj['correo_puerto'], $aj['correo_seguridad']);
    try {
        $smtp->conectar();
        if ($aj['correo_contrasena'] !== '') {
            $smtp->autenticar($de, $aj['correo_contrasena']);
        }
        $smtp->enviar($de, $para, mensaje_mime($de, $aj['correo_nombre'] ?: nombre_negocio(), $para, $asunto, $texto, $adjuntos));
    } finally {
        $smtp->cerrar();
    }
}

/** Arma el mensaje completo (encabezados y cuerpo MIME con adjuntos). */
function mensaje_mime(string $de, string $nombre, string $para, string $asunto, string $texto, array $adjuntos): string
{
    $codificar = fn(string $s) => preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    $limite = '=_tw' . bin2hex(random_bytes(12));
    $dominio = substr(strrchr($de, '@') ?: '@tiendaweb.local', 1);
    $m = 'From: ' . $codificar(str_replace(['"', "\r", "\n"], '', $nombre)) . " <$de>\r\n"
       . "To: <$para>\r\n"
       . 'Subject: ' . $codificar(str_replace(["\r", "\n"], ' ', $asunto)) . "\r\n"
       . 'Date: ' . date(DATE_RFC2822) . "\r\n"
       . 'Message-ID: <' . bin2hex(random_bytes(16)) . "@$dominio>\r\n"
       . "MIME-Version: 1.0\r\n"
       . "Content-Type: multipart/mixed; boundary=\"$limite\"\r\n\r\n"
       . "--$limite\r\n"
       . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
       . chunk_split(base64_encode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $texto))));
    foreach ($adjuntos as $a) {
        $archivo = str_replace(['"', "\r", "\n", '/', '\\'], '', $a['nombre']);
        $m .= "--$limite\r\n"
            . 'Content-Type: ' . ($a['tipo'] ?? 'application/octet-stream') . "; name=\"$archivo\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"$archivo\"\r\n\r\n"
            . chunk_split(base64_encode($a['contenido']));
    }
    return $m . "--$limite--\r\n";
}

/** Cliente SMTP mínimo: conectar, autenticar (AUTH LOGIN) y mandar un mensaje. */
final class ClienteSmtp
{
    /** @var resource|null */
    private $conexion = null;

    public function __construct(private string $servidor, private int $puerto, private string $seguridad) {}

    public function conectar(): void
    {
        $contexto = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->servidor]]);
        $destino = ($this->seguridad === 'ssl' ? 'ssl://' : 'tcp://') . $this->servidor . ':' . $this->puerto;
        $c = @stream_socket_client($destino, $num, $msg, 20, STREAM_CLIENT_CONNECT, $contexto);
        if (!$c) {
            throw new RuntimeException("No se pudo conectar con el servidor de correo ({$this->servidor}:{$this->puerto}). Revisa que haya Internet y que el servidor y el puerto estén bien.");
        }
        stream_set_timeout($c, 30);
        $this->conexion = $c;
        $this->esperar(220);
        $this->saludar();
        if ($this->seguridad === 'tls') {
            $this->comando('STARTTLS', 220);
            if (!@stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('El servidor de correo no aceptó la conexión segura (TLS).');
            }
            $this->saludar();
        }
    }

    public function autenticar(string $usuario, string $contrasena): void
    {
        $this->comando('AUTH LOGIN', 334);
        $this->comando(base64_encode($usuario), 334);
        try {
            $this->comando(base64_encode($contrasena), 235);
        } catch (RuntimeException) {
            throw new RuntimeException('El servidor de correo rechazó el usuario o la contraseña. En Gmail y Yahoo tienes que usar una "contraseña de aplicación", no la normal.');
        }
    }

    public function enviar(string $de, string $para, string $mensaje): void
    {
        $this->comando("MAIL FROM:<$de>", 250);
        $this->comando("RCPT TO:<$para>", [250, 251]);
        $this->comando('DATA', 354);
        // Las líneas que empiezan con punto se duplican (regla de SMTP).
        $cuerpo = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $mensaje));
        $this->escribir(str_replace("\n", "\r\n", rtrim($cuerpo, "\n")) . "\r\n.\r\n");
        $this->esperar(250);
    }

    public function cerrar(): void
    {
        if ($this->conexion) {
            @fwrite($this->conexion, "QUIT\r\n");
            @fclose($this->conexion);
            $this->conexion = null;
        }
    }

    private function saludar(): void
    {
        $this->comando('EHLO ' . (gethostname() ?: 'tiendaweb'), 250);
    }

    private function comando(string $linea, int|array $esperado): string
    {
        $this->escribir($linea . "\r\n");
        return $this->esperar($esperado);
    }

    private function escribir(string $datos): void
    {
        if (@fwrite($this->conexion, $datos) === false) {
            throw new RuntimeException('Se perdió la conexión con el servidor de correo.');
        }
    }

    /** Lee la respuesta (puede ser de varias líneas) y revisa el código. */
    private function esperar(int|array $esperado): string
    {
        $respuesta = '';
        while (($linea = fgets($this->conexion, 1024)) !== false) {
            $respuesta .= $linea;
            if (strlen($linea) < 4 || $linea[3] === ' ') {
                break;
            }
        }
        $codigo = (int) substr($respuesta, 0, 3);
        if (!in_array($codigo, (array) $esperado, true)) {
            throw new RuntimeException('El servidor de correo respondió: ' . (trim($respuesta) ?: 'nada (se acabó el tiempo de espera)'));
        }
        return $respuesta;
    }
}
