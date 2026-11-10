<?php
/**
 * Acciones de crédito (POST):
 *   accion=abonar          cliente_id, monto, metodo_pago, nota   → permiso "credito"
 *   accion=cancelar_abono  abono_id, motivo                       → permisos "credito" y "cancelar"
 *
 * Un abono en efectivo entra a la caja abierta (cuenta en el corte); por eso
 * requiere la caja abierta.
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('credito');
requerir_post_csrf();

$accion = (string) ($_POST['accion'] ?? '');

if ($accion === 'abonar') {
    $clienteId = filter_var($_POST['cliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $volver = 'clientes/detalle.php?id=' . $clienteId;
    $cliente = cliente_por_id($clienteId);
    $monto = normalizar_decimal($_POST['monto'] ?? '', 2, 8);
    $metodo = (string) ($_POST['metodo_pago'] ?? '');
    $nota = mb_substr(trim((string) ($_POST['nota'] ?? '')), 0, 150);

    if (!$cliente) {
        flash('error', 'El cliente no existe.');
        redirigir('clientes/index.php');
    }
    if ($monto === null || a_centavos($monto) <= 0 || !array_key_exists($metodo, METODOS_ABONO)) {
        flash('error', 'Escribe cuánto abona y cómo paga.');
        redirigir($volver);
    }

    transaccion_iniciar();
    try {
        $saldo = saldo_cliente($clienteId);
        if (a_centavos($monto) > $saldo) {
            throw new RuntimeException('El abono (' . dinero($monto) . ') es mayor que lo que debe (' . dinero($saldo / 100) . ').');
        }
        $turno = turno_abierto();
        if ($metodo === 'efectivo' && !$turno) {
            throw new RuntimeException('La caja está cerrada. Ábrela para recibir abonos en efectivo.');
        }
        db()->prepare('INSERT INTO abonos (cliente_id, fecha, monto, metodo_pago, nota, turno_id, usuario_id)
                       VALUES (:cliente, :fecha, :monto, :metodo, :nota, :turno, :usuario)')
            ->execute([':cliente' => $clienteId, ':fecha' => date('Y-m-d H:i:s'), ':monto' => $monto, ':metodo' => $metodo,
                       ':nota' => $nota !== '' ? $nota : null, ':turno' => $turno['id'] ?? null, ':usuario' => usuario_id()]);
        $abonoId = (int) db()->lastInsertId();
        transaccion_confirmar();
    } catch (RuntimeException $ex) {
        transaccion_revertir();
        if ($ex instanceof PDOException) {
            throw $ex;
        }
        flash('error', $ex->getMessage());
        redirigir($volver);
    }

    $restante = saldo_cliente($clienteId);
    auditar('abono', 'Abono de ' . dinero($monto) . ' (' . METODOS_ABONO[$metodo] . ') de «' . $cliente['nombre'] . '»', 'cliente', $clienteId);
    flash('exito', 'Abono de ' . dinero($monto) . ' registrado. ' . ($restante > 0 ? 'Todavía debe ' . dinero($restante / 100) . '.' : '¡Quedó al corriente!'));
    if (impresora_activa()) {
        try {
            $bytes = ($metodo === 'efectivo' && ajuste('cajon_al_cobrar') !== 'nunca' ? escpos_cajon(ajuste('cajon_pin')) : '')
                . escpos_abono(abono_por_id($abonoId), ajustes());
            enviar_a_impresora($bytes);
        } catch (RuntimeException $ex) {
            flash('aviso', 'El abono se guardó, pero no se pudo imprimir: ' . $ex->getMessage());
        }
    }
    redirigir($volver);
}

if ($accion === 'cancelar_abono') {
    requerir_permiso('cancelar');
    $abonoId = filter_var($_POST['abono_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $motivo = trim((string) ($_POST['motivo'] ?? ''));
    $abono = abono_por_id($abonoId);
    if (!$abono) {
        flash('error', 'El abono no existe.');
        redirigir('clientes/index.php');
    }
    $volver = 'clientes/detalle.php?id=' . (int) $abono['cliente_id'];
    if (mb_strlen($motivo) < 5) {
        flash('error', 'Escribe el motivo de la cancelación.');
        redirigir($volver);
    }

    transaccion_iniciar();
    try {
        $st = db()->prepare("UPDATE abonos SET estado = 'cancelado', cancelado_en = :fecha, motivo_cancelacion = :motivo
                             WHERE id = :id AND estado = 'aplicado'");
        $st->execute([':fecha' => date('Y-m-d H:i:s'), ':motivo' => mb_substr($motivo, 0, 255), ':id' => $abonoId]);
        if ($st->rowCount() !== 1) {
            throw new RuntimeException('Ese abono ya estaba cancelado.');
        }
        // El efectivo de un turno ya cerrado sale del cajón actual.
        $aviso = '';
        $turno = turno_abierto();
        if ($abono['metodo_pago'] === 'efectivo' && (int) $abono['turno_id'] !== (int) ($turno['id'] ?? 0)) {
            if ($turno) {
                registrar_movimiento_caja('salida', a_centavos($abono['monto']), 'Devolución del abono #' . $abonoId . ' (' . $abono['cliente'] . ')');
                $aviso = ' Se registró la salida de ' . dinero($abono['monto']) . ' de la caja.';
            } else {
                $aviso = ' La caja está cerrada: si devolviste dinero, regístralo como salida al abrirla.';
            }
        }
        transaccion_confirmar();
    } catch (RuntimeException $ex) {
        transaccion_revertir();
        if ($ex instanceof PDOException) {
            throw $ex;
        }
        flash('error', $ex->getMessage());
        redirigir($volver);
    }
    auditar('abono_cancelado', 'Abono #' . $abonoId . ' de ' . dinero($abono['monto']) . ' de «' . $abono['cliente'] . '». Motivo: ' . $motivo,
        'cliente', (int) $abono['cliente_id']);
    flash('exito', 'Abono cancelado.' . $aviso);
    redirigir($volver);
}

flash('error', 'Acción no válida.');
redirigir('clientes/index.php');
