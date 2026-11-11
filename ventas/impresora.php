<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Impresora de tickets y cajón (recibe JSON por POST, responde JSON).
 *
 *   { "accion": "cobro",  "id": 12 }   después de cobrar: abre el cajón e imprime
 *                                       según lo elegido en Configuración
 *   { "accion": "ticket", "id": 12 }   imprime (o reimprime) el ticket de una venta
 *   { "accion": "cajon" }              solo abre el cajón
 *   { "accion": "corte",  "id": 3 }    corte del turno (parcial si sigue abierto)
 *   { "accion": "abono",  "id": 7 }    comprobante de un abono de cliente
 *   { "accion": "devolucion", "id": 2 } comprobante de una devolución
 *
 * Si la impresora falla, la venta ya está guardada: solo se avisa.
 */
require_once __DIR__ . '/../includes/app.php';

requerir_post_csrf();

$entrada = json_decode((string) file_get_contents('php://input'), true);
$accion  = is_array($entrada) ? (string) ($entrada['accion'] ?? '') : '';
if (!in_array($accion, ['cobro', 'ticket', 'cajon', 'corte', 'abono', 'devolucion'], true)) {
    responder_json(['ok' => false, 'mensaje' => 'Acción no válida.'], 400);
}
if (!impresora_activa()) {
    responder_json(['ok' => false, 'mensaje' => 'No hay impresora de tickets configurada. Ve a Configuración.'], 409);
}

$aj    = ajustes();
$bytes = '';
$hecho = [];

$id = filter_var($entrada['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

if ($accion === 'cajon') {
    $bytes = escpos_cajon($aj['cajon_pin']);
    $hecho[] = 'cajon';
    auditar('cajon_abierto', 'Abrió el cajón de dinero sin venta');
} elseif ($accion === 'corte') {
    requerir_permiso('caja');
    $turno = turno_por_id($id);
    if (!$turno) {
        responder_json(['ok' => false, 'mensaje' => 'El turno no existe.'], 404);
    }
    $bytes = escpos_corte($turno, $aj);
    $hecho[] = 'corte';
} elseif ($accion === 'abono') {
    requerir_permiso('credito');
    $abono = abono_por_id($id);
    if (!$abono) {
        responder_json(['ok' => false, 'mensaje' => 'El abono no existe.'], 404);
    }
    $bytes = escpos_abono($abono, $aj);
    $hecho[] = 'abono';
} elseif ($accion === 'devolucion') {
    requerir_permiso('cancelar');
    $st = db()->prepare('SELECT COUNT(*) FROM devoluciones WHERE id = :id');
    $st->execute([':id' => $id]);
    if (!$st->fetchColumn()) {
        responder_json(['ok' => false, 'mensaje' => 'La devolución no existe.'], 404);
    }
    $bytes = escpos_devolucion($id, $aj);
    $hecho[] = 'devolucion';
} else {
    $st = db()->prepare('SELECT * FROM ventas WHERE id = :id');
    $st->execute([':id' => $id]);
    $venta = $st->fetch();
    if (!$venta) {
        responder_json(['ok' => false, 'mensaje' => 'La venta no existe.'], 404);
    }

    $abrirCajon = $accion === 'cobro' && $venta['estado'] === 'completada' && (
        $aj['cajon_al_cobrar'] === 'siempre'
        || ($aj['cajon_al_cobrar'] === 'efectivo' && isset(pagos_venta((int) $venta['id'])['efectivo']))
    );
    $imprimir = $accion === 'ticket' || $aj['imprimir_al_cobrar'] === 'siempre';
    if ($accion === 'ticket' && strtotime($venta['fecha']) < time() - 120) {
        auditar('ticket_reimpreso', 'Reimprimió el ticket de la venta ' . folio($venta['id']), 'venta', (int) $venta['id']);
    }

    if ($abrirCajon) {
        $bytes .= escpos_cajon($aj['cajon_pin']);
        $hecho[] = 'cajon';
    }
    if ($imprimir) {
        $st = db()->prepare('SELECT * FROM detalle_ventas WHERE venta_id = :id ORDER BY id');
        $st->execute([':id' => $venta['id']]);
        $bytes .= escpos_ticket_venta($venta, $st->fetchAll(), $aj);
        $hecho[] = 'ticket';
    }
}

if ($bytes !== '') {
    try {
        enviar_a_impresora($bytes, $aj);
    } catch (RuntimeException $ex) {
        error_log('TiendaWeb: impresora: ' . $ex->getMessage());
        responder_json(['ok' => false, 'mensaje' => $ex->getMessage()], 503);
    }
}

responder_json(['ok' => true, 'hecho' => $hecho]);
