<?php
/**
 * Detalle de una compra y su cancelación (regresa las existencias y, si se
 * pagó con dinero de la caja, registra la entrada del reembolso).
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('inventario');

$id = filter_var($_REQUEST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$st = db()->prepare('SELECT c.*, p.nombre AS proveedor FROM compras c LEFT JOIN proveedores p ON p.id = c.proveedor_id WHERE c.id = :id');
$st->execute([':id' => $id]);
$compra = $st->fetch();
if (!$compra) {
    flash('error', 'La compra no existe.');
    redirigir('compras/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_post_csrf();
    $motivo = trim((string) ($_POST['motivo'] ?? ''));
    if ($compra['estado'] !== 'recibida') {
        flash('aviso', 'Esta compra ya estaba cancelada.');
        redirigir('compras/detalle.php?id=' . $id);
    }
    if (mb_strlen($motivo) < 5) {
        flash('error', 'Escribe el motivo de la cancelación.');
        redirigir('compras/detalle.php?id=' . $id);
    }
    transaccion_iniciar();
    try {
        db()->prepare("UPDATE compras SET estado = 'cancelada', cancelada_en = :f, motivo_cancelacion = :m WHERE id = :id AND estado = 'recibida'")
            ->execute([':f' => date('Y-m-d H:i:s'), ':m' => mb_substr($motivo, 0, 255), ':id' => $id]);
        $st = db()->prepare('SELECT cp.producto_id, cp.cantidad, p.nombre FROM compra_productos cp JOIN productos p ON p.id = cp.producto_id WHERE cp.compra_id = :id');
        $st->execute([':id' => $id]);
        foreach ($st->fetchAll() as $l) {
            try {
                mover_existencia((int) $l['producto_id'], 'ajuste', -a_milesimas($l['cantidad']), null, 'Cancelación de la compra #' . $id);
            } catch (RuntimeException $e) {
                throw new RuntimeException("No se puede cancelar: ya no hay suficiente «{$l['nombre']}» para regresarlo (quizá ya se vendió). "
                    . 'Haz un conteo físico para ajustar la existencia.');
            }
        }
        $aviso = '';
        if ((int) $compra['pagada_de_caja'] && (float) $compra['total'] > 0) {
            if (turno_abierto()) {
                registrar_movimiento_caja('entrada', a_centavos($compra['total']), 'Reembolso por cancelar la compra #' . $id);
                $aviso = ' Se registró la entrada de ' . dinero($compra['total']) . ' a la caja.';
            } else {
                $aviso = ' La caja está cerrada: si el proveedor te regresó el dinero, regístralo como entrada al abrirla.';
            }
        }
        auditar('inventario', 'Canceló la compra #' . $id . ' por ' . dinero($compra['total']) . '. Motivo: ' . $motivo, 'compra', $id);
        transaccion_confirmar();
    } catch (RuntimeException $e) {
        transaccion_revertir();
        if ($e instanceof PDOException) {
            throw $e;
        }
        flash('error', $e->getMessage());
        redirigir('compras/detalle.php?id=' . $id);
    }
    flash('exito', 'Compra cancelada y existencias regresadas.' . $aviso);
    redirigir('compras/detalle.php?id=' . $id);
}

$st = db()->prepare('SELECT cp.*, p.nombre, p.codigo_barras FROM compra_productos cp JOIN productos p ON p.id = cp.producto_id WHERE cp.compra_id = :id ORDER BY cp.id');
$st->execute([':id' => $id]);
$lineas = $st->fetchAll();

$titulo  = 'Compra #' . $id;
$seccion = 'compras';
require __DIR__ . '/../includes/encabezado.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <nav aria-label="Ruta"><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= e(url('compras/index.php')) ?>">Compras</a></li>
        <li class="breadcrumb-item active" aria-current="page">#<?= $id ?></li>
    </ol></nav>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-light border" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button>
        <?php if ($compra['estado'] === 'recibida'): ?>
            <button type="button" class="btn btn-outline-danger" id="btnCancelar"><i class="bi bi-x-circle me-1"></i>Cancelar compra</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($compra['estado'] === 'cancelada'): ?>
    <div class="alert alert-danger"><strong>Compra cancelada</strong> el <?= e(fecha_fmt($compra['cancelada_en'])) ?>. Motivo: <?= e($compra['motivo_cancelacion']) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card"><div class="card-body p-4">
            <dl class="mb-0">
                <dt>Proveedor</dt><dd><?= e($compra['proveedor'] ?? 'Sin proveedor') ?></dd>
                <dt>Fecha</dt><dd><?= e(fecha_fmt($compra['fecha'])) ?></dd>
                <dt>Factura o nota</dt><dd><?= e($compra['folio_proveedor'] ?? '—') ?></dd>
                <dt>Pago</dt><dd><?= (int) $compra['pagada_de_caja'] ? 'Con dinero de la caja (turno #' . (int) $compra['turno_id'] . ')' : 'Fuera de la caja' ?></dd>
                <dt>Registró</dt><dd><?= e($compra['usuario_id'] ? nombre_usuario((int) $compra['usuario_id']) : '—') ?></dd>
                <?php if ($compra['notas']): ?><dt>Notas</dt><dd><?= e($compra['notas']) ?></dd><?php endif; ?>
            </dl>
            <div class="bg-primary-suave rounded-4 text-center py-3 mt-3">
                <div class="small text-body-secondary">Total</div>
                <div class="fs-3 fw-bold num"><?= dinero($compra['total']) ?></div>
            </div>
        </div></div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Producto</th><th class="text-end">Cantidad</th><th class="text-end">Costo c/u</th><th class="text-end">Importe</th></tr></thead>
                    <tbody>
                    <?php foreach ($lineas as $l): ?>
                        <tr>
                            <td><?= e($l['nombre']) ?><div class="small text-body-secondary"><?= e($l['codigo_barras'] ?? '') ?></div></td>
                            <td class="text-end num"><?= e(cantidad_fmt($l['cantidad'])) ?></td>
                            <td class="text-end num"><?= dinero($l['costo_unitario']) ?></td>
                            <td class="text-end num fw-semibold"><?= dinero($l['importe']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($compra['estado'] === 'recibida'): ?>
<form method="post" id="formCancelar" class="d-none">
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="motivo" id="motivo">
</form>
<script>
document.getElementById('btnCancelar').addEventListener('click', function () {
    Swal.fire({
        icon: 'warning', titleText: 'Cancelar la compra #<?= $id ?>',
        text: 'Se restarán del inventario los productos de esta compra.<?= (int) $compra['pagada_de_caja'] ? ' Como se pagó con dinero de la caja, se registrará la entrada del reembolso.' : '' ?>',
        input: 'text', inputLabel: 'Motivo', showCancelButton: true, confirmButtonText: 'Cancelar compra', cancelButtonText: 'No, regresar',
        confirmButtonColor: '#dc3545', reverseButtons: true,
        inputValidator: v => (v || '').trim().length < 5 ? 'Escribe un motivo de al menos 5 caracteres.' : undefined
    }).then(function (r) {
        if (!r.isConfirmed) { return; }
        document.getElementById('motivo').value = r.value.trim();
        document.getElementById('formCancelar').submit();
    });
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/pie.php'; ?>
