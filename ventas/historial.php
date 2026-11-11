<?php
// SPDX-License-Identifier: GPL-3.0-only
require_once __DIR__ . '/../includes/app.php';

$hoy = date('Y-m-d');

// Atajos de periodo.
$periodo = $_GET['periodo'] ?? '';
if ($periodo === 'hoy') {
    $_GET['desde'] = $_GET['hasta'] = $hoy;
} elseif ($periodo === 'ayer') {
    $_GET['desde'] = $_GET['hasta'] = date('Y-m-d', strtotime('-1 day'));
} elseif ($periodo === 'semana') {
    $_GET['desde'] = date('Y-m-d', strtotime('-6 days'));
    $_GET['hasta'] = $hoy;
} elseif ($periodo === 'mes') {
    $_GET['desde'] = date('Y-m-01');
    $_GET['hasta'] = $hoy;
}
unset($_GET['periodo']);

$folioTxt = trim((string) ($_GET['folio'] ?? ''));
$folio    = $folioTxt !== '' ? filter_var(ltrim($folioTxt, '#0') ?: '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
$desde    = fecha_valida($_GET['desde'] ?? '');
$hasta    = fecha_valida($_GET['hasta'] ?? '');
$metodo   = array_key_exists($_GET['metodo'] ?? '', METODOS_PAGO) ? $_GET['metodo'] : '';
$estado   = in_array($_GET['estado'] ?? '', ['completada', 'cancelada'], true) ? $_GET['estado'] : '';
$turnoId  = filter_var($_GET['turno'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$clienteId = filter_var($_GET['cliente'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

$where  = [];
$params = [];
if ($turnoId) {
    $where[] = 'v.turno_id = :turno';
    $params[':turno'] = $turnoId;
}
if ($clienteId) {
    $where[] = 'v.cliente_id = :cliente';
    $params[':cliente'] = $clienteId;
}
if ($folioTxt !== '') {
    $where[] = 'v.id = :folio';
    $params[':folio'] = $folio ?: 0;
}
if ($desde || $hasta) {
    [$inicio, $fin, $desde, $hasta] = rango_sql($desde ?? $hasta, $hasta ?? $desde);
    $where[] = 'v.fecha >= :inicio AND v.fecha < :fin';
    $params[':inicio'] = $inicio;
    $params[':fin']    = $fin;
}
if ($metodo !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM pagos p WHERE p.venta_id = v.id AND p.metodo = :metodo)';   // incluye pagos divididos
    $params[':metodo'] = $metodo;
}
if ($estado !== '') {
    $where[] = 'v.estado = :estado';
    $params[':estado'] = $estado;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Resumen del filtro (las canceladas se cuentan aparte, nunca en el total vendido).
$st = db()->prepare("SELECT COUNT(*) AS registros,
        SUM(v.estado = 'completada') AS completadas,
        COALESCE(SUM(CASE WHEN v.estado = 'completada' THEN v.total END), 0) AS vendido,
        SUM(v.estado = 'cancelada') AS canceladas,
        COALESCE(SUM(CASE WHEN v.estado = 'cancelada' THEN v.total END), 0) AS cancelado
    FROM ventas v $whereSql");
$st->execute($params);
$resumen = $st->fetch();

// Exportar a Excel (CSV) con los mismos filtros: una fila por venta con su desglose de pago.
if (isset($_GET['exportar'])) {
    $st = db()->prepare("SELECT v.*, c.nombre AS cliente,
            (SELECT group_concat(p.metodo || ' ' || printf('%.2f', p.monto), ' + ') FROM pagos p WHERE p.venta_id = v.id) AS pagos
        FROM ventas v LEFT JOIN clientes c ON c.id = v.cliente_id $whereSql ORDER BY v.id");
    $st->execute($params);
    exportar_csv('ventas-' . date('Y-m-d') . '.csv',
        ['folio', 'fecha', 'estado', 'subtotal', 'descuento', 'total', 'iva', 'forma_de_pago', 'desglose_pago', 'recibido', 'cambio',
         'cliente', 'cajero', 'turno', 'motivo_cancelacion'],
        array_map(fn($v) => [folio($v['id']), $v['fecha'], $v['estado'], $v['subtotal'], $v['descuento'], $v['total'], $v['iva'],
            metodo_pago_etiqueta($v['metodo_pago']), $v['pagos'], $v['recibido'], $v['cambio'], $v['cliente'],
            $v['usuario_id'] ? nombre_usuario((int) $v['usuario_id']) : '', $v['turno_id'], $v['motivo_cancelacion']], $st->fetchAll()));
}

$pag = paginar((int) $resumen['registros']);

$st = db()->prepare("SELECT v.id, v.fecha, v.total, v.metodo_pago, v.estado, v.usuario_id, c.nombre AS cliente,
        (SELECT COUNT(*) FROM detalle_ventas d WHERE d.venta_id = v.id) AS renglones
    FROM ventas v LEFT JOIN clientes c ON c.id = v.cliente_id $whereSql
    ORDER BY v.id DESC
    LIMIT {$pag['por_pagina']} OFFSET {$pag['offset']}");
$st->execute($params);
$ventas = $st->fetchAll();

$hayFiltros = $folioTxt !== '' || $desde || $hasta || $metodo !== '' || $estado !== '' || $turnoId || $clienteId;

$titulo  = 'Ventas hechas';
$seccion = 'historial';
require __DIR__ . '/../includes/encabezado.php';
require __DIR__ . '/../includes/pestanas_ventas.php';
?>

<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card tarjeta-stat h-100"><div class="card-body d-flex align-items-center gap-3">
            <span class="stat-icono icono-azul"><i class="bi bi-cash-stack"></i></span>
            <div><div class="stat-valor num"><?= dinero($resumen['vendido']) ?></div><div class="stat-etiqueta">Vendido (completadas)</div></div>
        </div></div>
    </div>
    <div class="col-sm-4">
        <div class="card tarjeta-stat h-100"><div class="card-body d-flex align-items-center gap-3">
            <span class="stat-icono icono-verde"><i class="bi bi-receipt"></i></span>
            <div><div class="stat-valor num"><?= (int) $resumen['completadas'] ?></div><div class="stat-etiqueta">Ventas completadas</div></div>
        </div></div>
    </div>
    <div class="col-sm-4">
        <div class="card tarjeta-stat h-100"><div class="card-body d-flex align-items-center gap-3">
            <span class="stat-icono icono-rojo"><i class="bi bi-x-circle"></i></span>
            <div><div class="stat-valor num"><?= (int) $resumen['canceladas'] ?></div><div class="stat-etiqueta">Canceladas (<?= dinero($resumen['cancelado']) ?>)</div></div>
        </div></div>
    </div>
</div>

<div class="card">
    <form class="barra-filtros" method="get">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach (['hoy' => 'Hoy', 'ayer' => 'Ayer', 'semana' => 'Últimos 7 días', 'mes' => 'Este mes'] as $clave => $etiqueta): ?>
                <a class="btn btn-sm btn-light border" href="?periodo=<?= e($clave) ?>"><i class="bi bi-calendar-event me-1"></i><?= e($etiqueta) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label for="folio" class="form-label small mb-1">Folio</label>
                <input type="search" class="form-control" id="folio" name="folio" value="<?= e($folioTxt) ?>" placeholder="Ej. 15" inputmode="numeric">
            </div>
            <div class="col-6 col-md-2">
                <label for="desde" class="form-label small mb-1">Desde</label>
                <input type="date" class="form-control" id="desde" name="desde" value="<?= e($desde ?? '') ?>" max="<?= e($hoy) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label for="hasta" class="form-label small mb-1">Hasta</label>
                <input type="date" class="form-control" id="hasta" name="hasta" value="<?= e($hasta ?? '') ?>" max="<?= e($hoy) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label for="metodo" class="form-label small mb-1">Método</label>
                <select class="form-select" id="metodo" name="metodo">
                    <option value="">Todos</option>
                    <?php foreach (METODOS_PAGO as $clave => $m): ?>
                        <option value="<?= e($clave) ?>" <?= $metodo === $clave ? 'selected' : '' ?>><?= e($m['etiqueta']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="estado" class="form-label small mb-1">Estado</label>
                <select class="form-select" id="estado" name="estado">
                    <option value="">Todos</option>
                    <option value="completada" <?= $estado === 'completada' ? 'selected' : '' ?>>Completadas</option>
                    <option value="cancelada" <?= $estado === 'cancelada' ? 'selected' : '' ?>>Canceladas</option>
                </select>
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-funnel me-1"></i>Filtrar</button>
                <a class="btn btn-light border" href="<?= e(url('ventas/historial.php')) ?>" title="Limpiar filtros"><i class="bi bi-x-lg"></i></a>
                <a class="btn btn-light border" href="?<?= e(http_build_query(array_merge($_GET, ['exportar' => 1]))) ?>" title="Exportar a Excel"><i class="bi bi-download"></i></a>
            </div>
        </div>
        <?php if ($turnoId): ?><input type="hidden" name="turno" value="<?= (int) $turnoId ?>"><?php endif; ?>
        <?php if ($clienteId): ?><input type="hidden" name="cliente" value="<?= (int) $clienteId ?>"><?php endif; ?>
        <?php if ($turnoId || $clienteId): ?>
            <div class="small mt-2">
                <?php if ($turnoId): ?><span class="badge text-bg-light border me-1"><i class="bi bi-cash-coin me-1"></i>Turno #<?= (int) $turnoId ?></span><?php endif; ?>
                <?php if ($clienteId): ?><span class="badge text-bg-light border me-1"><i class="bi bi-person me-1"></i>Cliente #<?= (int) $clienteId ?></span><?php endif; ?>
                <a href="<?= e(url('ventas/historial.php')) ?>">Quitar</a>
            </div>
        <?php endif; ?>
        <?php if ($desde || $hasta): ?>
            <div class="small text-body-secondary mt-2"><i class="bi bi-info-circle me-1"></i>Periodo: <?= e(fecha_fmt($desde, false)) ?> al <?= e(fecha_fmt($hasta, false)) ?></div>
        <?php endif; ?>
    </form>

    <?php if (!$ventas): ?>
        <div class="estado-vacio">
            <i class="bi bi-receipt"></i>
            <?= $hayFiltros ? 'No hay ventas que coincidan con los filtros.' : 'Todavía no se ha registrado ninguna venta.' ?>
            <div class="mt-3"><a href="<?= e(url('ventas/index.php')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-cart-plus me-1"></i>Ir al punto de venta</a></div>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>Folio</th>
                    <th>Fecha y hora</th>
                    <th class="text-center">Productos</th>
                    <th>Método de pago</th>
                    <th>Estado</th>
                    <th class="text-end">Total</th>
                    <th class="text-end"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($ventas as $v): ?>
                    <tr class="fila-enlace<?= $v['estado'] === 'cancelada' ? ' fila-inactiva' : '' ?>" data-href="<?= e(url('ventas/detalle.php?id=' . (int) $v['id'])) ?>">
                        <td class="fw-semibold num">#<?= e(folio($v['id'])) ?></td>
                        <td class="num"><?= e(fecha_fmt($v['fecha'])) ?></td>
                        <td class="text-center"><?= (int) $v['renglones'] ?></td>
                        <td>
                            <?= metodo_pago_badge($v['metodo_pago']) ?>
                            <?php if ($v['cliente'] || $v['usuario_id']): ?>
                                <div class="small text-body-secondary mt-1">
                                    <?= $v['cliente'] ? '<i class="bi bi-person me-1"></i>' . e($v['cliente']) : '' ?>
                                    <?= $v['usuario_id'] ? '<span class="ms-1"><i class="bi bi-person-badge me-1"></i>' . e(nombre_usuario((int) $v['usuario_id'])) . '</span>' : '' ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= estado_venta_badge($v['estado']) ?></td>
                        <td class="text-end fw-bold num<?= $v['estado'] === 'cancelada' ? ' text-decoration-line-through' : '' ?>"><?= dinero($v['total']) ?></td>
                        <td class="text-end"><a href="<?= e(url('ventas/detalle.php?id=' . (int) $v['id'])) ?>" class="btn btn-sm btn-light border" title="Ver detalle"><i class="bi bi-eye"></i></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= paginacion_html($pag) ?>
</div>

<?php require __DIR__ . '/../includes/pie.php'; ?>
