<?php
/**
 * Acciones de caja (POST):
 *   accion=abrir       fondo                   → cualquier usuario (sin caja abierta no se vende)
 *   accion=movimiento  tipo, monto, concepto   → permiso "caja"
 *   accion=cerrar      contado, notas          → permiso "caja"
 */
require_once __DIR__ . '/../includes/app.php';

requerir_post_csrf();

$accion = (string) ($_POST['accion'] ?? '');
$volver = ($_POST['volver'] ?? '') === 'pos' ? 'ventas/index.php' : 'caja/index.php';

try {
    if ($accion === 'abrir') {
        $fondo = normalizar_decimal(($_POST['fondo'] ?? '') === '' ? '0' : $_POST['fondo'], 2, 8);
        if ($fondo === null) {
            throw new RuntimeException('Escribe el fondo inicial (el cambio con el que empieza la caja), o 0.');
        }
        abrir_turno(a_centavos($fondo));
        auditar('caja_abierta', 'Abrió la caja con ' . dinero($fondo) . ' de fondo');
        flash('exito', 'Caja abierta con ' . dinero($fondo) . ' de fondo.');
        redirigir($volver);
    }

    requerir_permiso('caja');

    if ($accion === 'movimiento') {
        $tipo = in_array($_POST['tipo'] ?? '', ['entrada', 'salida'], true) ? $_POST['tipo'] : null;
        $monto = normalizar_decimal($_POST['monto'] ?? '', 2, 8);
        $concepto = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['concepto'] ?? '')) ?? '');
        if (!$tipo || $monto === null || a_centavos($monto) <= 0) {
            throw new RuntimeException('Escribe un monto mayor que cero.');
        }
        if ($concepto === '' || mb_strlen($concepto) > 120) {
            throw new RuntimeException('Escribe para qué es el dinero (máximo 120 caracteres).');
        }
        registrar_movimiento_caja($tipo, a_centavos($monto), $concepto);
        auditar('caja_movimiento', ($tipo === 'entrada' ? 'Entrada' : 'Salida') . ' de ' . dinero($monto) . ': ' . $concepto);
        flash('exito', ($tipo === 'entrada' ? 'Entrada' : 'Salida') . ' de ' . dinero($monto) . ' registrada.');
        if (impresora_activa() && $tipo === 'salida') {
            try {
                enviar_a_impresora(escpos_cajon(ajuste('cajon_pin')));
            } catch (RuntimeException $ex) {
                flash('aviso', 'No se pudo abrir el cajón: ' . $ex->getMessage());
            }
        }
        redirigir('caja/index.php');
    }

    if ($accion === 'cerrar') {
        $turno = turno_abierto();
        if (!$turno) {
            throw new RuntimeException('La caja ya estaba cerrada.');
        }
        $contado = normalizar_decimal($_POST['contado'] ?? '', 2, 8);
        if ($contado === null) {
            throw new RuntimeException('Cuenta el efectivo del cajón y escribe cuánto hay.');
        }
        $notas = mb_substr(trim((string) ($_POST['notas'] ?? '')), 0, 255);
        cerrar_turno($turno, a_centavos($contado), $notas);
        $cerrado = turno_por_id((int) $turno['id']);
        auditar('caja_cerrada', 'Corte del turno #' . $turno['id'] . ': debía haber ' . dinero($cerrado['efectivo_esperado'])
            . ', se contaron ' . dinero($cerrado['efectivo_contado']), 'turno', (int) $turno['id']);
        flash('exito', 'Corte realizado. ' . diferencia_texto(a_centavos($cerrado['efectivo_contado']) - a_centavos($cerrado['efectivo_esperado'])) . '.');
        if (impresora_activa()) {
            try {
                enviar_a_impresora(escpos_corte($cerrado, ajustes()));
            } catch (RuntimeException $ex) {
                flash('aviso', 'El corte se guardó, pero no se pudo imprimir: ' . $ex->getMessage());
            }
        }
        redirigir('caja/corte.php?id=' . (int) $turno['id']);
    }

    throw new RuntimeException('Acción no válida.');
} catch (RuntimeException $ex) {
    if ($ex instanceof PDOException) {
        throw $ex;
    }
    flash('error', $ex->getMessage());
    redirigir($volver);
}
