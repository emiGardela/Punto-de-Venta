<?php
/**
 * Devolver una o varias piezas de una venta (devolución parcial).
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('cancelar');

$id = filter_var($_REQUEST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$st = db()->prepare('SELECT v.*, c.nombre AS cliente FROM ventas v LEFT JOIN clientes c ON c.id = v.cliente_id WHERE v.id = :id');
$st->execute([':id' => $id]);
$venta = $st->fetch();
if (!$venta || $venta['estado'] !== 'completada') {
    flash('error', 'Solo se pueden hacer devoluciones de ventas completadas.');
    redirigir($venta ? 'ventas/detalle.php?id=' . $id : 'ventas/historial.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_post_csrf();
    $motivo = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['motivo'] ?? '')) ?? ''), 0, 255);
    $renglones = [];
    foreach ((array) ($_POST['cantidad'] ?? []) as $detalleId => $cant) {
        $cant = trim((string) $cant);
        if ($cant === '' || $cant === '0') {
            continue;
        }
        $norm = normalizar_decimal($cant, 3, 9);
        if ($norm === null) {
            flash('error', 'Hay una cantidad no válida.');
            redirigir('ventas/devolver.php?id=' . $id);
        }
        $renglones[(int) $detalleId] = ['milesimas' => a_milesimas($norm), 'regresa' => !empty($_POST['regresa'][$detalleId])];
    }
    if (mb_strlen($motivo) < 5) {
        flash('error', 'Escribe el motivo de la devolución (al menos 5 caracteres).');
        redirigir('ventas/devolver.php?id=' . $id);
    }
    transaccion_iniciar();
    try {
        $devId = registrar_devolucion($id, $renglones, (string) ($_POST['reembolso'] ?? ''), $motivo);
        transaccion_confirmar();
    } catch (RuntimeException $e) {
        transaccion_revertir();
        if ($e instanceof PDOException) {
            throw $e;
        }
        flash('error', $e->getMessage());
        redirigir('ventas/devolver.php?id=' . $id);
    }
    $st = db()->prepare('SELECT total, reembolso FROM devoluciones WHERE id = :id');
    $st->execute([':id' => $devId]);
    $dev = $st->fetch();
    flash('exito', 'Devolución registrada: reembolso de ' . dinero($dev['total']) . '.');
    if (impresora_activa()) {
        try {
            $aj = ajustes();
            enviar_a_impresora(($dev['reembolso'] === 'efectivo' && $aj['cajon_al_cobrar'] !== 'nunca' ? escpos_cajon($aj['cajon_pin']) : '')
                . escpos_devolucion($devId, $aj), $aj);
        } catch (RuntimeException $e) {
            flash('aviso', 'La devolución se guardó, pero no se pudo imprimir: ' . $e->getMessage());
        }
    }
    redirigir('ventas/detalle.php?id=' . $id);
}

$st = db()->prepare('SELECT * FROM detalle_ventas WHERE venta_id = :id ORDER BY id');
$st->execute([':id' => $id]);
$detalle = $st->fetchAll();
$devuelto = devuelto_por_renglon($id);
$pagos = pagos_venta($id);
$sugerido = isset($pagos['efectivo']) ? 'efectivo' : (isset($pagos['credito']) ? 'credito' : (array_key_first($pagos) ?: 'efectivo'));

$titulo  = 'Devolución · venta ' . folio($id);
$seccion = 'historial';
require __DIR__ . '/../includes/encabezado.php';
?>
<nav aria-label="Ruta" class="mb-3"><ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= e(url('ventas/historial.php')) ?>">Historial</a></li>
    <li class="breadcrumb-item"><a href="<?= e(url('ventas/detalle.php?id=' . $id)) ?>">Folio <?= e(folio($id)) ?></a></li>
    <li class="breadcrumb-item active" aria-current="page">Devolución</li>
</ol></nav>

<form method="post" id="formDevolucion">
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h2><i class="bi bi-arrow-return-left me-2 text-primary"></i>¿Qué devuelve el cliente?</h2></div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Producto</th><th class="text-end">Compró</th><th class="text-end">Ya devolvió</th>
                            <th style="width:120px">Devuelve</th><th class="text-center" title="Desmárcalo si viene dañado">¿Regresa al anaquel?</th><th class="text-end">Reembolso</th></tr></thead>
                        <tbody>
                        <?php foreach ($detalle as $d):
                            $vendido = a_milesimas($d['cantidad']);
                            $ya = $devuelto[(int) $d['id']]['milesimas'] ?? 0;
                            $resta = $vendido - $ya;
                            $neto = a_centavos($d['subtotal']) - a_centavos($d['descuento']);
                            $restaImporte = $neto - ($devuelto[(int) $d['id']]['centavos'] ?? 0); ?>
                            <tr data-vendido="<?= $vendido ?>" data-neto="<?= $neto ?>" data-resta="<?= $resta ?>" data-resta-importe="<?= $restaImporte ?>">
                                <td><?= e($d['nombre_producto']) ?><div class="small text-body-secondary"><?= dinero($neto / max(1, $vendido) * 10) ?> c/u cobrado</div></td>
                                <td class="text-end num"><?= e(cantidad_fmt($vendido / 1000)) ?></td>
                                <td class="text-end num"><?= $ya ? e(cantidad_fmt($ya / 1000)) : '—' ?></td>
                                <td>
                                    <?php if ($resta > 0): ?>
                                        <input type="number" class="form-control form-control-sm cant" name="cantidad[<?= (int) $d['id'] ?>]" min="0"
                                               max="<?= $resta / 1000 ?>" step="<?= $vendido % 1000 ? '0.001' : '1' ?>" value="0">
                                    <?php else: ?><span class="small text-body-secondary">Todo devuelto</span><?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($resta > 0 && $d['descuenta_stock']): ?>
                                        <input class="form-check-input" type="checkbox" name="regresa[<?= (int) $d['id'] ?>]" value="1" checked title="Desmárcalo si viene dañado">
                                    <?php elseif ($resta > 0): ?><span class="small text-body-secondary">Servicio</span><?php endif; ?>
                                </td>
                                <td class="text-end num importe">$0.00</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer small text-body-secondary">Si viene dañado, desmarca "Regresa al anaquel": se reembolsa pero no se suma a la existencia.</div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-body p-4">
                    <div class="bg-primary-suave rounded-4 text-center py-3 mb-3">
                        <div class="small text-body-secondary">Reembolso</div>
                        <div class="fs-2 fw-bold num" id="totalDevolucion">$0.00</div>
                    </div>
                    <div class="form-label">¿Cómo se le regresa el dinero?</div>
                    <?php foreach (METODOS_REEMBOLSO as $clave => $etiqueta):
                        if ($clave === 'credito' && !$venta['cliente_id']) { continue; } ?>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="reembolso" value="<?= e($clave) ?>" id="r_<?= e($clave) ?>" <?= $sugerido === $clave ? 'checked' : '' ?>>
                            <label class="form-check-label" for="r_<?= e($clave) ?>"><?= e($etiqueta) ?><?= $clave === 'credito' ? ' (' . e($venta['cliente']) . ')' : '' ?></label>
                        </div>
                    <?php endforeach; ?>
                    <div class="small text-body-secondary mt-1 mb-3">La venta se pagó con: <?= e(implode(', ', array_map('metodo_pago_etiqueta', array_keys($pagos)))) ?></div>
                    <label for="motivo" class="form-label">Motivo</label>
                    <input type="text" class="form-control mb-3" id="motivo" name="motivo" maxlength="255" required placeholder="Ej. Salió defectuoso / se equivocó de modelo">
                    <button type="submit" class="btn btn-primary btn-lg w-100" id="btnDevolver" disabled><i class="bi bi-check2-circle me-1"></i>Registrar devolución</button>
                </div>
            </div>
        </div>
    </div>
</form>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const filas = document.querySelectorAll('tr[data-vendido]');
    function recalcular() {
        let total = 0;
        filas.forEach(function (tr) {
            const inp = tr.querySelector('.cant');
            if (!inp) { return; }
            const mil = Math.round((Number(inp.value) || 0) * 1000);
            const resta = Number(tr.dataset.resta);
            const imp = mil <= 0 ? 0 : (mil >= resta ? Number(tr.dataset.restaImporte) : Math.round(Number(tr.dataset.neto) * mil / Number(tr.dataset.vendido)));
            tr.querySelector('.importe').textContent = TW.dinero(imp / 100);
            inp.classList.toggle('is-invalid', mil > resta || mil < 0);
            total += Math.max(0, imp);
        });
        document.getElementById('totalDevolucion').textContent = TW.dinero(total / 100);
        document.getElementById('btnDevolver').disabled = total <= 0 && !Array.from(document.querySelectorAll('.cant')).some(i => Number(i.value) > 0);
    }
    document.querySelectorAll('.cant').forEach(i => i.addEventListener('input', recalcular));
    recalcular();
});
</script>
<?php require __DIR__ . '/../includes/pie.php'; ?>
