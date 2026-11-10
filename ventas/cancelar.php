<?php
/**
 * Cancela una venta completada: registra el motivo y devuelve las existencias.
 * La venta no se borra. Una venta ya cancelada no puede cancelarse de nuevo,
 * por lo que las existencias nunca se restituyen dos veces.
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('cancelar');

requerir_post_csrf();

$id     = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$motivo = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['motivo'] ?? '')) ?? '');

if (!$id) {
    flash('error', 'Venta no válida.');
    redirigir('ventas/historial.php');
}
if (mb_strlen($motivo) < 5 || mb_strlen($motivo) > 255) {
    flash('error', 'Escribe un motivo de cancelación de entre 5 y 255 caracteres.');
    redirigir('ventas/detalle.php?id=' . $id);
}

$pdo = db();
try {
    transaccion_iniciar();

    // La transacción (BEGIN IMMEDIATE) evita que dos cancelaciones simultáneas se crucen.
    $st = $pdo->prepare('SELECT id, estado, metodo_pago, total, turno_id FROM ventas WHERE id = :id');
    $st->execute([':id' => $id]);
    $venta = $st->fetch();

    if (!$venta) {
        transaccion_revertir();
        flash('error', 'La venta no existe.');
        redirigir('ventas/historial.php');
    }
    if ($venta['estado'] !== 'completada') {
        transaccion_revertir();
        flash('aviso', 'Esta venta ya estaba cancelada; no se modificó nada.');
        redirigir('ventas/detalle.php?id=' . $id);
    }
    // Con devoluciones, cancelar repondría otra vez lo ya devuelto: lo que resta se devuelve con "Devolver productos".
    $st = $pdo->prepare('SELECT COUNT(*) FROM devoluciones WHERE venta_id = :id');
    $st->execute([':id' => $id]);
    if ((int) $st->fetchColumn() > 0) {
        transaccion_revertir();
        flash('error', 'Esta venta ya tiene devoluciones y no se puede cancelar completa. Usa "Devolver productos" para lo que resta.');
        redirigir('ventas/detalle.php?id=' . $id);
    }

    $st = $pdo->prepare("UPDATE ventas
                         SET estado = 'cancelada', motivo_cancelacion = :motivo, cancelada_en = :fecha, cancelada_por = :usuario
                         WHERE id = :id AND estado = 'completada'");
    $st->execute([':motivo' => $motivo, ':fecha' => date('Y-m-d H:i:s'), ':usuario' => usuario_id(), ':id' => $id]);
    if ($st->rowCount() !== 1) {
        throw new RuntimeException('No se pudo cancelar la venta. Intenta de nuevo.');
    }

    // Devuelve al inventario exactamente lo que se descontó: se lee del historial de inventario de la venta
    // (incluye los productos reales de paquetes y kits). Las ventas anteriores al historial usan su detalle.
    $st = $pdo->prepare("SELECT producto_id, -SUM(cantidad) AS cantidad FROM movimientos_inventario
                         WHERE venta_id = :id AND tipo = 'venta' GROUP BY producto_id");
    $st->execute([':id' => $id]);
    $lineas = $st->fetchAll();
    if (!$lineas) {
        $st = $pdo->prepare('SELECT producto_id, SUM(cantidad) AS cantidad
                             FROM detalle_ventas
                             WHERE venta_id = :id AND descuenta_stock = 1 AND producto_id IS NOT NULL
                             GROUP BY producto_id');
        $st->execute([':id' => $id]);
        $lineas = $st->fetchAll();
    }
    $restituir = $pdo->prepare('UPDATE productos SET existencia = ROUND(existencia + :cantidad, 3) WHERE id = :producto');
    foreach ($lineas as $linea) {
        $restituir->execute([':cantidad' => $linea['cantidad'], ':producto' => $linea['producto_id']]);
        anotar_movimiento_inventario((int) $linea['producto_id'], 'cancelacion', a_milesimas($linea['cantidad']),
            null, 'Cancelación de la venta ' . folio($id), $id);
    }

    // Si la venta (o su parte en efectivo) era de un turno ya cerrado, el dinero se devuelve del cajón actual.
    $aviso = '';
    $turno = turno_abierto();
    $efectivo = (float) (pagos_venta($id)['efectivo'] ?? 0);
    if ($efectivo > 0 && (int) $venta['turno_id'] !== (int) ($turno['id'] ?? 0)) {
        if ($turno) {
            registrar_movimiento_caja('salida', a_centavos($efectivo), 'Devolución de la venta ' . folio($id), $id);
            $aviso = ' Se registró la salida de ' . dinero($efectivo) . ' de la caja.';
        } else {
            $aviso = ' La caja está cerrada: si devolviste dinero, regístralo como salida al abrirla.';
        }
    }

    // Factura: si solo estaba pedida, se cancela la solicitud; si ya estaba hecha, hay que cancelarla en el SAT (motivo 03).
    $factura = factura_de_venta($id);
    if ($factura && $factura['estado'] === 'pendiente') {
        $pdo->prepare("UPDATE facturas SET estado = 'cancelada', cancelada_en = :f, notas = 'Se canceló la venta' WHERE id = :id")
            ->execute([':f' => date('Y-m-d H:i:s'), ':id' => $factura['id']]);
        $aviso .= ' También se canceló la solicitud de factura.';
    } elseif ($factura && $factura['estado'] === 'hecha') {
        $aviso .= ' OJO: esta venta tiene factura hecha; entra a "Factura" y cancélala con el motivo 03.';
    }

    auditar('venta_cancelada', 'Venta ' . folio($id) . ' por ' . dinero($venta['total']) . '. Motivo: ' . $motivo, 'venta', $id);
    transaccion_confirmar();
} catch (Throwable $ex) {
    if (en_transaccion()) {
        transaccion_revertir();
    }
    throw $ex;
}

flash('exito', 'La venta ' . folio($id) . ' fue cancelada y las existencias se restituyeron.' . $aviso);
redirigir('ventas/detalle.php?id=' . $id);
