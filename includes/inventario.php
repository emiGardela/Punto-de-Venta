<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * TiendaWeb - Historial de inventario (kardex).
 *
 * Cada cambio de existencia queda registrado en movimientos_inventario con
 * quién lo hizo y con cuánto quedó el producto. Las funciones de aquí se
 * llaman dentro de la transacción de quien hace el cambio.
 */

declare(strict_types=1);

const TIPOS_MOVIMIENTO_INVENTARIO = [
    'inicial'     => ['Existencia inicial', 'bi-flag',              'secondary'],
    'entrada'     => ['Entrada de mercancía', 'bi-box-arrow-in-down', 'success'],
    'ajuste'      => ['Ajuste',             'bi-sliders',           'warning'],
    'venta'       => ['Venta',              'bi-cart',              'primary'],
    'cancelacion' => ['Cancelación o devolución', 'bi-arrow-counterclockwise', 'info'],
    'importacion' => ['Importación',        'bi-file-earmark-arrow-up', 'secondary'],
];

/**
 * Anota un movimiento que YA se aplicó a productos.existencia
 * ($milesimas: positivo si entró, negativo si salió).
 */
function anotar_movimiento_inventario(int $productoId, string $tipo, int $milesimas, ?string $costo = null,
                                      ?string $nota = null, ?int $ventaId = null): void
{
    $st = db()->prepare('SELECT existencia FROM productos WHERE id = :id');
    $st->execute([':id' => $productoId]);
    $existencia = $st->fetchColumn();
    db()->prepare('INSERT INTO movimientos_inventario
                       (producto_id, fecha, tipo, cantidad, existencia_final, costo, nota, usuario_id, venta_id)
                   VALUES (:producto, :fecha, :tipo, :cantidad, :final, :costo, :nota, :usuario, :venta)')
        ->execute([
            ':producto' => $productoId, ':fecha' => date('Y-m-d H:i:s'), ':tipo' => $tipo,
            ':cantidad' => number_format($milesimas / 1000, 3, '.', ''), ':final' => $existencia,
            ':costo' => $costo, ':nota' => $nota, ':usuario' => usuario_id(), ':venta' => $ventaId,
        ]);
}

/** Suma (o resta) existencia y lo anota. Lanza RuntimeException si quedaría negativa. */
function mover_existencia(int $productoId, string $tipo, int $milesimas, ?string $costo = null, ?string $nota = null): void
{
    $st = db()->prepare('UPDATE productos SET existencia = ROUND(existencia + :delta, 3)
                         WHERE id = :id AND controlar_stock = 1 AND ROUND(existencia + :delta2, 3) >= 0');
    $delta = number_format($milesimas / 1000, 3, '.', '');
    $st->execute([':delta' => $delta, ':delta2' => $delta, ':id' => $productoId]);
    if ($st->rowCount() !== 1) {
        throw new RuntimeException('No se pudo cambiar la existencia del producto #' . $productoId
            . ' (no controla inventario o quedaría en negativo).');
    }
    anotar_movimiento_inventario($productoId, $tipo, $milesimas, $costo, $nota);
}

/* =====================================================================
 *  Paquetes y kits
 *
 *  Un paquete (caja de 12 lápices) no tiene existencia propia: es N
 *  unidades de su producto base. Un kit (kit escolar) son varios
 *  productos. Al vender cualquiera de ellos se descuenta la existencia
 *  de los productos reales; así vender la caja o la pieza suelta nunca
 *  descuadra el inventario.
 * ===================================================================== */

/**
 * Carga los productos indicados y todo lo que se necesita para resolverlos
 * (productos base de paquetes y componentes de kits).
 * Devuelve ['productos' => [id => fila], 'componentes' => [kitId => [[productoId, milésimas por kit], ...]]].
 */
function cargar_composicion(array $ids): array
{
    $productos = [];
    $componentes = [];
    $pendientes = array_values(array_unique(array_map('intval', $ids)));
    while ($pendientes) {
        $marcas = implode(',', array_fill(0, count($pendientes), '?'));
        $st = db()->prepare("SELECT * FROM productos WHERE id IN ($marcas)");
        $st->execute($pendientes);
        $nuevos = [];
        foreach ($st->fetchAll() as $p) {
            $productos[(int) $p['id']] = $p;
            if ($p['tipo'] === 'paquete' && $p['producto_base_id'] !== null) {
                $nuevos[] = (int) $p['producto_base_id'];
            }
        }
        $kits = array_keys(array_filter($productos, fn($p) => $p['tipo'] === 'kit' && !isset($componentes[(int) $p['id']])));
        if ($kits) {
            $marcas = implode(',', array_fill(0, count($kits), '?'));
            $st = db()->prepare("SELECT kit_id, producto_id, cantidad FROM kit_componentes WHERE kit_id IN ($marcas) ORDER BY id");
            $st->execute($kits);
            foreach ($kits as $k) {
                $componentes[$k] = [];
            }
            foreach ($st->fetchAll() as $c) {
                $componentes[(int) $c['kit_id']][] = [(int) $c['producto_id'], a_milesimas($c['cantidad'])];
                $nuevos[] = (int) $c['producto_id'];
            }
        }
        $pendientes = array_values(array_diff(array_unique($nuevos), array_keys($productos)));
    }
    return ['productos' => $productos, 'componentes' => $componentes];
}

/**
 * Existencia que consume vender $milesimas de un producto: [producto real => milésimas].
 * Un servicio no consume nada.
 */
function consumo_producto(int $id, int $milesimas, array $comp, int $nivel = 0): array
{
    $p = $comp['productos'][$id] ?? null;
    if ($p === null || $nivel > 3) {
        return [];
    }
    if ($p['tipo'] === 'paquete' && $p['producto_base_id'] !== null) {
        return consumo_producto((int) $p['producto_base_id'], (int) round($milesimas * a_milesimas($p['factor_base']) / 1000), $comp, $nivel + 1);
    }
    if ($p['tipo'] === 'kit') {
        $total = [];
        foreach ($comp['componentes'][$id] ?? [] as [$cid, $porKit]) {
            foreach (consumo_producto($cid, (int) round($milesimas * $porKit / 1000), $comp, $nivel + 1) as $real => $m) {
                $total[$real] = ($total[$real] ?? 0) + $m;
            }
        }
        return $total;
    }
    return (int) $p['controlar_stock'] ? [$id => $milesimas] : [];
}

/**
 * Cuántos se pueden vender de cada producto: [id => ['controla' => bool, 'existencia' => float]].
 * Paquetes y kits se calculan a partir de sus productos reales.
 */
function disponibilidad(array $ids, ?array $comp = null): array
{
    $comp ??= cargar_composicion($ids);
    $resultado = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        $p = $comp['productos'][$id] ?? null;
        if ($p === null) {
            continue;
        }
        $consumo = consumo_producto($id, 1000, $comp);   // lo que consume UNA unidad
        if (!$consumo) {
            $resultado[$id] = ['controla' => (bool) (int) $p['controlar_stock'], 'existencia' => (float) $p['existencia']];
            continue;
        }
        $maximo = PHP_INT_MAX;
        foreach ($consumo as $real => $porUnidad) {
            $hay = a_milesimas($comp['productos'][$real]['existencia'] ?? 0);
            $maximo = min($maximo, $porUnidad > 0 ? intdiv($hay * 1000, $porUnidad) : PHP_INT_MAX);
        }
        if (!(int) $p['permite_fraccion']) {
            $maximo = intdiv($maximo, 1000) * 1000;
        }
        $resultado[$id] = ['controla' => true, 'existencia' => $maximo / 1000];
    }
    return $resultado;
}
