<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Facturas pedidas por los clientes y la factura global del mes.
 * Desde aquí se descarga todo para el contador o para hacerlas en el portal del SAT.
 */
require_once __DIR__ . '/../includes/app.php';

if (!puede_administrar_facturas()) {
    negar_acceso('Tu usuario no puede ver las facturas. Pídeselo al dueño.');
}

$estado = array_key_exists($_GET['estado'] ?? '', ESTADOS_FACTURA) ? $_GET['estado'] : 'pendiente';
$mes = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['mes'] ?? '')) ? $_GET['mes'] : date('Y-m');
[$inicioMes, $finMes] = rango_sql($mes . '-01', date('Y-m-t', strtotime($mes . '-01')));

$st = db()->prepare("SELECT f.*, v.fecha AS fecha_venta, v.estado AS estado_venta FROM facturas f JOIN ventas v ON v.id = f.venta_id
                     WHERE f.estado = :estado ORDER BY f.id DESC");
$st->execute([':estado' => $estado]);
$facturas = $st->fetchAll();
foreach ($facturas as &$f) {
    $f['totales'] = totales_factura(conceptos_factura((int) $f['venta_id']));
}
unset($f);
$cuenta = db()->query('SELECT estado, COUNT(*) FROM facturas GROUP BY estado')->fetchAll(PDO::FETCH_KEY_PAIR);

// Factura global: ventas completadas del mes que no tienen factura propia.
$st = db()->prepare("SELECT id, fecha FROM ventas v WHERE estado = 'completada' AND fecha >= :inicio AND fecha < :fin
                     AND NOT EXISTS (SELECT 1 FROM facturas f WHERE f.venta_id = v.id AND f.estado <> 'cancelada') ORDER BY id");
$st->execute([':inicio' => $inicioMes, ':fin' => $finMes]);
$globales = [];
foreach ($st->fetchAll() as $vg) {
    $t = totales_factura(conceptos_factura((int) $vg['id']));
    if ($t['total'] > 0) {
        $globales[] = ['id' => (int) $vg['id'], 'fecha' => $vg['fecha'], 't' => $t];
    }
}
$totalGlobal = ['base' => array_sum(array_map(fn($g) => $g['t']['base'], $globales)), 'total' => array_sum(array_map(fn($g) => $g['t']['total'], $globales))];
$ivaGlobal = array_sum(array_map(fn($g) => $g['t']['iva16'] + $g['t']['iva8'], $globales));

$descarga = (string) ($_GET['descargar'] ?? '');
$p = fn(int $c) => round($c / 100, 2);            // centavos → pesos (número en Excel)
$negrita = fn($v) => is_string($v) ? ['t' => $v, 'negrita' => true] : ['n' => $v, 'negrita' => true];
$meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
if ($descarga === 'facturas') {
    $renglones = [[$negrita('Facturas ' . mb_strtolower(ESTADOS_FACTURA[$estado][0]) . ' · ' . nombre_negocio())],
                  ['Descargado el ' . fecha_fmt(date('Y-m-d H:i:s')) . '. La clave de producto del SAT (ClaveProdServ) la elige tu contador.'], [],
                  array_map($negrita, ['Ticket', 'Fecha', 'RFC', 'Nombre o razón social', 'C.P.', 'Régimen', 'Uso CFDI', 'Correo', 'Forma de pago',
                      'Método', 'Producto', 'Cantidad', 'Clave unidad', 'Precio sin IVA', 'Importe sin IVA', 'Tasa IVA', 'IVA', 'Total'])];
    foreach ($facturas as $f) {
        $forma = forma_pago_sat((int) $f['venta_id']);
        foreach (conceptos_factura((int) $f['venta_id']) as $c) {
            $renglones[] = [folio($f['venta_id']), date('d/m/Y', strtotime($f['fecha_venta'])), $f['rfc'], $f['razon_social'], $f['cp_fiscal'],
                (string) $f['regimen_fiscal'], $f['uso_cfdi'], (string) $f['correo'], $forma, $forma === '99' ? 'PPD' : 'PUE',
                $c['nombre'], (float) $c['cantidad'], CLAVES_UNIDAD_SAT[$c['unidad']] ?? 'H87', $p((int) round($c['base'] / max(0.001, $c['cantidad']))),
                $p($c['base']), $c['tasa'] === 'exento' ? 'Exento' : $c['tasa'] . '%', $p($c['iva']), $p($c['total'])];
        }
    }
    exportar_excel("facturas-$estado-" . date('Y-m-d') . '.xlsx', ['Facturas' => [
        'anchos' => [9, 11, 16, 30, 7, 9, 9, 24, 13, 8, 30, 9, 11, 13, 14, 9, 11, 11], 'renglones' => $renglones]]);
}
if ($descarga === 'global') {
    [$anio, $numMes] = array_map('intval', explode('-', $mes));
    $renglones = [
        [$negrita('Factura global · ' . $meses[$numMes] . ' ' . $anio . ' · ' . nombre_negocio())],
        ['Receptor', 'XAXX010101000 · PUBLICO EN GENERAL · Régimen 616 · Uso S01 · C.P. el de tu negocio'],
        ['Periodicidad', '04 · Mensual', 'Mes', sprintf('%02d', $numMes), 'Año', (string) $anio],
        ['Cada ticket es un concepto: ClaveProdServ 01010101, ClaveUnidad ACT, cantidad 1, descripción "Venta", método de pago PUE.'],
        [],
        array_map($negrita, ['Ticket', 'Fecha', 'ClaveProdServ', 'ClaveUnidad', 'Cantidad', 'Descripción', 'Base IVA 16%', 'IVA 16%',
            'Base IVA 8%', 'IVA 8%', 'Base IVA 0%', 'Exento', 'Total', 'Forma de pago', 'Nota']),
    ];
    $suma = ['base16' => 0, 'iva16' => 0, 'base8' => 0, 'iva8' => 0, 'base0' => 0, 'exento' => 0, 'total' => 0];
    foreach ($globales as $g) {
        foreach ($suma as $k => $_) {
            $suma[$k] += $g['t'][$k];
        }
        $renglones[] = [folio($g['id']), date('d/m/Y', strtotime($g['fecha'])), '01010101', 'ACT', 1, 'Venta',
            $p($g['t']['base16']), $p($g['t']['iva16']), $p($g['t']['base8']), $p($g['t']['iva8']), $p($g['t']['base0']), $p($g['t']['exento']),
            $p($g['t']['total']), forma_pago_sat($g['id']),
            venta_con_credito($g['id']) ? 'Fiado: va en la factura global del mes en que te lo paguen' : null];
    }
    $renglones[] = [$negrita('Total'), null, null, null, $negrita(count($globales)), null, $negrita($p($suma['base16'])), $negrita($p($suma['iva16'])),
        $negrita($p($suma['base8'])), $negrita($p($suma['iva8'])), $negrita($p($suma['base0'])), $negrita($p($suma['exento'])), $negrita($p($suma['total']))];
    exportar_excel("factura-global-$mes.xlsx", ['Factura global' => [
        'anchos' => [9, 11, 13, 11, 9, 11, 13, 11, 12, 10, 12, 10, 12, 13, 52], 'renglones' => $renglones]]);
}
$titulo  = 'Facturas';
$seccion = 'historial';
require __DIR__ . '/../includes/encabezado.php';
require __DIR__ . '/../includes/pestanas_ventas.php';
?>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header flex-wrap gap-2">
                <ul class="nav nav-pills gap-1">
                    <?php foreach (ESTADOS_FACTURA as $clave => [$etiqueta]): ?>
                        <li class="nav-item"><a class="nav-link py-1<?= $estado === $clave ? ' active' : '' ?>" href="?estado=<?= e($clave) ?>">
                            <?= e($etiqueta) ?> <span class="badge rounded-pill text-bg-light border"><?= (int) ($cuenta[$clave] ?? 0) ?></span></a></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($facturas): ?>
                    <a class="btn btn-sm btn-light border" href="?estado=<?= e($estado) ?>&descargar=facturas"><i class="bi bi-download me-1"></i>Descargar para el contador</a>
                <?php endif; ?>
            </div>
            <?php if (!$facturas): ?>
                <div class="estado-vacio"><i class="bi bi-receipt-cutoff"></i>
                    <?= $estado === 'pendiente' ? 'No hay facturas por hacer. Para pedir una, abre la venta y dale a "Pedir factura".' : 'No hay facturas aquí.' ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead><tr><th>Ticket</th><th>Cliente</th><th class="text-end">Sin IVA</th><th class="text-end">IVA</th><th class="text-end">Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($facturas as $f): ?>
                            <tr class="fila-enlace" data-href="<?= e(url('ventas/factura.php?id=' . (int) $f['venta_id'])) ?>">
                                <td class="num"><a href="<?= e(url('ventas/factura.php?id=' . (int) $f['venta_id'])) ?>" class="fw-semibold">#<?= e(folio($f['venta_id'])) ?></a>
                                    <div class="small text-body-secondary"><?= e(fecha_fmt($f['fecha_venta'])) ?></div></td>
                                <td><?= e($f['razon_social']) ?><div class="small text-body-secondary num"><?= e($f['rfc']) ?>
                                    <?= $f['estado_venta'] === 'cancelada' ? ' · <span class="text-danger">venta cancelada</span>' : '' ?>
                                    <?= $f['estado'] === 'hecha' && $f['enviada_en'] ? ' · <i class="bi bi-envelope-check text-success"></i> mandada' : '' ?>
                                    <?= $f['estado'] === 'cancelada' && $f['motivo_cancelacion'] && !$f['cancelada_sat'] ? ' · <span class="badge text-bg-danger">falta cancelarla en el SAT</span>' : '' ?></div></td>
                                <td class="text-end num"><?= dinero($f['totales']['base'] / 100) ?></td>
                                <td class="text-end num"><?= dinero(($f['totales']['iva16'] + $f['totales']['iva8']) / 100) ?></td>
                                <td class="text-end num fw-bold"><?= dinero($f['totales']['total'] / 100) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><h2><i class="bi bi-collection me-2 text-primary"></i>Factura global</h2></div>
            <div class="card-body">
                <form method="get" class="mb-3">
                    <label for="mes" class="form-label small mb-1">Mes</label>
                    <div class="input-group">
                        <input type="month" class="form-control" id="mes" name="mes" value="<?= e($mes) ?>" max="<?= e(date('Y-m')) ?>">
                        <input type="hidden" name="estado" value="<?= e($estado) ?>">
                        <button class="btn btn-light border" type="submit">Ver</button>
                    </div>
                </form>
                <p class="small text-body-secondary">Las ventas a "público en general" (sin factura propia) se juntan en una factura global cada mes.</p>
                <dl class="row small mb-3">
                    <dt class="col-7">Tickets</dt><dd class="col-5 text-end num"><?= count($globales) ?></dd>
                    <dt class="col-7">Sin IVA</dt><dd class="col-5 text-end num"><?= dinero($totalGlobal['base'] / 100) ?></dd>
                    <dt class="col-7">IVA</dt><dd class="col-5 text-end num"><?= dinero($ivaGlobal / 100) ?></dd>
                    <dt class="col-7 fs-6">Total</dt><dd class="col-5 text-end num fw-bold fs-6"><?= dinero($totalGlobal['total'] / 100) ?></dd>
                </dl>
                <?php if ($globales): ?>
                    <a class="btn btn-primary w-100" href="?mes=<?= e($mes) ?>&estado=<?= e($estado) ?>&descargar=global"><i class="bi bi-download me-1"></i>Descargar para el contador</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/pie.php'; ?>
