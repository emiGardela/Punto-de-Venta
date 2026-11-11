<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Reportes: ventas por periodo, métodos de pago, productos más vendidos,
 * utilidad bruta estimada e inventario. Las ventas canceladas nunca se
 * suman a los totales de ventas completadas; las devoluciones se restan el
 * día en que se hicieron.
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('reportes');

$hoy = date('Y-m-d');
$atajos = [
    'hoy'    => ['Hoy', $hoy, $hoy],
    'semana' => ['Últimos 7 días', date('Y-m-d', strtotime('-6 days')), $hoy],
    'mes'    => ['Este mes', date('Y-m-01'), $hoy],
    'pasado' => ['Mes pasado', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
];
$periodo = $_GET['periodo'] ?? '';
if (isset($atajos[$periodo])) {
    [, $desde, $hasta] = $atajos[$periodo];
} else {
    $desde = fecha_valida($_GET['desde'] ?? '') ?? $hoy;
    $hasta = fecha_valida($_GET['hasta'] ?? '') ?? $desde;
}
[$inicio, $fin, $desde, $hasta] = rango_sql($desde, $hasta);
$rango = [':inicio' => $inicio, ':fin' => $fin];

$pdo = db();

// 1. Resumen de ventas.
$st = $pdo->prepare("SELECT
        SUM(estado = 'completada') AS operaciones,
        COALESCE(SUM(CASE WHEN estado = 'completada' THEN total END), 0) AS ingresos,
        SUM(estado = 'cancelada') AS canceladas,
        COALESCE(SUM(CASE WHEN estado = 'cancelada' THEN total END), 0) AS cancelado
    FROM ventas WHERE fecha >= :inicio AND fecha < :fin");
$st->execute($rango);
$resumen = $st->fetch();
$operaciones = (int) $resumen['operaciones'];
$ingresos    = (float) $resumen['ingresos'];

// 2. Por método de pago.
// Se suman los pagos: una venta dividida cuenta en cada método que usó.
$st = $pdo->prepare("SELECT p.metodo, COUNT(DISTINCT p.venta_id) AS operaciones, SUM(p.monto) AS total
    FROM pagos p JOIN ventas v ON v.id = p.venta_id
    WHERE v.estado = 'completada' AND v.fecha >= :inicio AND v.fecha < :fin
    GROUP BY p.metodo");
$st->execute($rango);
$porMetodoBd = [];
foreach ($st->fetchAll() as $fila) {
    $porMetodoBd[$fila['metodo']] = $fila;
}

// IVA incluido en las ventas del periodo (para el contador).
$st = $pdo->prepare("SELECT COALESCE(SUM(iva), 0) FROM ventas WHERE estado = 'completada' AND fecha >= :inicio AND fecha < :fin");
$st->execute($rango);
$ivaPeriodo = (float) $st->fetchColumn();
$porMetodo = [];
foreach (METODOS_PAGO as $clave => $m) {
    $porMetodo[$clave] = [
        'etiqueta'    => $m['etiqueta'],
        'operaciones' => (int) ($porMetodoBd[$clave]['operaciones'] ?? 0),
        'total'       => (float) ($porMetodoBd[$clave]['total'] ?? 0),
    ];
}

// 3. Utilidad bruta estimada (solo partidas con costo conocido).
$st = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN d.costo_unitario IS NOT NULL THEN d.subtotal - d.descuento END), 0) AS ingresos_con_costo,
        COALESCE(SUM(CASE WHEN d.costo_unitario IS NOT NULL THEN ROUND(d.costo_unitario * d.cantidad, 2) END), 0) AS costo,
        COALESCE(SUM(CASE WHEN d.costo_unitario IS NULL THEN d.subtotal - d.descuento END), 0) AS ingresos_sin_costo
    FROM detalle_ventas d JOIN ventas v ON v.id = d.venta_id
    WHERE v.estado = 'completada' AND v.fecha >= :inicio AND v.fecha < :fin");
$st->execute($rango);
$util = $st->fetch();
$ingresosConCosto = (float) $util['ingresos_con_costo'];
$costoVentas      = (float) $util['costo'];
// Devoluciones del periodo: restan lo reembolsado; si el artículo regresó al anaquel, su costo se recupera
// (si venía dañado, el costo se pierde y baja la utilidad).
$st = $pdo->prepare("SELECT COUNT(DISTINCT d.id) AS n, COALESCE(SUM(dp.importe), 0) AS total,
        COALESCE(SUM(CASE WHEN dp.costo_unitario IS NOT NULL THEN dp.importe END), 0) AS con_costo,
        COALESCE(SUM(CASE WHEN dp.costo_unitario IS NOT NULL AND dp.regresa_inventario = 1 THEN ROUND(dp.costo_unitario * dp.cantidad, 2) END), 0) AS costo_recuperado
    FROM devolucion_productos dp JOIN devoluciones d ON d.id = dp.devolucion_id JOIN ventas v ON v.id = d.venta_id
    WHERE v.estado = 'completada' AND d.fecha >= :inicio AND d.fecha < :fin");
$st->execute($rango);
$dev = $st->fetch();
$devuelto          = (float) $dev['total'];
$ingresosNetos     = $ingresos - $devuelto;
$ingresosConCosto -= (float) $dev['con_costo'];
$costoVentas      -= (float) $dev['costo_recuperado'];
$util['ingresos_sin_costo'] = (float) $util['ingresos_sin_costo'] - ($devuelto - (float) $dev['con_costo']);
$utilidad         = $ingresosConCosto - $costoVentas;
$margen           = $ingresosConCosto > 0 ? $utilidad / $ingresosConCosto * 100 : 0;
$cobertura        = $ingresosNetos > 0 ? $ingresosConCosto / $ingresosNetos * 100 : 0;

// 4. Productos más vendidos.
$st = $pdo->prepare("SELECT MAX(d.nombre_producto) AS nombre, d.producto_id,
        SUM(d.cantidad) AS cantidad, SUM(d.subtotal - d.descuento) AS importe, COUNT(DISTINCT d.venta_id) AS ventas
    FROM detalle_ventas d JOIN ventas v ON v.id = d.venta_id
    WHERE v.estado = 'completada' AND v.fecha >= :inicio AND v.fecha < :fin
    GROUP BY d.producto_id, CASE WHEN d.producto_id IS NULL THEN d.nombre_producto END
    ORDER BY cantidad DESC, importe DESC
    LIMIT 10");
$st->execute($rango);
$masVendidos = $st->fetchAll();

// 5. Ventas por día.
$st = $pdo->prepare("SELECT DATE(fecha) AS dia, COUNT(*) AS operaciones, SUM(total) AS total
    FROM ventas WHERE estado = 'completada' AND fecha >= :inicio AND fecha < :fin
    GROUP BY DATE(fecha) ORDER BY dia");
$st->execute($rango);
$porDia = $st->fetchAll();

// 6. Existencias actuales y valor del inventario.
$inventario = $pdo->query("SELECT id, nombre, codigo_barras, existencia, stock_minimo, precio, costo,
        CASE WHEN costo IS NOT NULL THEN ROUND(existencia * costo, 2) END AS valor_costo,
        ROUND(existencia * precio, 2) AS valor_venta
    FROM productos WHERE activo = 1 AND controlar_stock = 1
    ORDER BY sin_acentos(nombre)")->fetchAll();
$valorCosto = 0.0;
$valorVenta = 0.0;
$sinCosto   = 0;
foreach ($inventario as $p) {
    $valorVenta += (float) $p['valor_venta'];
    if ($p['valor_costo'] === null) {
        $sinCosto++;
    } else {
        $valorCosto += (float) $p['valor_costo'];
    }
}

// 7. Por cajero, descuentos y crédito.
$st = $pdo->prepare("SELECT v.usuario_id, COUNT(*) AS operaciones, SUM(v.total) AS total, SUM(v.descuento) AS descuentos
    FROM ventas v WHERE v.estado = 'completada' AND v.fecha >= :inicio AND v.fecha < :fin
    GROUP BY v.usuario_id ORDER BY total DESC");
$st->execute($rango);
$porCajero = $st->fetchAll();
$descuentosPeriodo = array_sum(array_map(fn($c) => (float) $c['descuentos'], $porCajero));

$st = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) FROM abonos WHERE estado = 'aplicado' AND fecha >= :inicio AND fecha < :fin");
$st->execute($rango);
$abonosPeriodo = (float) $st->fetchColumn();
$fiadoPeriodo  = $porMetodo['credito']['total'] ?? 0;
$deudaTotal    = (float) $pdo->query('SELECT COALESCE(SUM(' . SQL_SALDO_CLIENTE . '), 0) FROM clientes c')->fetchColumn();

// Exportar a Excel (CSV): resumen del periodo, por día, por método, más vendidos o inventario.
$exportar = (string) ($_GET['exportar'] ?? '');
$sufijo = $desde === $hasta ? $desde : "$desde-al-$hasta";
if ($exportar === 'dias') {
    exportar_csv("ventas-por-dia-$sufijo.csv", ['dia', 'operaciones', 'total'],
        array_map(fn($d) => [$d['dia'], $d['operaciones'], $d['total']], $porDia));
} elseif ($exportar === 'metodos') {
    exportar_csv("ventas-por-metodo-$sufijo.csv", ['metodo', 'operaciones', 'total'],
        array_map(fn($m) => [$m['etiqueta'], $m['operaciones'], $m['total']], $porMetodo));
} elseif ($exportar === 'productos') {
    exportar_csv("mas-vendidos-$sufijo.csv", ['producto', 'cantidad', 'importe', 'ventas'],
        array_map(fn($p) => [$p['nombre'], $p['cantidad'], $p['importe'], $p['ventas']], $masVendidos));
} elseif ($exportar === 'inventario') {
    exportar_csv('inventario-' . date('Y-m-d') . '.csv', ['producto', 'codigo_barras', 'existencia', 'minimo', 'precio', 'costo', 'valor_a_costo', 'valor_a_precio'],
        array_map(fn($p) => [$p['nombre'], $p['codigo_barras'], $p['existencia'], $p['stock_minimo'], $p['precio'], $p['costo'],
            $p['valor_costo'], $p['valor_venta']], $inventario));
} elseif ($exportar === 'resumen') {
    exportar_csv("resumen-$sufijo.csv", ['concepto', 'importe'], [
        ['Ventas', $ingresos], ['Devoluciones', $devuelto], ['Ingresos netos', $ingresosNetos], ['Operaciones', $operaciones], ['IVA incluido', $ivaPeriodo],
        ['Ingresos sin IVA', $ingresos - $ivaPeriodo], ['Costo de lo vendido (con costo conocido)', $costoVentas],
        ['Utilidad bruta estimada', $utilidad], ['Descuentos dados', $descuentosPeriodo], ['Ventas canceladas', $resumen['canceladas']],
        ['Fiado en el periodo', $fiadoPeriodo], ['Abonos cobrados', $abonosPeriodo], ['Te deben hoy', $deudaTotal],
    ]);
}

$grafica = [
    'dias'    => array_map(fn($d) => date('d/m', strtotime($d['dia'])), $porDia),
    'totales' => array_map(fn($d) => round((float) $d['total'], 2), $porDia),
    'metodos' => array_values(array_map(fn($m) => $m['etiqueta'], $porMetodo)),
    'montos'  => array_values(array_map(fn($m) => round($m['total'], 2), $porMetodo)),
];

$titulo  = 'Reportes';
$seccion = 'reportes';
$scripts = ['assets/vendor/chartjs/chart.umd.min.js'];
require __DIR__ . '/../includes/encabezado.php';
?>

<div class="card mb-4 no-imprimir">
    <form class="card-body" method="get">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach ($atajos as $clave => [$etiqueta, $d, $h]): ?>
                <a href="?periodo=<?= e($clave) ?>" class="btn btn-sm <?= $desde === $d && $hasta === $h ? 'btn-primary' : 'btn-light border' ?>"><?= e($etiqueta) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label for="desde" class="form-label small mb-1">Desde</label>
                <input type="date" class="form-control" id="desde" name="desde" value="<?= e($desde) ?>" max="<?= e($hoy) ?>" required>
            </div>
            <div class="col-6 col-md-3">
                <label for="hasta" class="form-label small mb-1">Hasta</label>
                <input type="date" class="form-control" id="hasta" name="hasta" value="<?= e($hasta) ?>" max="<?= e($hoy) ?>" required>
            </div>
            <div class="col-md-6 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Consultar</button>
                <button type="button" class="btn btn-light border" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button>
                <div class="dropdown">
                    <button class="btn btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-download me-1"></i>Exportar a Excel</button>
                    <ul class="dropdown-menu">
                        <?php foreach (['resumen' => 'Resumen del periodo', 'dias' => 'Ventas por día', 'metodos' => 'Ventas por forma de pago',
                                        'productos' => 'Productos más vendidos', 'inventario' => 'Inventario valorizado'] as $clave => $etiqueta): ?>
                            <li><a class="dropdown-item" href="?<?= e(http_build_query(['desde' => $desde, 'hasta' => $hasta, 'exportar' => $clave])) ?>"><?= e($etiqueta) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </form>
</div>

<h2 class="h5 fw-bold mb-3">
    Periodo: <?= $desde === $hasta ? e(fecha_fmt($desde, false)) : e(fecha_fmt($desde, false)) . ' al ' . e(fecha_fmt($hasta, false)) ?>
</h2>

<!-- Ingresos y utilidad -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card tarjeta-stat h-100"><div class="card-body">
            <span class="stat-icono icono-azul mb-3"><i class="bi bi-cash-stack"></i></span>
            <div class="stat-valor num"><?= dinero($ingresosNetos) ?></div>
            <div class="stat-etiqueta">Ingresos por ventas</div>
            <small class="text-body-secondary"><?php if ($devuelto > 0): ?>Ventas <?= dinero($ingresos) ?> − <?= (int) $dev['n'] ?> devolución(es) <?= dinero($devuelto) ?><?php else: ?>Dinero cobrado en ventas completadas<?php endif; ?></small>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card tarjeta-stat h-100"><div class="card-body">
            <span class="stat-icono icono-verde mb-3"><i class="bi bi-receipt"></i></span>
            <div class="stat-valor num"><?= $operaciones ?></div>
            <div class="stat-etiqueta">Operaciones</div>
            <small class="text-body-secondary">Promedio por venta: <?= dinero($operaciones ? $ingresos / $operaciones : 0) ?></small>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card tarjeta-stat h-100"><div class="card-body">
            <span class="stat-icono icono-morado mb-3"><i class="bi bi-graph-up-arrow"></i></span>
            <div class="stat-valor num <?= $utilidad < 0 ? 'text-danger' : '' ?>"><?= dinero($utilidad) ?></div>
            <div class="stat-etiqueta">Utilidad bruta estimada</div>
            <small class="text-body-secondary">Margen <?= number_format($margen, 1) ?>% sobre lo que tiene costo</small>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card tarjeta-stat h-100"><div class="card-body">
            <span class="stat-icono icono-rojo mb-3"><i class="bi bi-x-circle"></i></span>
            <div class="stat-valor num"><?= (int) $resumen['canceladas'] ?></div>
            <div class="stat-etiqueta">Ventas canceladas</div>
            <small class="text-body-secondary"><?= dinero($resumen['cancelado']) ?> · no se suman a los ingresos</small>
        </div></div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><h2><i class="bi bi-calculator me-2 text-primary"></i>Ingresos contra utilidad</h2></div>
    <div class="card-body">
        <div class="row g-3 text-center">
            <div class="col-6 col-md-3"><div class="small text-body-secondary">Ingresos totales<?= $devuelto > 0 ? ' (sin devoluciones)' : '' ?></div><div class="fs-5 fw-bold num"><?= dinero($ingresosNetos) ?></div></div>
            <div class="col-6 col-md-3"><div class="small text-body-secondary">Ingresos con costo conocido</div><div class="fs-5 fw-bold num"><?= dinero($ingresosConCosto) ?></div></div>
            <div class="col-6 col-md-3"><div class="small text-body-secondary">Costo de lo vendido</div><div class="fs-5 fw-bold num">− <?= dinero($costoVentas) ?></div></div>
            <div class="col-6 col-md-3"><div class="small text-body-secondary">Utilidad bruta estimada</div><div class="fs-5 fw-bold num text-primary"><?= dinero($utilidad) ?></div></div>
        </div>
        <div class="progress mt-3" role="progressbar" aria-label="Cobertura de costos" aria-valuenow="<?= (int) round($cobertura) ?>" aria-valuemin="0" aria-valuemax="100" style="height:8px">
            <div class="progress-bar" style="width: <?= number_format(min(100, $cobertura), 1, '.', '') ?>%"></div>
        </div>
        <p class="small text-body-secondary mt-2 mb-0">
            <i class="bi bi-info-circle me-1"></i>
            Los <strong>ingresos</strong> son el dinero cobrado. La <strong>utilidad</strong> es una estimación: ingresos menos el costo de
            adquisición registrado al momento de cada venta. El <?= number_format($cobertura, 1) ?>% de los ingresos tiene costo conocido;
            <?= dinero($util['ingresos_sin_costo']) ?> provienen de productos o servicios sin costo registrado y no entran en el cálculo de utilidad.
            <?php if ($descuentosPeriodo > 0): ?>Ya se descontaron <?= dinero($descuentosPeriodo) ?> de descuentos.<?php endif; ?>
            Los precios incluyen IVA: en este periodo las ventas llevan <?= dinero($ivaPeriodo) ?> de IVA
            (ventas sin IVA: <?= dinero($ingresos - $ivaPeriodo) ?>). Las ventas anteriores a la versión 3.2 no tienen el IVA desglosado.
        </p>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h2><i class="bi bi-person-badge me-2 text-primary"></i>Ventas por cajero</h2></div>
            <?php if (!$porCajero): ?>
                <div class="card-body text-body-secondary">Sin ventas en este periodo.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Cajero</th><th class="text-end">Ventas</th><th class="text-end">Descuentos</th><th class="text-end">Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($porCajero as $c): ?>
                            <tr>
                                <td><?= $c['usuario_id'] ? e(nombre_usuario((int) $c['usuario_id'])) : '<span class="text-body-secondary">Sin usuario</span>' ?></td>
                                <td class="text-end num"><?= (int) $c['operaciones'] ?></td>
                                <td class="text-end num"><?= dinero($c['descuentos']) ?></td>
                                <td class="text-end num fw-semibold"><?= dinero($c['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h2><i class="bi bi-person-vcard me-2 text-primary"></i>Crédito a clientes</h2></div>
            <div class="card-body">
                <div class="row g-3 text-center">
                    <div class="col-4"><div class="small text-body-secondary">Fiado en el periodo</div><div class="fs-5 fw-bold num"><?= dinero($fiadoPeriodo) ?></div></div>
                    <div class="col-4"><div class="small text-body-secondary">Abonos cobrados</div><div class="fs-5 fw-bold num text-success"><?= dinero($abonosPeriodo) ?></div></div>
                    <div class="col-4"><div class="small text-body-secondary">Te deben hoy</div><div class="fs-5 fw-bold num text-danger"><?= dinero($deudaTotal) ?></div></div>
                </div>
                <p class="small text-body-secondary mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>Las ventas a crédito cuentan en los ingresos del día en que se vendió.
                    Los abonos son el dinero que esos clientes pagaron después.</p>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header"><h2><i class="bi bi-bar-chart me-2 text-primary"></i>Ventas por día</h2></div>
            <div class="card-body">
                <?php if (!$porDia): ?>
                    <div class="estado-vacio"><i class="bi bi-bar-chart"></i>Sin ventas en este periodo.</div>
                <?php else: ?>
                    <div style="height:240px" class="mb-3"><canvas id="graficaDias"></canvas></div>
                    <div class="table-responsive" style="max-height:260px">
                        <table class="table table-sm">
                            <thead><tr><th>Día</th><th class="text-end">Operaciones</th><th class="text-end">Total</th></tr></thead>
                            <tbody>
                            <?php foreach ($porDia as $d): ?>
                                <tr>
                                    <td><a href="<?= e(url('ventas/historial.php?desde=' . $d['dia'] . '&hasta=' . $d['dia'] . '&estado=completada')) ?>"><?= e(fecha_fmt($d['dia'], false)) ?></a></td>
                                    <td class="text-end num"><?= (int) $d['operaciones'] ?></td>
                                    <td class="text-end num fw-semibold"><?= dinero($d['total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header"><h2><i class="bi bi-wallet2 me-2 text-primary"></i>Total por método de pago</h2></div>
            <div class="card-body">
                <?php if ($operaciones > 0): ?>
                    <div style="height:200px" class="mb-3"><canvas id="graficaMetodos"></canvas></div>
                <?php endif; ?>
                <table class="table">
                    <thead><tr><th>Método</th><th class="text-end">Operaciones</th><th class="text-end">Total</th><th class="text-end">%</th></tr></thead>
                    <tbody>
                    <?php foreach ($porMetodo as $clave => $m): ?>
                        <tr>
                            <td><?= metodo_pago_badge($clave) ?></td>
                            <td class="text-end num"><?= $m['operaciones'] ?></td>
                            <td class="text-end num fw-semibold"><?= dinero($m['total']) ?></td>
                            <td class="text-end num text-body-secondary"><?= $ingresos > 0 ? number_format($m['total'] / $ingresos * 100, 1) : '0.0' ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><th>Total</th><th class="text-end num"><?= $operaciones ?></th><th class="text-end num"><?= dinero($ingresos) ?></th><th></th></tr></tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><h2><i class="bi bi-trophy me-2 text-primary"></i>Productos más vendidos</h2></div>
    <?php if (!$masVendidos): ?>
        <div class="estado-vacio"><i class="bi bi-trophy"></i>Sin ventas en este periodo.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>#</th><th>Producto</th><th class="text-end">Cantidad</th><th class="text-end">Ventas</th><th class="text-end">Importe</th></tr></thead>
                <tbody>
                <?php foreach ($masVendidos as $i => $p): ?>
                    <tr>
                        <td class="text-body-secondary"><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= e($p['nombre']) ?></td>
                        <td class="text-end num"><?= e(cantidad_fmt($p['cantidad'])) ?></td>
                        <td class="text-end num"><?= (int) $p['ventas'] ?></td>
                        <td class="text-end num fw-semibold"><?= dinero($p['importe']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2><i class="bi bi-boxes me-2 text-primary"></i>Existencias actuales y valor del inventario</h2>
        <small class="text-body-secondary">Al momento de la consulta · solo productos activos con inventario</small>
    </div>
    <div class="card-body border-bottom">
        <div class="row g-3 text-center">
            <div class="col-md-4"><div class="small text-body-secondary">Valor a costo (estimado)</div><div class="fs-5 fw-bold num"><?= dinero($valorCosto) ?></div></div>
            <div class="col-md-4"><div class="small text-body-secondary">Valor a precio de venta</div><div class="fs-5 fw-bold num"><?= dinero($valorVenta) ?></div></div>
            <div class="col-md-4"><div class="small text-body-secondary">Productos sin costo registrado</div><div class="fs-5 fw-bold num"><?= $sinCosto ?></div></div>
        </div>
    </div>
    <?php if (!$inventario): ?>
        <div class="estado-vacio"><i class="bi bi-boxes"></i>No hay productos que controlen inventario.</div>
    <?php else: ?>
        <div class="table-responsive" style="max-height:480px">
            <table class="table table-sm align-middle">
                <thead class="sticky-top"><tr><th>Producto</th><th class="text-end">Existencia</th><th class="text-end">Costo</th><th class="text-end">Valor a costo</th><th class="text-end">Precio</th><th class="text-end">Valor a precio</th></tr></thead>
                <tbody>
                <?php foreach ($inventario as $p): $bajo = (float) $p['existencia'] <= (float) $p['stock_minimo']; ?>
                    <tr>
                        <td><a class="text-reset" href="<?= e(url('productos/editar.php?id=' . (int) $p['id'])) ?>"><?= e($p['nombre']) ?></a></td>
                        <td class="text-end num"><?= $bajo ? '<span class="badge text-bg-warning">' . e(cantidad_fmt($p['existencia'])) . '</span>' : e(cantidad_fmt($p['existencia'])) ?></td>
                        <td class="text-end num"><?= $p['costo'] !== null ? dinero($p['costo']) : '<span class="text-body-secondary">—</span>' ?></td>
                        <td class="text-end num"><?= $p['valor_costo'] !== null ? dinero($p['valor_costo']) : '<span class="text-body-secondary">—</span>' ?></td>
                        <td class="text-end num"><?= dinero($p['precio']) ?></td>
                        <td class="text-end num"><?= dinero($p['valor_venta']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.Chart) { return; }
    const g = <?= json_encode($grafica, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const lienzoDias = document.getElementById('graficaDias');
    if (lienzoDias) {
        new Chart(lienzoDias, {
            type: 'bar',
            data: { labels: g.dias, datasets: [{ label: 'Ventas', data: g.totales, backgroundColor: '#1d5fd1', borderRadius: 6, maxBarThickness: 40 }] },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => TW.dinero(c.parsed.y) } } },
                scales: { y: { beginAtZero: true, ticks: { callback: (v) => TW.dinero(v).replace('.00', '') } }, x: { grid: { display: false } } }
            }
        });
    }
    const lienzoMetodos = document.getElementById('graficaMetodos');
    if (lienzoMetodos) {
        new Chart(lienzoMetodos, {
            type: 'doughnut',
            data: { labels: g.metodos, datasets: [{ data: g.montos, backgroundColor: ['#16a34a', '#0ea5e9', '#1d5fd1'], borderWidth: 0 }] },
            options: {
                maintainAspectRatio: false, cutout: '62%',
                plugins: { legend: { position: 'right' }, tooltip: { callbacks: { label: (c) => c.label + ': ' + TW.dinero(c.parsed) } } }
            }
        });
    }
});
</script>

<?php require __DIR__ . '/../includes/pie.php'; ?>
