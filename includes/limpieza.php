<?php
/**
 * TiendaWeb - Limpieza de la base y productos de ejemplo.
 *
 * La usan el asistente de primer uso (bienvenida.php) y la herramienta de
 * mantenimiento por consola (herramientas/limpiar_base.php). No hay ningún
 * botón en el sistema que la llame: borrar el historial de una tienda en
 * operación lo decide quien da mantenimiento, no un cajero.
 *
 * Los folios vuelven a empezar en 000001 en todo lo que se borra.
 */

declare(strict_types=1);

require_once __DIR__ . '/giros.php';

const MODOS_LIMPIEZA = [
    'ventas'  => 'Borra ventas, facturas pedidas, cortes, movimientos de caja, abonos e historial de inventario. Conserva productos (con su existencia actual), clientes, usuarios y configuración.',
    'todo'    => 'Borra además productos, precios de mayoreo, categorías, proveedores y clientes. Conserva usuarios y configuración.',
    'fabrica' => 'Deja el sistema como recién instalado: borra todo, incluidos usuarios y configuración. Al abrirlo aparece el asistente de primer uso.',
];

/** Tablas que borra cada modo, en orden (primero las que dependen de otras). */
function tablas_limpieza(string $modo): array
{
    $tablas = ['movimientos_inventario', 'movimientos_caja', 'abonos', 'devolucion_productos', 'devoluciones', 'factura_archivos', 'facturas', 'pagos', 'detalle_ventas',
               'ventas', 'compra_productos', 'compras', 'turnos'];
    if ($modo === 'todo' || $modo === 'fabrica') {
        array_push($tablas, 'clientes', 'lista_productos', 'listas', 'kit_componentes', 'precios_volumen', 'productos', 'categorias', 'proveedores');
    }
    if ($modo === 'fabrica') {
        array_push($tablas, 'usuarios', 'ajustes', 'auditoria');   // en los otros modos la bitácora se conserva
    }
    return $tablas;
}

/**
 * Borra según el modo y reinicia los folios. Devuelve [tabla => registros borrados].
 * Hacer un respaldo ANTES es responsabilidad de quien la llama.
 */
function limpiar_base(string $modo): array
{
    if (!isset(MODOS_LIMPIEZA[$modo])) {
        throw new InvalidArgumentException('Modo de limpieza no válido: ' . $modo);
    }
    $pdo = db();
    $borrados = [];
    transaccion_iniciar();
    try {
        foreach (tablas_limpieza($modo) as $tabla) {
            $borrados[$tabla] = $pdo->exec("DELETE FROM $tabla");
            $pdo->exec('DELETE FROM sqlite_sequence WHERE name = ' . $pdo->quote($tabla));
        }
        if ($modo === 'ventas') {
            // El historial de inventario vuelve a empezar desde la existencia de hoy.
            $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, fecha, tipo, cantidad, existencia_final, costo, nota)
                           SELECT id, :fecha, 'inicial', existencia, existencia, costo, 'Existencia al limpiar el historial'
                           FROM productos WHERE controlar_stock = 1")->execute([':fecha' => date('Y-m-d H:i:s')]);
        }
        if ($modo !== 'fabrica') {
            auditar('base_limpiada', "Limpieza de la base, modo «{$modo}»: " . implode(', ', array_map(
                fn($t, $n) => "$t ($n)", array_keys($borrados), $borrados)));
        }
        transaccion_confirmar();
    } catch (Throwable $e) {
        transaccion_revertir();
        throw $e;
    }
    return $borrados;
}

/** Carga los productos de ejemplo del giro (para practicar) con su existencia inicial. */
function cargar_productos_ejemplo(string $giro = GIRO_PREDETERMINADO): void
{
    $pdo = db();
    $alta = $pdo->prepare("INSERT INTO productos (nombre, codigo_barras, precio, costo, existencia, stock_minimo, controlar_stock, permite_fraccion, tasa_iva)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, '16')");
    foreach (datos_giro($giro)['productos'] as $producto) {
        $alta->execute($producto);
    }
    $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, fecha, tipo, cantidad, existencia_final, costo, nota)
                   SELECT id, :fecha, 'inicial', existencia, existencia, costo, 'Producto de ejemplo'
                   FROM productos WHERE controlar_stock = 1
                     AND id NOT IN (SELECT producto_id FROM movimientos_inventario)")->execute([':fecha' => date('Y-m-d H:i:s')]);
}
