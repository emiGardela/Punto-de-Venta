<?php
/**
 * Compras: lista y sugerido de compra por proveedor.
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('inventario');

$proveedor = filter_var($_GET['proveedor'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$desde = fecha_valida($_GET['desde'] ?? '');
$hasta = fecha_valida($_GET['hasta'] ?? '');
$where = [];
$params = [];
if ($proveedor) {
    $where[] = 'c.proveedor_id = :p';
    $params[':p'] = $proveedor;
}
if ($desde || $hasta) {
    [$inicio, $fin, $desde, $hasta] = rango_sql($desde ?? $hasta, $hasta ?? $desde);
    $where[] = 'c.fecha >= :inicio AND c.fecha < :fin';
    $params[':inicio'] = $inicio;
    $params[':fin'] = $fin;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = db()->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN estado = 'recibida' THEN total END), 0) AS total FROM compras c $whereSql");
$st->execute($params);
$resumen = $st->fetch();
$pag = paginar((int) $resumen['n']);
$st = db()->prepare("SELECT c.*, p.nombre AS proveedor, (SELECT COUNT(*) FROM compra_productos x WHERE x.compra_id = c.id) AS renglones
                     FROM compras c LEFT JOIN proveedores p ON p.id = c.proveedor_id $whereSql
                     ORDER BY c.id DESC LIMIT {$pag['por_pagina']} OFFSET {$pag['offset']}");
$st->execute($params);
$compras = $st->fetchAll();

// Sugerido: cuántos productos por agotarse tiene cada proveedor.
$porAgotarse = db()->query("SELECT p.proveedor_id, pr.nombre, COUNT(*) AS n FROM productos p LEFT JOIN proveedores pr ON pr.id = p.proveedor_id
                            WHERE p.activo = 1 AND p.tipo = 'normal' AND p.controlar_stock = 1 AND p.existencia <= p.stock_minimo
                            GROUP BY p.proveedor_id ORDER BY n DESC")->fetchAll();
$proveedores = db()->query('SELECT id, nombre FROM proveedores ORDER BY sin_acentos(nombre)')->fetchAll(PDO::FETCH_KEY_PAIR);

$titulo  = 'Compras';
$seccion = 'compras';
require __DIR__ . '/../includes/encabezado.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
        <span class="badge rounded-pill text-bg-light border px-3 py-2"><i class="bi bi-receipt me-1"></i><?= (int) $resumen['n'] ?> compra(s)</span>
        <span class="badge rounded-pill text-bg-light border px-3 py-2"><i class="bi bi-cash me-1"></i><?= dinero($resumen['total']) ?> en el periodo</span>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= e(url('proveedores/index.php')) ?>" class="btn btn-light border"><i class="bi bi-truck me-1"></i>Proveedores</a>
        <a href="<?= e(url('compras/nueva.php')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nueva compra</a>
    </div>
</div>

<?php if ($porAgotarse): ?>
    <div class="card mb-4">
        <div class="card-header"><h2><i class="bi bi-magic me-2 text-primary"></i>Sugerido de compra</h2></div>
        <div class="card-body d-flex flex-wrap gap-2">
            <?php foreach ($porAgotarse as $s): ?>
                <a class="btn btn-light border" href="<?= e(url('compras/nueva.php?sugerir=' . (int) $s['proveedor_id'])) ?>">
                    <i class="bi bi-truck me-1"></i><?= e($s['nombre'] ?? 'Sin proveedor asignado') ?>
                    <span class="badge text-bg-warning ms-1"><?= (int) $s['n'] ?> por agotarse</span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <form class="barra-filtros row g-2 align-items-end" method="get">
        <div class="col-md-4">
            <label for="proveedor" class="form-label small mb-1">Proveedor</label>
            <select class="form-select" id="proveedor" name="proveedor">
                <option value="">Todos</option>
                <?php foreach ($proveedores as $pid => $pn): ?><option value="<?= (int) $pid ?>" <?= $proveedor === (int) $pid ? 'selected' : '' ?>><?= e($pn) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3"><label for="desde" class="form-label small mb-1">Desde</label><input type="date" class="form-control" id="desde" name="desde" value="<?= e($desde ?? '') ?>"></div>
        <div class="col-6 col-md-3"><label for="hasta" class="form-label small mb-1">Hasta</label><input type="date" class="form-control" id="hasta" name="hasta" value="<?= e($hasta ?? '') ?>"></div>
        <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-funnel"></i></button>
            <a class="btn btn-light border" href="<?= e(url('compras/index.php')) ?>"><i class="bi bi-x-lg"></i></a></div>
    </form>
    <?php if (!$compras): ?>
        <div class="estado-vacio"><i class="bi bi-truck"></i>No hay compras registradas.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>#</th><th>Fecha</th><th>Proveedor</th><th>Nota</th><th class="text-end">Productos</th><th class="text-end">Total</th><th>Estado</th></tr></thead>
                <tbody>
                <?php foreach ($compras as $c): ?>
                    <tr class="fila-enlace<?= $c['estado'] === 'cancelada' ? ' fila-inactiva' : '' ?>" data-href="<?= e(url('compras/detalle.php?id=' . (int) $c['id'])) ?>">
                        <td class="fw-semibold">#<?= (int) $c['id'] ?></td>
                        <td class="num small"><?= e(fecha_fmt($c['fecha'])) ?></td>
                        <td><?= e($c['proveedor'] ?? '—') ?></td>
                        <td class="small"><?= e($c['folio_proveedor'] ?? '') ?></td>
                        <td class="text-end"><?= (int) $c['renglones'] ?></td>
                        <td class="text-end num fw-semibold"><?= dinero($c['total']) ?></td>
                        <td><?= $c['estado'] === 'cancelada' ? '<span class="badge text-bg-danger-subtle text-danger-emphasis">Cancelada</span>'
                                : ($c['pagada_de_caja'] ? '<span class="badge text-bg-light border">Pagada de caja</span>' : '<span class="badge text-bg-success-subtle text-success-emphasis">Recibida</span>') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= paginacion_html($pag) ?>
</div>
<?php require __DIR__ . '/../includes/pie.php'; ?>
