<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * TiendaWeb - Devoluciones parciales (una o varias piezas de una venta).
 *
 * - Se reembolsa lo que realmente se cobró por esas piezas (precio con su
 *   parte del descuento), nunca más.
 * - Si el artículo regresa al anaquel se suma a la existencia (en paquetes y
 *   kits, a los productos que contienen); si viene dañado, no.
 * - Reembolso en efectivo: sale de la caja abierta. A crédito: baja lo que
 *   debe el cliente. Tarjeta o transferencia: solo se registra.
 */

declare(strict_types=1);

const METODOS_REEMBOLSO = [
    'efectivo'      => 'Efectivo (sale de la caja)',
    'tarjeta'       => 'Tarjeta (devuélvelo en la terminal)',
    'transferencia' => 'Transferencia',
    'credito'       => 'Descontar de lo que debe el cliente',
];

/** Milésimas ya devueltas de cada renglón de una venta: [detalle_id => milésimas]. */
function devuelto_por_renglon(int $ventaId): array
{
    $st = db()->prepare('SELECT dp.detalle_id, SUM(dp.cantidad) AS cantidad, SUM(dp.importe) AS importe
                         FROM devolucion_productos dp JOIN devoluciones d ON d.id = dp.devolucion_id
                         WHERE d.venta_id = :id GROUP BY dp.detalle_id');
    $st->execute([':id' => $ventaId]);
    $r = [];
    foreach ($st->fetchAll() as $f) {
        $r[(int) $f['detalle_id']] = ['milesimas' => a_milesimas($f['cantidad']), 'centavos' => a_centavos($f['importe'])];
    }
    return $r;
}

/**
 * Registra una devolución. $renglones: [detalle_id => ['milesimas' => int, 'regresa' => bool]].
 * Devuelve el id de la devolución. Lanza RuntimeException con un mensaje para el usuario.
 * Debe llamarse dentro de una transacción.
 */
function registrar_devolucion(int $ventaId, array $renglones, string $reembolso, string $motivo): int
{
    $st = db()->prepare('SELECT * FROM ventas WHERE id = :id');
    $st->execute([':id' => $ventaId]);
    $venta = $st->fetch();
    if (!$venta || $venta['estado'] !== 'completada') {
        throw new RuntimeException('Solo se pueden hacer devoluciones de ventas completadas.');
    }
    if (!array_key_exists($reembolso, METODOS_REEMBOLSO)) {
        throw new RuntimeException('Elige cómo se le regresa el dinero al cliente.');
    }
    if ($reembolso === 'credito' && !$venta['cliente_id']) {
        throw new RuntimeException('Para descontarlo del crédito, la venta debe tener cliente.');
    }
    $turno = turno_abierto();
    if ($reembolso === 'efectivo' && !$turno) {
        throw new RuntimeException('La caja está cerrada: ábrela para devolver efectivo.');
    }

    $st = db()->prepare('SELECT * FROM detalle_ventas WHERE venta_id = :id');
    $st->execute([':id' => $ventaId]);
    $detalle = array_column($st->fetchAll(), null, 'id');
    $devuelto = devuelto_por_renglon($ventaId);

    $lineas = [];
    $total = 0;
    foreach ($renglones as $detalleId => $r) {
        $d = $detalle[$detalleId] ?? null;
        $mil = (int) $r['milesimas'];
        if (!$d || $mil <= 0) {
            continue;
        }
        $vendido = a_milesimas($d['cantidad']);
        $yaDevuelto = $devuelto[$detalleId]['milesimas'] ?? 0;
        if ($mil > $vendido - $yaDevuelto) {
            throw new RuntimeException("De «{$d['nombre_producto']}» solo quedan " . cantidad_fmt(($vendido - $yaDevuelto) / 1000) . ' por devolver.');
        }
        // Lo cobrado por el renglón (con su parte del descuento), proporcional a lo que se devuelve.
        $neto = a_centavos($d['subtotal']) - a_centavos($d['descuento']);
        $importe = $mil === $vendido - $yaDevuelto
            ? $neto - ($devuelto[$detalleId]['centavos'] ?? 0)      // lo último: lo que falte, sin errores de redondeo
            : (int) round($neto * $mil / $vendido);
        $lineas[] = ['detalle' => $d, 'milesimas' => $mil, 'importe' => max(0, $importe), 'regresa' => !empty($r['regresa'])];
        $total += max(0, $importe);
    }
    if (!$lineas) {
        throw new RuntimeException('Escribe cuántas piezas devuelve el cliente.');
    }

    db()->prepare('INSERT INTO devoluciones (venta_id, fecha, total, reembolso, motivo, turno_id, usuario_id)
                   VALUES (:venta, :fecha, :total, :reembolso, :motivo, :turno, :usuario)')
        ->execute([':venta' => $ventaId, ':fecha' => date('Y-m-d H:i:s'), ':total' => centavos_a_decimal($total), ':reembolso' => $reembolso,
                   ':motivo' => $motivo, ':turno' => $turno['id'] ?? null, ':usuario' => usuario_id()]);
    $devId = (int) db()->lastInsertId();

    $ins = db()->prepare('INSERT INTO devolucion_productos (devolucion_id, detalle_id, cantidad, importe, costo_unitario, regresa_inventario)
                          VALUES (:dev, :det, :cant, :imp, :costo, :regresa)');
    $restituir = db()->prepare('UPDATE productos SET existencia = ROUND(existencia + :cantidad, 3) WHERE id = :id AND controlar_stock = 1');
    $productoIds = array_filter(array_map(fn($l) => $l['detalle']['producto_id'] !== null ? (int) $l['detalle']['producto_id'] : 0, $lineas));
    $comp = $productoIds ? cargar_composicion($productoIds) : ['productos' => [], 'componentes' => []];
    foreach ($lineas as $l) {
        $d = $l['detalle'];
        $ins->execute([':dev' => $devId, ':det' => $d['id'], ':cant' => number_format($l['milesimas'] / 1000, 3, '.', ''),
                       ':imp' => centavos_a_decimal($l['importe']), ':costo' => $d['costo_unitario'], ':regresa' => $l['regresa'] ? 1 : 0]);
        if ($l['regresa'] && $d['producto_id'] !== null && $d['descuenta_stock']) {
            foreach (consumo_producto((int) $d['producto_id'], $l['milesimas'], $comp) as $real => $m) {
                $restituir->execute([':cantidad' => number_format($m / 1000, 3, '.', ''), ':id' => $real]);
                if ($restituir->rowCount() === 1) {
                    anotar_movimiento_inventario($real, 'cancelacion', $m, null, 'Devolución #' . $devId . ' de la venta ' . folio($ventaId), $ventaId);
                }
            }
        }
    }
    if ($reembolso === 'efectivo' && $total > 0) {
        registrar_movimiento_caja('salida', $total, 'Devolución #' . $devId . ' de la venta ' . folio($ventaId), $ventaId);
    }
    auditar('venta_devolucion', 'Devolución #' . $devId . ' de la venta ' . folio($ventaId) . ' por ' . dinero($total / 100)
        . ' (' . mb_strtolower(explode(' (', METODOS_REEMBOLSO[$reembolso])[0]) . '). Motivo: ' . $motivo, 'venta', $ventaId);
    return $devId;
}

/** Comprobante de devolución para la impresora de tickets. */
function escpos_devolucion(int $devId, array $aj): string
{
    $st = db()->prepare('SELECT * FROM devoluciones WHERE id = :id');
    $st->execute([':id' => $devId]);
    $dev = $st->fetch();
    $st = db()->prepare('SELECT dp.*, d.nombre_producto FROM devolucion_productos dp JOIN detalle_ventas d ON d.id = dp.detalle_id
                         WHERE dp.devolucion_id = :id ORDER BY dp.id');
    $st->execute([':id' => $devId]);
    $t = ticket_nuevo($aj);
    $t->comando(ESCPOS_CENTRO . ESCPOS_NEGRITA_SI)->linea('DEVOLUCIÓN')->comando(ESCPOS_NEGRITA_NO . ESCPOS_IZQUIERDA)->separador()
      ->columnas('Devolución:', '#' . $devId)
      ->columnas('De la venta:', folio($dev['venta_id']))
      ->columnas('Fecha:', fecha_fmt($dev['fecha']))
      ->separador();
    foreach ($st->fetchAll() as $l) {
        $t->linea($l['nombre_producto'] . ($l['regresa_inventario'] ? '' : ' (dañado)'))
          ->columnas('  ' . cantidad_fmt($l['cantidad']) . ' pza(s)', dinero($l['importe']));
    }
    $t->separador()
      ->comando(ESCPOS_DOBLE_ALTO . ESCPOS_NEGRITA_SI)->columnas('REEMBOLSO:', dinero($dev['total']))
      ->comando(ESCPOS_TAMANO_NORMAL . ESCPOS_NEGRITA_NO)
      ->linea('Forma: ' . explode(' (', METODOS_REEMBOLSO[$dev['reembolso']])[0])
      ->linea('Motivo: ' . $dev['motivo']);
    if ($dev['usuario_id']) {
        $t->columnas('Atendió:', nombre_usuario((int) $dev['usuario_id']));
    }
    return ticket_cerrar($t, $aj);
}
