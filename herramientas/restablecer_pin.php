<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Restablece el PIN de un usuario (por ejemplo, si el dueño lo olvidó).
 * Solo funciona desde la consola de la computadora de la tienda:
 *
 *     php\php.exe -c php\php.ini www\herramientas\restablecer_pin.php            (lista usuarios)
 *     php\php.exe -c php\php.ini www\herramientas\restablecer_pin.php Ana 1234   (nuevo PIN)
 *
 * (desde la carpeta donde está instalado el Punto de venta)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../configuracion.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/funciones.php';
require_once __DIR__ . '/../includes/usuarios.php';

$nombre = $argv[1] ?? null;
$pin    = $argv[2] ?? null;

if ($nombre === null) {
    echo "Usuarios:\n";
    foreach (db()->query('SELECT nombre, rol, activo FROM usuarios ORDER BY nombre') as $u) {
        echo '  ' . $u['nombre'] . ' (' . ($u['rol'] === 'dueno' ? 'dueño' : 'cajero') . ($u['activo'] ? '' : ', inactivo') . ")\n";
    }
    echo "\nUso: restablecer_pin.php <nombre> <pin de 4 a 6 números>\n";
    exit(0);
}
if ($pin === null || !pin_valido($pin)) {
    fwrite(STDERR, "El PIN debe tener de 4 a 6 números.\n");
    exit(1);
}
$st = db()->prepare('UPDATE usuarios SET pin_hash = :pin, activo = 1 WHERE nombre = :nombre');
$st->execute([':pin' => password_hash($pin, PASSWORD_DEFAULT), ':nombre' => $nombre]);
if ($st->rowCount() !== 1) {
    fwrite(STDERR, "No existe el usuario «{$nombre}».\n");
    exit(1);
}
echo "Listo: «{$nombre}» ya puede entrar con el PIN nuevo.\n";
