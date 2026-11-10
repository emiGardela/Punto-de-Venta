<?php
/**
 * Limpieza de la base (mantenimiento). Solo desde la consola de la
 * computadora de la tienda, con el Punto de venta cerrado. Desde la carpeta donde
 * está instalado (%LOCALAPPDATA%\Programs\TiendaWeb):
 *
 *     php\php.exe -c php\php.ini www\herramientas\limpiar_base.php            (muestra los modos)
 *     php\php.exe -c php\php.ini www\herramientas\limpiar_base.php ventas
 *     php\php.exe -c php\php.ini www\herramientas\limpiar_base.php todo
 *     php\php.exe -c php\php.ini www\herramientas\limpiar_base.php fabrica
 *
 * Antes de borrar guarda un respaldo completo (se puede deshacer con
 * Respaldos → Restaurar) y pide escribir BORRAR. Los folios vuelven a 000001.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../configuracion.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/funciones.php';
require_once __DIR__ . '/../includes/respaldos.php';
require_once __DIR__ . '/../includes/auditoria.php';
require_once __DIR__ . '/../includes/limpieza.php';

$modo = $argv[1] ?? null;

$cuenta = fn(string $tabla) => (int) db()->query("SELECT COUNT(*) FROM $tabla")->fetchColumn();
echo "Base de datos: " . archivo_base_datos() . "\n";
echo '  ' . $cuenta('productos') . ' productos, ' . $cuenta('ventas') . ' ventas, ' . $cuenta('turnos') . ' cortes, '
    . $cuenta('clientes') . ' clientes, ' . $cuenta('usuarios') . " usuarios\n\n";

if ($modo === null || !isset(MODOS_LIMPIEZA[$modo])) {
    echo "Uso: limpiar_base.php <modo>\n\n";
    foreach (MODOS_LIMPIEZA as $clave => $descripcion) {
        echo '  ' . str_pad($clave, 8) . ' ' . wordwrap($descripcion, 68, "\n           ") . "\n\n";
    }
    exit($modo === null ? 0 : 1);
}

echo "Modo «{$modo}»: " . MODOS_LIMPIEZA[$modo] . "\n";
echo "Se borrarán: " . implode(', ', tablas_limpieza($modo)) . ".\n";
echo "Cierra el Punto de venta antes de continuar.\n\n";
echo 'Escribe BORRAR para confirmar: ';
if (trim((string) fgets(STDIN)) !== 'BORRAR') {
    echo "Cancelado. No se borró nada.\n";
    exit(1);
}

$respaldo = carpeta_respaldos() . DIRECTORY_SEPARATOR . 'antes-de-limpiar-' . $modo . '-' . date('Y-m-d-His') . '.sqlite';
crear_respaldo($respaldo);
echo "Respaldo guardado: $respaldo\n";

try {
    $borrados = limpiar_base($modo);
} catch (Throwable $e) {
    fwrite(STDERR, 'No se borró nada: ' . $e->getMessage() . "\n");
    exit(1);
}
foreach ($borrados as $tabla => $n) {
    echo '  ' . str_pad($tabla, 24) . "$n borrado(s)\n";
}
db()->exec('VACUUM');   // achica el archivo

echo "\nListo. Los folios vuelven a empezar en 000001.\n";
if ($modo === 'fabrica') {
    echo "Al abrir el Punto de venta aparecerá el asistente de primer uso.\n";
}
echo "Para deshacerlo: Respaldos → Restaurar una copia → elige el archivo de arriba.\n";
