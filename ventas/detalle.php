<?php
require_once __DIR__ . '/../includes/app.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    flash('error', 'Folio no válido.');
    redirigir('ventas/historial.php');
}

$st = db()->prepare('SELECT * FROM ventas WHERE id = :id');
$st->execute([':id' => $id]);
$venta = $st->fetch();
if (!$venta) {
    flash('error', 'No existe la venta con folio ' . folio($id) . '.');
    redirigir('ventas/historial.php');
}

$st = db()->prepare('SELECT d.*, p.activo AS producto_activo
                     FROM detalle_ventas d LEFT JOIN productos p ON p.id = d.producto_id
                     WHERE d.venta_id = :id ORDER BY d.id');
$st->execute([':id' => $id]);
$detalle = $st->fetchAll();

$articulos = array_sum(array_map(fn($d) => (float) $d['cantidad'], $detalle));
$cancelada = $venta['estado'] === 'cancelada';
$cliente   = $venta['cliente_id'] ? cliente_por_id((int) $venta['cliente_id']) : null;
$pagos     = pagos_venta((int) $venta['id']);
$devuelto  = devuelto_por_renglon((int) $venta['id']);
$factura   = factura_de_venta((int) $venta['id']);
$facturaVigente = $factura && $factura['estado'] !== 'cancelada' ? $factura : null;
$st = db()->prepare('SELECT * FROM devoluciones WHERE venta_id = :id ORDER BY id');
$st->execute([':id' => $id]);
$devoluciones = $st->fetchAll();
$quedaPorDevolver = array_filter($detalle, fn($d) => a_milesimas($d['cantidad']) > ($devuelto[(int) $d['id']]['milesimas'] ?? 0));

$titulo  = 'Venta #' . folio($venta['id']);
$seccion = 'historial';
require __DIR__ . '/../includes/encabezado.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-imprimir">
    <nav aria-label="Ruta">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= e(url('ventas/historial.php')) ?>">Historial</a></li>
            <li class="breadcrumb-item active" aria-current="page">Folio <?= e(folio($venta['id'])) ?></li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <?php if (impresora_activa()): ?>
            <button type="button" class="btn btn-light border" id="btnTicketTermico"><i class="bi bi-printer me-1"></i>Imprimir ticket</button>
            <button type="button" class="btn btn-light border" onclick="window.print()" title="Imprimir en una hoja con la impresora normal"><i class="bi bi-file-earmark-text me-1"></i>En hoja</button>
        <?php else: ?>
            <button type="button" class="btn btn-light border" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir ticket</button>
        <?php endif; ?>
        <?php if ($facturaVigente): ?>
            <a href="<?= e(url('ventas/factura.php?id=' . (int) $venta['id'])) ?>" class="btn btn-light border"><i class="bi bi-receipt-cutoff me-1 text-success"></i>Factura: <?= e(ESTADOS_FACTURA[$facturaVigente['estado']][0]) ?></a>
        <?php elseif (!$cancelada): ?>
            <a href="<?= e(url('ventas/factura.php?id=' . (int) $venta['id'])) ?>" class="btn btn-light border"><i class="bi bi-receipt-cutoff me-1"></i>Pedir factura</a>
        <?php endif; ?>
        <?php if (!$cancelada && permiso('cancelar') && $quedaPorDevolver): ?>
            <a href="<?= e(url('ventas/devolver.php?id=' . (int) $venta['id'])) ?>" class="btn btn-light border"><i class="bi bi-arrow-return-left me-1"></i>Devolver productos</a>
        <?php endif; ?>
        <?php if (!$cancelada && permiso('cancelar') && !$devoluciones): ?>
            <button type="button" class="btn btn-outline-danger" id="btnCancelarVenta"><i class="bi bi-x-circle me-1"></i>Cancelar venta</button>
        <?php endif; ?>
        <a href="<?= e(url('ventas/index.php')) ?>" class="btn btn-primary"><i class="bi bi-cart-plus me-1"></i>Nueva venta</a>
    </div>
</div>

<?php if ($cancelada): ?>
    <div class="alert alert-danger d-flex gap-2 no-imprimir">
        <i class="bi bi-x-octagon fs-4"></i>
        <div>
            <strong>Venta cancelada</strong> el <?= e(fecha_fmt($venta['cancelada_en'])) ?><?= $venta['cancelada_por'] ? ' por ' . e(nombre_usuario((int) $venta['cancelada_por'])) : '' ?>.
            <div>Motivo: <?= e($venta['motivo_cancelacion']) ?></div>
            <small>Las existencias de los productos con inventario fueron restituidas. Esta venta no cuenta en los totales.</small>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4 solo-ticket-imprimir">
    <div class="col-lg-8 col-print-oculto">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2><i class="bi bi-list-ul me-2 text-primary"></i>Productos</h2>
                <?= estado_venta_badge($venta['estado']) ?>
            </div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                    <tr>
                        <th>Producto</th>
                        <th class="text-end">Cantidad</th>
                        <th class="text-end">Precio unitario</th>
                        <th class="text-end">Subtotal</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($detalle as $d): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e($d['nombre_producto']) ?></div>
                                <small class="text-body-secondary">
                                    <?php if ($d['producto_id'] === null): ?>
                                        Producto eliminado del catálogo
                                    <?php elseif (!$d['producto_activo']): ?>
                                        Producto desactivado
                                    <?php else: ?>
                                        <a href="<?= e(url('productos/editar.php?id=' . (int) $d['producto_id'])) ?>" class="text-body-secondary">Ver producto</a>
                                    <?php endif; ?>
                                    <?= $d['descuenta_stock'] ? '' : ' · servicio' ?>
                                    <?php if (isset($devuelto[(int) $d['id']])): ?>
                                        <span class="badge text-bg-warning-subtle text-warning-emphasis ms-1">Devolvió <?= e(cantidad_fmt($devuelto[(int) $d['id']]['milesimas'] / 1000)) ?></span>
                                    <?php endif; ?>
                                </small>
                            </td>
                            <td class="text-end num"><?= e(cantidad_fmt($d['cantidad'])) ?></td>
                            <td class="text-end num"><?= dinero($d['precio_unitario']) ?></td>
                            <td class="text-end num fw-semibold"><?= dinero($d['subtotal']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                    <tr>
                        <th colspan="3" class="text-end border-0 pt-3">Total</th>
                        <th class="text-end border-0 pt-3 fs-5 num"><?= dinero($venta['total']) ?></th>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php if ($devoluciones): ?>
            <div class="card mt-4 no-imprimir">
                <div class="card-header"><h2><i class="bi bi-arrow-return-left me-2 text-warning"></i>Devoluciones</h2></div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($devoluciones as $dv): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <div>
                                <div class="fw-semibold">#<?= (int) $dv['id'] ?> · <?= e(fecha_fmt($dv['fecha'])) ?></div>
                                <div class="small text-body-secondary"><?= e(explode(' (', METODOS_REEMBOLSO[$dv['reembolso']])[0]) ?> · <?= e($dv['motivo']) ?><?= $dv['usuario_id'] ? ' · ' . e(nombre_usuario((int) $dv['usuario_id'])) : '' ?></div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-bold num text-danger">−<?= dinero($dv['total']) ?></span>
                                <?php if (impresora_activa()): ?>
                                    <button type="button" class="btn btn-sm btn-light border btn-ticket-dev" data-id="<?= (int) $dv['id'] ?>" title="Imprimir comprobante"><i class="bi bi-printer"></i></button>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4 ticket-imprimible">
        <div class="card">
            <div class="card-body ticket">
                <div class="text-center mb-2">
                    <div class="fw-bold fs-5"><?= e(nombre_negocio()) ?></div>
                    <?php if (ajuste('negocio_datos') !== ''): ?>
                        <div class="small" style="white-space:pre-line"><?= e(ajuste('negocio_datos')) ?></div>
                    <?php endif; ?>
                    <div class="small">Ticket de venta</div>
                </div>
                <hr>
                <div class="d-flex justify-content-between"><span>Folio:</span><strong><?= e(folio($venta['id'])) ?></strong></div>
                <div class="d-flex justify-content-between"><span>Fecha:</span><span><?= e(fecha_fmt($venta['fecha'])) ?></span></div>
                <?php if ($cancelada): ?>
                    <div class="text-center fw-bold text-danger mt-1">*** CANCELADA ***</div>
                <?php endif; ?>
                <hr>
                <?php foreach ($detalle as $d): ?>
                    <div><?= e($d['nombre_producto']) ?></div>
                    <div class="d-flex justify-content-between ps-2 mb-1">
                        <span><?= e(cantidad_fmt($d['cantidad'])) ?> x <?= dinero($d['precio_unitario']) ?></span>
                        <span><?= dinero($d['subtotal']) ?></span>
                    </div>
                    <?php if ((float) $d['descuento'] > 0): ?>
                        <div class="d-flex justify-content-between ps-3 small text-success"><span>Descuento</span><span>−<?= dinero($d['descuento']) ?></span></div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <hr>
                <div class="d-flex justify-content-between small"><span>Artículos:</span><span><?= e(cantidad_fmt($articulos)) ?></span></div>
                <?php if ((float) $venta['descuento'] > 0): ?>
                    <div class="d-flex justify-content-between"><span>Subtotal:</span><span><?= dinero($venta['subtotal']) ?></span></div>
                    <div class="d-flex justify-content-between text-success"><span>Descuento:</span><span>−<?= dinero($venta['descuento']) ?></span></div>
                <?php endif; ?>
                <?php if (ajuste('iva_ticket') === '1'): foreach (renglones_desglose_iva($detalle) as [$etiquetaIva, $centavosIva]): ?>
                    <div class="d-flex justify-content-between small"><span><?= e($etiquetaIva) ?>:</span><span><?= dinero($centavosIva / 100) ?></span></div>
                <?php endforeach; endif; ?>
                <div class="d-flex justify-content-between fs-5 fw-bold"><span>TOTAL:</span><span><?= dinero($venta['total']) ?></span></div>
                <div class="small text-center mb-1">(<?= e(importe_en_letra(a_centavos($venta['total']))) ?>)</div>
                <?php if (count($pagos) > 1): ?>
                    <div>Pago dividido:</div>
                    <?php foreach ($pagos as $m => $monto): ?>
                        <div class="d-flex justify-content-between ps-2"><span><?= e(metodo_pago_etiqueta($m)) ?></span><span><?= dinero($monto) ?></span></div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="d-flex justify-content-between"><span>Pago:</span><span><?= e(metodo_pago_etiqueta($venta['metodo_pago'])) ?></span></div>
                <?php endif; ?>
                <?php if (isset($pagos['efectivo']) || $venta['metodo_pago'] === 'efectivo'): ?>
                    <div class="d-flex justify-content-between"><span>Efectivo recibido:</span><span><?= dinero($venta['recibido']) ?></span></div>
                    <div class="d-flex justify-content-between"><span>Cambio:</span><span><?= dinero($venta['cambio']) ?></span></div>
                <?php endif; ?>
                <?php if ($cliente): ?>
                    <div class="d-flex justify-content-between"><span>Cliente:</span><span><?= e($cliente['nombre']) ?></span></div>
                <?php endif; ?>
                <?php if ($venta['usuario_id']): ?>
                    <div class="d-flex justify-content-between small"><span>Atendió:</span><span><?= e(nombre_usuario((int) $venta['usuario_id'])) ?></span></div>
                <?php endif; ?>
                <?php if ($devoluciones): ?>
                    <div class="d-flex justify-content-between text-danger"><span>Devuelto:</span><span>−<?= dinero(array_sum(array_column($devoluciones, 'total'))) ?></span></div>
                <?php endif; ?>
                <?php if ($facturaVigente): ?>
                    <hr><div class="small">Factura <?= $facturaVigente['estado'] === 'hecha' ? 'emitida' : 'solicitada' ?> a:<br><?= e($facturaVigente['razon_social']) ?><br>RFC: <?= e($facturaVigente['rfc']) ?>
                        <?= $facturaVigente['uuid'] ? '<br>Folio fiscal: ' . e($facturaVigente['uuid']) : '' ?></div>
                <?php elseif (!$cancelada && ajuste('ticket_factura') !== ''): ?>
                    <hr><div class="text-center small"><?= e(ajuste('ticket_factura')) ?></div>
                <?php endif; ?>
                <?php if (ajuste('ticket_pie') !== ''): ?>
                    <hr>
                    <div class="text-center small"><?= e(ajuste('ticket_pie')) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (impresora_activa()): ?>
<script>
document.getElementById('btnTicketTermico').addEventListener('click', async function () {
    const boton = this;
    boton.disabled = true;
    try {
        await TW.json('ventas/impresora.php', { method: 'POST', body: { accion: 'ticket', id: <?= (int) $venta['id'] ?> } });
        TW.toast('success', 'Ticket enviado a la impresora.');
    } catch (e) {
        TW.alerta('error', 'No se pudo imprimir', e.message);
    } finally {
        boton.disabled = false;
    }
});
document.querySelectorAll('.btn-ticket-dev').forEach(function (b) {
    b.addEventListener('click', async function () {
        b.disabled = true;
        try {
            await TW.json('ventas/impresora.php', { method: 'POST', body: { accion: 'devolucion', id: Number(b.dataset.id) } });
            TW.toast('success', 'Comprobante enviado a la impresora.');
        } catch (e) {
            TW.alerta('error', 'No se pudo imprimir', e.message);
        } finally {
            b.disabled = false;
        }
    });
});
</script>
<?php endif; ?>

<?php if (!$cancelada && permiso('cancelar') && !$devoluciones): ?>
<form method="post" action="<?= e(url('ventas/cancelar.php')) ?>" id="formCancelar" class="d-none">
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= (int) $venta['id'] ?>">
    <input type="hidden" name="motivo" id="motivoCancelacion">
</form>
<script>
document.getElementById('btnCancelarVenta').addEventListener('click', function () {
    Swal.fire({
        icon: 'warning',
        titleText: 'Cancelar la venta <?= e(folio($venta['id'])) ?>',
        text: 'La venta quedará marcada como cancelada, se devolverán las existencias y dejará de contar en los totales. Esta acción no se puede deshacer.',
        input: 'text',
        inputLabel: 'Motivo de la cancelación',
        inputPlaceholder: 'Ej. El cliente devolvió el producto',
        inputAttributes: { maxlength: 255 },
        showCancelButton: true,
        confirmButtonText: 'Cancelar venta',
        cancelButtonText: 'No, regresar',
        confirmButtonColor: '#dc3545',
        reverseButtons: true,
        inputValidator: function (valor) {
            valor = (valor || '').trim();
            if (valor.length < 5) { return 'Escribe un motivo de al menos 5 caracteres.'; }
            if (valor.length > 255) { return 'El motivo no puede pasar de 255 caracteres.'; }
        }
    }).then(function (r) {
        if (r.isConfirmed) {
            document.getElementById('motivoCancelacion').value = r.value.trim();
            document.getElementById('formCancelar').submit();
        }
    });
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/pie.php'; ?>
