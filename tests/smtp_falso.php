<?php
/**
 * Servidor de correo (SMTP) falso para las pruebas: no manda nada, solo
 * guarda cada mensaje recibido en un archivo .eml.
 *
 *     php smtp_falso.php PUERTO CARPETA
 *
 * Acepta AUTH LOGIN solo con tienda@prueba.mx / clave-buena.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
[$puerto, $carpeta] = [(int) ($argv[1] ?? 0), (string) ($argv[2] ?? '')];
$servidor = stream_socket_server("tcp://127.0.0.1:$puerto", $num, $msg);
if (!$servidor) {
    fwrite(STDERR, "No pude escuchar: $msg\n");
    exit(1);
}
$n = 0;
while ($c = @stream_socket_accept($servidor, -1)) {
    $responder = fn(string $l) => fwrite($c, $l . "\r\n");
    $responder('220 smtp-falso listo');
    $usuario = null;
    $de = $para = '';
    while (($linea = fgets($c)) !== false) {
        $linea = rtrim($linea, "\r\n");
        $cmd = strtoupper(substr($linea, 0, 4));
        if ($cmd === 'EHLO' || $cmd === 'HELO') {
            $responder('250-smtp-falso');
            $responder('250 AUTH LOGIN');
        } elseif ($linea === 'AUTH LOGIN') {
            $responder('334 VXNlcm5hbWU6');
            $usuario = base64_decode(rtrim((string) fgets($c), "\r\n"));
            $responder('334 UGFzc3dvcmQ6');
            $clave = base64_decode(rtrim((string) fgets($c), "\r\n"));
            $responder($usuario === 'tienda@prueba.mx' && $clave === 'clave-buena' ? '235 OK' : '535 Credenciales malas');
        } elseif ($cmd === 'MAIL') {
            $de = $linea;
            $responder('250 OK');
        } elseif ($cmd === 'RCPT') {
            $para = $linea;
            $responder('250 OK');
        } elseif ($cmd === 'DATA') {
            $responder('354 Adelante');
            $mensaje = '';
            while (($l = fgets($c)) !== false && rtrim($l, "\r\n") !== '.') {
                $mensaje .= $l;
            }
            file_put_contents($carpeta . DIRECTORY_SEPARATOR . 'correo-' . (++$n) . '.eml', "X-Sobre: $de | $para\r\n" . $mensaje);
            $responder('250 Guardado');
        } elseif ($cmd === 'QUIT') {
            $responder('221 Adios');
            break;
        } else {
            $responder('250 OK');
        }
    }
    fclose($c);
}
