<?php
/**
 * Corre todas las pruebas automáticas de TiendaWeb.
 *
 *     C:\xampp\php\php.exe tests\correr.php
 *
 * Cada prueba usa su propia base temporal: nunca toca los datos del negocio.
 * Devuelve código 0 si todo pasó y 1 si algo falló.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$inicio = microtime(true);
// Las funciones del sistema se cargan contra una base temporal desechable.
putenv('TIENDAWEB_DATOS=' . sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tiendaweb-pruebas-unitarias');
require_once __DIR__ . '/../configuracion.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/funciones.php';
require_once __DIR__ . '/../includes/ajustes.php';
require_once __DIR__ . '/../includes/giros.php';
require_once __DIR__ . '/../includes/impresora.php';
require_once __DIR__ . '/lib.php';

foreach (['unitarias.php', 'flujos.php'] as $archivo) {
    try {
        require __DIR__ . '/' . $archivo;
    } catch (Throwable $e) {
        Pruebas::$fallas[] = "$archivo se detuvo: " . $e->getMessage();
        echo "\n  ERROR en $archivo: " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    }
}

$segundos = round(microtime(true) - $inicio, 1);
echo "\n" . str_repeat('=', 60) . "\n";
echo Pruebas::$ok . ' pruebas pasaron, ' . count(Pruebas::$fallas) . " fallaron ({$segundos} s)\n";
foreach (Pruebas::$fallas as $f) {
    echo "  ✗ $f\n";
}
exit(Pruebas::$fallas ? 1 : 0);
