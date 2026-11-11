<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Copia productos y ventas de la versión anterior (MySQL de XAMPP) a la
 * base SQLite de la app de escritorio. Se usa UNA sola vez, desde la consola:
 *
 *     C:\xampp\php\php.exe herramientas\importar_desde_mysql.php
 *
 * Requiere que MySQL esté iniciado en XAMPP. Si la base SQLite ya tiene
 * ventas no hace nada, salvo que se agregue --forzar (antes guarda un
 * respaldo de lo que haya).
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

$forzar = in_array('--forzar', $argv, true);

try {
    $mysql = new PDO('mysql:host=127.0.0.1;port=3306;dbname=tiendaweb;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "No se pudo conectar a MySQL. ¿Está iniciado en XAMPP?\n" . $e->getMessage() . "\n");
    exit(1);
}

$pdo = db();
$ventasActuales = (int) $pdo->query('SELECT COUNT(*) FROM ventas')->fetchColumn();
if ($ventasActuales > 0 && !$forzar) {
    fwrite(STDERR, "La base SQLite ya tiene $ventasActuales venta(s). Para reemplazarlas usa --forzar.\n");
    exit(1);
}

$copia = carpeta_respaldos() . DIRECTORY_SEPARATOR . 'antes-de-importar-' . date('Y-m-d-His') . '.sqlite';
crear_respaldo($copia);

try {
    transaccion_iniciar();
    // Se vacían todas las tablas de datos (menos usuarios) y se copian productos y ventas de MySQL.
    foreach (array_reverse(TABLAS_RESPALDO) as $tabla) {
        if ($tabla !== 'usuarios') {
            $pdo->exec("DELETE FROM $tabla");
            $pdo->exec('DELETE FROM sqlite_sequence WHERE name = ' . $pdo->quote($tabla));
        }
    }
    foreach (['productos', 'ventas', 'detalle_ventas'] as $tabla) {
        // Solo las columnas que existen en las dos bases (la de MySQL es de una versión anterior).
        $deMysql  = array_column($mysql->query("SHOW COLUMNS FROM $tabla")->fetchAll(), 'Field');
        $deSqlite = array_column($pdo->query("PRAGMA table_info($tabla)")->fetchAll(), 'name');
        $columnas = array_values(array_intersect($deSqlite, $deMysql));
        $lista  = implode(', ', $columnas);
        $marcas = implode(', ', array_map(fn($c) => ':' . $c, $columnas));
        $insertar = $pdo->prepare("INSERT INTO $tabla ($lista) VALUES ($marcas)");
        $filas = 0;
        foreach ($mysql->query("SELECT $lista FROM $tabla ORDER BY id") as $fila) {
            $params = [];
            foreach ($columnas as $c) {
                $params[':' . $c] = $fila[$c];
            }
            $insertar->execute($params);
            $filas++;
        }
        echo str_pad($tabla, 16) . "$filas registro(s)\n";
    }
    $pdo->exec('UPDATE ventas SET subtotal = total');
    $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, fecha, tipo, cantidad, existencia_final, costo, nota)
                   SELECT id, :fecha, 'inicial', existencia, existencia, costo, 'Existencia importada de MySQL'
                   FROM productos WHERE controlar_stock = 1")->execute([':fecha' => date('Y-m-d H:i:s')]);
    transaccion_confirmar();
} catch (Throwable $e) {
    transaccion_revertir();
    fwrite(STDERR, 'No se importó nada: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Listo. Datos guardados en " . archivo_base_datos() . "\n";
echo "Respaldo previo: $copia\n";
