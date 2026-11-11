<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Estado de cuenta de un cliente: compras a crédito, abonos y saldo.
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('credito');

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$cliente = $id ? cliente_por_id($id) : null;
if (!$cliente) {
    flash('error', 'El cliente no existe.');
    redirigir('clientes/index.php');
}
$saldo = saldo_cliente($id);

// Movimientos de la cuenta (cargos = ventas a crédito; abonos = pagos y devoluciones descontadas del crédito),
// del más antiguo al más nuevo.
// El cargo de una venta es solo su parte a crédito (en un pago dividido puede ser menor que el total).
$st = db()->prepare("SELECT 'venta' AS tipo, v.id, v.fecha, p.monto, v.estado, NULL AS metodo, NULL AS nota, v.id AS venta_id
                     FROM ventas v JOIN pagos p ON p.venta_id = v.id AND p.metodo = 'credito'
                     WHERE v.cliente_id = :c1
                     UNION ALL
                     SELECT 'abono', id, fecha, monto, estado, metodo_pago, nota, NULL
                     FROM abonos WHERE cliente_id = :c2
                     UNION ALL
                     SELECT 'devolucion', d.id, d.fecha, d.total, v.estado, NULL, d.motivo, v.id
                     FROM devoluciones d JOIN ventas v ON v.id = d.venta_id
                     WHERE v.cliente_id = :c3 AND d.reembolso = 'credito'
                     ORDER BY fecha, tipo DESC, id");
$st->execute([':c1' => $id, ':c2' => $id, ':c3' => $id]);
$movimientos = $st->fetchAll();
$acumulado = 0;
foreach ($movimientos as &$m) {
    $vigente = in_array($m['estado'], ['completada', 'aplicado'], true);
    if ($vigente) {
        $acumulado += ($m['tipo'] === 'venta' ? 1 : -1) * a_centavos($m['monto']);
    }
    $m['saldo'] = $acumulado;
    $m['vigente'] = $vigente;
}
unset($m);
$movimientos = array_reverse($movimientos);   // lo más reciente arriba

$st = db()->prepare("SELECT COUNT(*) FROM ventas v WHERE v.cliente_id = :c AND v.estado = 'completada'
                     AND NOT EXISTS (SELECT 1 FROM pagos p WHERE p.venta_id = v.id AND p.metodo = 'credito')");
$st->execute([':c' => $id]);
$comprasContado = (int) $st->fetchColumn();

$titulo  = $cliente['nombre'];
$seccion = 'clientes';
require __DIR__ . '/../includes/encabezado.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <nav aria-label="Ruta"><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= e(url('clientes/index.php')) ?>">Clientes</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($cliente['nombre']) ?></li>
    </ol></nav>
    <div class="d-flex gap-2">
        <a href="<?= e(url('ventas/historial.php?cliente=' . $id)) ?>" class="btn btn-light border"><i class="bi bi-receipt me-1"></i>Todas sus compras</a>
        <a href="<?= e(url('clientes/editar.php?id=' . $id)) ?>" class="btn btn-light border"><i class="bi bi-pencil me-1"></i>Editar</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-body p-4 text-center">
                <span class="login-inicial mb-2" style="width:56px;height:56px;font-size:1.5rem"><?= e(mb_strtoupper(mb_substr($cliente['nombre'], 0, 1))) ?></span>
                <h2 class="h5 fw-bold mb-0"><?= e($cliente['nombre']) ?></h2>
                <?php if (!(int) $cliente['activo']): ?><span class="badge text-bg-secondary">Desactivado</span><?php endif; ?>
                <div class="text-body-secondary small"><?= e($cliente['telefono'] ?? '') ?></div>
                <div class="rounded-4 py-3 my-3 <?= $saldo > 0 ? 'bg-danger-subtle' : 'bg-success-subtle' ?>">
                    <div class="small fw-semibold text-uppercase"><?= $saldo > 0 ? 'Debe' : ($saldo < 0 ? 'Saldo a favor' : 'Al corriente') ?></div>
                    <div class="fs-2 fw-bold num"><?= dinero(abs($saldo) / 100) ?></div>
                    <div class="small">Límite: <?= $cliente['limite_credito'] !== null ? dinero($cliente['limite_credito']) : 'sin límite' ?></div>
                </div>
                <?php if ($cliente['notas']): ?><p class="small text-start mb-0" style="white-space:pre-line"><?= e($cliente['notas']) ?></p><?php endif; ?>
                <?php if ($comprasContado): ?><div class="small text-body-secondary mt-2"><?= $comprasContado ?> compra(s) de contado</div><?php endif; ?>
            </div>
        </div>

        <?php if ($saldo > 0): ?>
            <div class="card">
                <div class="card-header"><h2><i class="bi bi-cash-coin me-2 text-primary"></i>Registrar abono</h2></div>
                <div class="card-body p-4">
                    <form method="post" action="<?= e(url('clientes/acciones.php')) ?>">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="accion" value="abonar">
                        <input type="hidden" name="cliente_id" value="<?= (int) $id ?>">
                        <label for="monto" class="form-label">¿Cuánto abona?</label>
                        <div class="input-group input-group-lg mb-2">
                            <span class="input-group-text">$</span>
                            <input type="text" class="form-control num" id="monto" name="monto" inputmode="decimal" required>
                        </div>
                        <button type="button" class="btn btn-sm btn-link px-0 mb-2" id="btnLiquidar">Paga todo (<?= dinero($saldo / 100) ?>)</button>
                        <div class="d-flex flex-wrap gap-2 mb-3" role="radiogroup">
                            <?php $primero = true; foreach (METODOS_ABONO as $clave => $etiqueta): ?>
                                <input type="radio" class="btn-check" name="metodo_pago" id="m_<?= e($clave) ?>" value="<?= e($clave) ?>" <?= $primero ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary btn-sm" for="m_<?= e($clave) ?>"><?= e($etiqueta) ?></label>
                            <?php $primero = false; endforeach; ?>
                        </div>
                        <input type="text" class="form-control mb-3" name="nota" maxlength="150" placeholder="Nota (opcional)">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check2-circle me-1"></i>Registrar abono</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h2><i class="bi bi-journal-text me-2 text-primary"></i>Estado de cuenta</h2></div>
            <?php if (!$movimientos): ?>
                <div class="estado-vacio"><i class="bi bi-journal"></i>Este cliente no ha comprado a crédito.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Fecha</th><th>Movimiento</th><th class="text-end">Cargo</th><th class="text-end">Abono</th><th class="text-end">Saldo</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($movimientos as $m): ?>
                            <tr class="<?= $m['vigente'] ? '' : 'fila-inactiva' ?>">
                                <td class="num small"><?= e(fecha_fmt($m['fecha'])) ?></td>
                                <td>
                                    <?php if ($m['tipo'] === 'venta'): ?>
                                        <a href="<?= e(url('ventas/detalle.php?id=' . (int) $m['id'])) ?>">Compra #<?= e(folio($m['id'])) ?></a>
                                    <?php elseif ($m['tipo'] === 'devolucion'): ?>
                                        Devolución #<?= (int) $m['id'] ?> de la <a href="<?= e(url('ventas/detalle.php?id=' . (int) $m['venta_id'])) ?>">compra #<?= e(folio($m['venta_id'])) ?></a>
                                        <?php if ($m['nota']): ?><div class="small text-body-secondary"><?= e($m['nota']) ?></div><?php endif; ?>
                                    <?php else: ?>
                                        Abono #<?= (int) $m['id'] ?> <span class="small text-body-secondary">· <?= e(METODOS_ABONO[$m['metodo']] ?? $m['metodo']) ?></span>
                                        <?php if ($m['nota']): ?><div class="small text-body-secondary"><?= e($m['nota']) ?></div><?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (!$m['vigente']): ?><span class="badge text-bg-danger-subtle text-danger-emphasis ms-1">Cancelado</span><?php endif; ?>
                                </td>
                                <td class="text-end num"><?= $m['tipo'] === 'venta' ? dinero($m['monto']) : '' ?></td>
                                <td class="text-end num text-success"><?= $m['tipo'] !== 'venta' ? dinero($m['monto']) : '' ?></td>
                                <td class="text-end num fw-semibold"><?= dinero($m['saldo'] / 100) ?></td>
                                <td class="text-end text-nowrap">
                                    <?php if ($m['tipo'] === 'abono' && impresora_activa()): ?>
                                        <button type="button" class="btn btn-sm btn-light border btn-imprimir-abono" data-id="<?= (int) $m['id'] ?>" title="Imprimir comprobante"><i class="bi bi-printer"></i></button>
                                    <?php endif; ?>
                                    <?php if ($m['tipo'] === 'abono' && $m['vigente'] && permiso('cancelar')): ?>
                                        <button type="button" class="btn btn-sm btn-light border text-danger btn-cancelar-abono" data-id="<?= (int) $m['id'] ?>" title="Cancelar abono"><i class="bi bi-x-lg"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<form method="post" action="<?= e(url('clientes/acciones.php')) ?>" id="formCancelarAbono" class="d-none">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion" value="cancelar_abono">
    <input type="hidden" name="abono_id" id="cancelarAbonoId">
    <input type="hidden" name="motivo" id="cancelarAbonoMotivo">
</form>

<script>
(function () {
    const liquidar = document.getElementById('btnLiquidar');
    if (liquidar) {
        liquidar.addEventListener('click', function () {
            document.getElementById('monto').value = '<?= centavos_a_decimal(max(0, $saldo)) ?>';
        });
    }
    document.querySelectorAll('.btn-imprimir-abono').forEach(function (b) {
        b.addEventListener('click', async function () {
            b.disabled = true;
            try {
                await TW.json('ventas/impresora.php', { method: 'POST', body: { accion: 'abono', id: Number(b.dataset.id) } });
                TW.toast('success', 'Comprobante enviado a la impresora.');
            } catch (e) { TW.alerta('error', 'No se pudo imprimir', e.message); }
            b.disabled = false;
        });
    });
    document.querySelectorAll('.btn-cancelar-abono').forEach(function (b) {
        b.addEventListener('click', function () {
            Swal.fire({
                icon: 'warning', titleText: 'Cancelar el abono #' + b.dataset.id,
                text: 'El cliente volverá a deber esa cantidad. Si era en efectivo, se registrará la salida del dinero de la caja.',
                input: 'text', inputLabel: 'Motivo', inputAttributes: { maxlength: 150 },
                showCancelButton: true, confirmButtonText: 'Cancelar abono', cancelButtonText: 'No, regresar',
                confirmButtonColor: '#dc3545', reverseButtons: true,
                inputValidator: v => (v || '').trim().length < 5 ? 'Escribe un motivo de al menos 5 caracteres.' : undefined
            }).then(function (r) {
                if (!r.isConfirmed) { return; }
                document.getElementById('cancelarAbonoId').value = b.dataset.id;
                document.getElementById('cancelarAbonoMotivo').value = r.value.trim();
                document.getElementById('formCancelarAbono').submit();
            });
        });
    });
})();
</script>
<?php require __DIR__ . '/../includes/pie.php'; ?>
