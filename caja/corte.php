<?php
/**
 * Detalle de un corte de caja ya cerrado (imprimible).
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('caja');

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$turno = $id ? turno_por_id($id) : null;
if (!$turno || $turno['cerrado_en'] === null) {
    flash('error', 'Ese corte no existe o la caja sigue abierta.');
    redirigir('caja/index.php');
}
$r = resumen_turno($turno);
$movs = movimientos_turno((int) $turno['id']);
$contado = a_centavos($turno['efectivo_contado']);
$esperado = a_centavos($turno['efectivo_esperado']);
$dif = $contado - $esperado;
$d = fn(int $centavos) => dinero($centavos / 100);

$titulo  = 'Corte #' . (int) $turno['id'];
$seccion = 'caja';
require __DIR__ . '/../includes/encabezado.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-imprimir">
    <nav aria-label="Ruta"><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= e(url('caja/index.php')) ?>">Caja</a></li>
        <li class="breadcrumb-item active" aria-current="page">Corte #<?= (int) $turno['id'] ?></li>
    </ol></nav>
    <div class="d-flex gap-2">
        <?php if (impresora_activa()): ?>
            <button type="button" class="btn btn-light border" id="btnImprimirCorte"><i class="bi bi-printer me-1"></i>Imprimir ticket</button>
        <?php endif; ?>
        <button type="button" class="btn btn-light border" onclick="window.print()"><i class="bi bi-file-earmark-text me-1"></i>Imprimir en hoja</button>
        <a href="<?= e(url('caja/index.php')) ?>" class="btn btn-primary"><i class="bi bi-cash-coin me-1"></i>Ir a caja</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body p-4">
                <h2 class="h5 fw-bold mb-1">Corte de caja · turno #<?= (int) $turno['id'] ?></h2>
                <div class="text-body-secondary small mb-3">
                    Abrió <?= e(fecha_fmt($turno['abierto_en'])) ?><?= $turno['abierto_por'] ? ' (' . e(nombre_usuario((int) $turno['abierto_por'])) . ')' : '' ?> ·
                    Cerró <?= e(fecha_fmt($turno['cerrado_en'])) ?><?= $turno['cerrado_por'] ? ' (' . e(nombre_usuario((int) $turno['cerrado_por'])) . ')' : '' ?>
                </div>
                <div class="text-center rounded-4 py-3 mb-3 <?= $dif === 0 ? 'bg-success-subtle' : ($dif > 0 ? 'bg-info-subtle' : 'bg-danger-subtle') ?>">
                    <div class="small fw-semibold text-uppercase">Resultado</div>
                    <div class="fs-3 fw-bold"><?= e(diferencia_texto($dif)) ?></div>
                    <div class="small">Debía haber <?= $d($esperado) ?> · se contaron <?= $d($contado) ?></div>
                </div>
                <table class="table table-sm">
                    <tr><td>Cambio con el que abriste</td><td class="text-end num"><?= $d($r['fondo']) ?></td></tr>
                    <tr><td>+ Ventas en efectivo</td><td class="text-end num"><?= $d($r['ventas']['efectivo']['total']) ?></td></tr>
                    <tr><td>+ Abonos en efectivo</td><td class="text-end num"><?= $d($r['abonos']['efectivo']) ?></td></tr>
                    <tr><td>+ Entradas</td><td class="text-end num"><?= $d($r['entradas']) ?></td></tr>
                    <tr><td>− Salidas</td><td class="text-end num"><?= $d($r['salidas']) ?></td></tr>
                    <tr class="fw-bold"><td>= Debía haber</td><td class="text-end num"><?= $d($esperado) ?></td></tr>
                </table>
                <?php if ($turno['notas']): ?>
                    <div class="alert alert-light border small mb-0"><strong>Notas:</strong> <?= e($turno['notas']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card mb-4">
            <div class="card-header"><h2><i class="bi bi-receipt me-2 text-primary"></i>Ventas del turno (<?= (int) $r['operaciones'] ?>)</h2></div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <?php foreach (METODOS_PAGO as $clave => $m): ?>
                        <tr><td><?= e($m['etiqueta']) ?> (<?= (int) $r['ventas'][$clave]['operaciones'] ?>)</td><td class="text-end num"><?= $d($r['ventas'][$clave]['total']) ?></td></tr>
                    <?php endforeach; ?>
                    <tr class="fw-bold"><td>Total vendido</td><td class="text-end num"><?= $d($r['vendido']) ?></td></tr>
                    <tr><td>Descuentos dados</td><td class="text-end num"><?= $d($r['descuentos']) ?></td></tr>
                    <tr><td>Ventas canceladas</td><td class="text-end"><?= (int) $r['canceladas'] ?></td></tr>
                    <?php foreach (METODOS_REEMBOLSO as $clave => $etiqueta): if ($r['devoluciones'][$clave] > 0): ?>
                        <tr><td>Devoluciones: <?= e(mb_strtolower(explode(' (', $etiqueta)[0])) ?></td><td class="text-end num text-danger">−<?= $d($r['devoluciones'][$clave]) ?></td></tr>
                    <?php endif; endforeach; ?>
                    <?php foreach (METODOS_ABONO as $clave => $etiqueta): if ($r['abonos'][$clave] > 0): ?>
                        <tr><td>Abonos en <?= e(mb_strtolower($etiqueta)) ?></td><td class="text-end num"><?= $d($r['abonos'][$clave]) ?></td></tr>
                    <?php endif; endforeach; ?>
                </table>
                <a class="small no-imprimir" href="<?= e(url('ventas/historial.php?turno=' . (int) $turno['id'])) ?>">Ver las ventas de este turno</a>
            </div>
        </div>
        <?php if ($movs): ?>
            <div class="card">
                <div class="card-header"><h2><i class="bi bi-arrow-down-up me-2 text-primary"></i>Entradas y salidas</h2></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <?php foreach ($movs as $mv): ?>
                            <tr>
                                <td class="num"><?= e(date('h:i a', strtotime($mv['fecha']))) ?></td>
                                <td><?= e($mv['concepto']) ?></td>
                                <td class="text-end num <?= $mv['tipo'] === 'entrada' ? 'text-success' : 'text-danger' ?>"><?= $mv['tipo'] === 'entrada' ? '+' : '−' ?><?= dinero($mv['monto']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (impresora_activa()): ?>
<script>
document.getElementById('btnImprimirCorte').addEventListener('click', async function () {
    this.disabled = true;
    try {
        await TW.json('ventas/impresora.php', { method: 'POST', body: { accion: 'corte', id: <?= (int) $turno['id'] ?> } });
        TW.toast('success', 'Corte enviado a la impresora.');
    } catch (e) { TW.alerta('error', 'No se pudo imprimir', e.message); }
    this.disabled = false;
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/pie.php'; ?>
