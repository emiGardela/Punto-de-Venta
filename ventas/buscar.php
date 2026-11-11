<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Búsqueda de productos para el punto de venta (responde JSON).
 *
 *   ?codigo=7501...  → producto activo con ese código de barras (o código interno) exacto
 *   ?q=texto         → hasta 20 productos activos por nombre, código, código interno, marca o categoría
 *   ?ids=1,2,3       → datos actuales de esos productos (refresca el carrito)
 */
require_once __DIR__ . '/../includes/app.php';

/** Productos en el formato que usa el carrito, con sus precios por cantidad. */
function productos_json(array $filas): array
{
    $ids = array_map(fn($p) => (int) $p['id'], $filas);
    $escalones = escalones_de($ids);
    $disponible = disponibilidad($ids);   // paquetes y kits: según lo que contienen
    $verCosto = permiso('productos') || permiso('inventario');   // el costo solo lo ve quien maneja productos
    return array_map(fn($p) => [
        'id'         => (int) $p['id'],
        'nombre'     => $p['nombre'],
        'codigo'     => $p['codigo_barras'],
        'interno'    => $p['codigo_interno'],
        'marca'      => $p['marca'],
        'unidad'     => UNIDADES[$p['unidad']][1] ?? $p['unidad'],
        'precio'     => (float) $p['precio'],
        // [[desde, precio c/u], ...] en unidades y pesos
        'precios'    => array_map(fn($e) => [$e[0] / 1000, $e[1] / 100], $escalones[(int) $p['id']] ?? []),
        'existencia' => $disponible[(int) $p['id']]['existencia'] ?? (float) $p['existencia'],
        'controla'   => $disponible[(int) $p['id']]['controla'] ?? (bool) $p['controlar_stock'],
        'tipo'       => $p['tipo'],
        'fraccion'   => (bool) $p['permite_fraccion'],
        'activo'     => (bool) $p['activo'],
        'costo'      => $verCosto && $p['costo'] !== null ? (float) $p['costo'] : null,
    ], $filas);
}

$campos = 'p.id, p.nombre, p.codigo_barras, p.codigo_interno, p.marca, p.unidad, p.precio, p.costo, p.existencia, p.tipo,
           p.controlar_stock, p.permite_fraccion, p.activo';

// 1) Código exacto (lector o tecleado + Enter): de barras o interno.
if (isset($_GET['codigo'])) {
    $codigo = trim((string) $_GET['codigo']);
    if ($codigo === '' || mb_strlen($codigo) > 64) {
        responder_json(['ok' => true, 'producto' => null]);
    }
    $st = db()->prepare("SELECT $campos FROM productos p
                         WHERE (p.codigo_barras = :codigo OR p.codigo_interno = :interno) AND p.activo = 1
                         ORDER BY p.codigo_barras = :orden DESC LIMIT 1");
    $st->execute([':codigo' => $codigo, ':interno' => $codigo, ':orden' => $codigo]);
    $p = $st->fetch();
    responder_json(['ok' => true, 'producto' => $p ? productos_json([$p])[0] : null]);
}

// 2) Refrescar productos que ya están en el carrito.
if (isset($_GET['ids'])) {
    $ids = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string) $_GET['ids'])),
        fn($n) => $n > 0
    )));
    $ids = array_slice($ids, 0, 200);
    if (!$ids) {
        responder_json(['ok' => true, 'productos' => []]);
    }
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT $campos FROM productos p WHERE p.id IN ($marcas)");
    $st->execute($ids);
    responder_json(['ok' => true, 'productos' => productos_json($st->fetchAll())]);
}

// 3) Búsqueda por texto.
$q = trim((string) ($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q) > 100) {
    responder_json(['ok' => true, 'productos' => []]);
}

$st = db()->prepare("SELECT $campos FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id
    WHERE p.activo = 1 AND (sin_acentos(p.nombre) LIKE sin_acentos(:contiene) ESCAPE '\\'
                            OR p.codigo_barras LIKE :codigo_parcial ESCAPE '\\'
                            OR p.codigo_interno LIKE :interno_parcial ESCAPE '\\'
                            OR sin_acentos(p.marca) LIKE sin_acentos(:marca) ESCAPE '\\'
                            OR sin_acentos(c.nombre) LIKE sin_acentos(:categoria) ESCAPE '\\')
    ORDER BY (p.codigo_barras = :codigo_exacto OR p.codigo_interno = :interno_exacto) DESC,
             (sin_acentos(p.nombre) LIKE sin_acentos(:empieza) ESCAPE '\\') DESC,
             sin_acentos(p.nombre) ASC
    LIMIT 20");
$st->execute([
    ':contiene'        => like($q),
    ':codigo_parcial'  => like($q),
    ':interno_parcial' => like($q),
    ':marca'           => like($q),
    ':categoria'       => like($q),
    ':codigo_exacto'   => $q,
    ':interno_exacto'  => $q,
    ':empieza'         => addcslashes($q, '\\%_') . '%',
]);
responder_json(['ok' => true, 'productos' => productos_json($st->fetchAll())]);
