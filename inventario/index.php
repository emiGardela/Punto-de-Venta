<?php
/**
 * Inventario: historial de movimientos (kardex) con filtros.
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('inventario');

$productoId = filter_var($_GET['producto'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$q     = trim((string) ($_GET['q'] ?? ''));
$tipo  = array_key_exists($_GET['tipo'] ?? '', TIPOS_MOVIMIENTO_INVENTARIO) ? $_GET['tipo'] : '';
$desde = fecha_valida($_GET['desde'] ?? '');
$hasta = fecha_valida($_GET['hasta'] ?? '');

$where = [];
$params = [];
if ($productoId) {
    $where[] = 'm.producto_id = :producto';
    $params[':producto'] = $productoId;
}
if ($q !== '') {
    $where[] = "(sin_acentos(p.nombre) LIKE sin_acentos(:q) ESCAPE '\\' OR p.codigo_barras LIKE :q2 ESCAPE '\\')";
    $params[':q'] = like($q);
    $params[':q2'] = like($q);
}
if ($tipo !== '') {
    $where[] = 'm.tipo = :tipo';
    $params[':tipo'] = $tipo;
}
if ($desde || $hasta) {
    [$inicio, $fin, $desde, $hasta] = rango_sql($desde ?? $hasta, $hasta ?? $desde);
    $where[] = 'm.fecha >= :inicio AND m.fecha < :fin';
    $params[':inicio'] = $inicio;
    $params[':fin'] = $fin;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = db()->prepare("SELECT COUNT(*) FROM movimientos_inventario m JOIN productos p ON p.id = m.producto_id $whereSql");
$st->execute($params);
$pag = paginar((int) $st->fetchColumn(), 25);

$st = db()->prepare("SELECT m.*, p.nombre, p.codigo_barras FROM movimientos_inventario m JOIN productos p ON p.id = m.producto_id
                     $whereSql ORDER BY m.id DESC LIMIT {$pag['por_pagina']} OFFSET {$pag['offset']}");
$st->execute($params);
$movs = $st->fetchAll();

$producto = null;
if ($productoId) {
    $st = db()->prepare('SELECT id, nombre, existencia FROM productos WHERE id = :id');
    $st->execute([':id' => $productoId]);
    $producto = $st->fetch() ?: null;
}
$bajos = (int) db()->query('SELECT COUNT(*) FROM productos WHERE activo = 1 AND controlar_stock = 1 AND existencia <= stock_minimo')->fetchColumn();

$titulo  = 'Inventario';
$seccion = 'inventario';
require __DIR__ . '/../includes/encabezado.php';
?>
<div class="d-flex flex-wrap gap-2 mb-4">
    <?php if (funciones_avanzadas()): ?>
        <a href="<?= e(url('compras/nueva.php')) ?>" class="btn btn-primary btn-lg"><i class="bi bi-box-arrow-in-down me-1"></i>Registrar compra</a>
        <a href="<?= e(url('inventario/entrada.php?modo=entrada')) ?>" class="btn btn-light border btn-lg"><i class="bi bi-plus-square me-1"></i>Entrada rápida</a>
    <?php else: ?>
        <a href="<?= e(url('inventario/entrada.php?modo=entrada')) ?>" class="btn btn-primary btn-lg"><i class="bi bi-plus-square me-1"></i>Llegó mercancía</a>
    <?php endif; ?>
    <a href="<?= e(url('inventario/entrada.php?modo=conteo')) ?>" class="btn btn-light border btn-lg"><i class="bi bi-clipboard-check me-1"></i>Conteo físico</a>
    <?php if (permiso('productos')): ?>
        <a href="<?= e(url('productos/index.php?filtro=bajo')) ?>" class="btn btn-light border btn-lg">
            <i class="bi bi-exclamation-triangle me-1 text-warning"></i>Por agotarse <span class="badge text-bg-warning"><?= $bajos ?></span>
        </a>
    <?php endif; ?>
</div>

<div class="card">
    <form class="barra-filtros" method="get">
        <?php if ($productoId): ?>
            <input type="hidden" name="producto" value="<?= (int) $productoId ?>">
            <div class="mb-2"><span class="badge text-bg-light border fs-6"><i class="bi bi-box-seam me-1"></i><?= e($producto['nombre'] ?? '#' . $productoId) ?>
                <?= $producto ? ' · hay ' . e(cantidad_fmt($producto['existencia'])) : '' ?></span>
                <a class="small ms-2" href="<?= e(url('inventario/index.php')) ?>">Ver todos</a></div>
        <?php endif; ?>
        <div class="row g-2 align-items-end">
            <?php if (!$productoId): ?>
                <div class="col-md-3">
                    <label for="q" class="form-label small mb-1">Producto</label>
                    <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>" placeholder="Nombre o código">
                </div>
            <?php endif; ?>
            <div class="col-6 col-md-3">
                <label for="tipo" class="form-label small mb-1">Movimiento</label>
                <select class="form-select" id="tipo" name="tipo">
                    <option value="">Todos</option>
                    <?php foreach (TIPOS_MOVIMIENTO_INVENTARIO as $clave => [$etiqueta]): ?>
                        <option value="<?= e($clave) ?>" <?= $tipo === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="desde" class="form-label small mb-1">Desde</label>
                <input type="date" class="form-control" id="desde" name="desde" value="<?= e($desde ?? '') ?>">
            </div>
            <div class="col-6 col-md-2">
                <label for="hasta" class="form-label small mb-1">Hasta</label>
                <input type="date" class="form-control" id="hasta" name="hasta" value="<?= e($hasta ?? '') ?>">
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-funnel me-1"></i>Filtrar</button>
                <a class="btn btn-light border" href="<?= e(url('inventario/index.php')) ?>" title="Limpiar"><i class="bi bi-x-lg"></i></a>
            </div>
        </div>
    </form>

    <?php if (!$movs): ?>
        <div class="estado-vacio"><i class="bi bi-boxes"></i>No hay movimientos que coincidan.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr><th>Fecha</th><th>Producto</th><th>Movimiento</th><th class="text-end">Cantidad</th><th class="text-end">Quedó</th><th>Detalle</th></tr></thead>
                <tbody>
                <?php foreach ($movs as $m): [$etiqueta, $icono, $color] = TIPOS_MOVIMIENTO_INVENTARIO[$m['tipo']]; $c = (float) $m['cantidad']; ?>
                    <tr>
                        <td class="num small"><?= e(fecha_fmt($m['fecha'])) ?></td>
                        <td><a href="?producto=<?= (int) $m['producto_id'] ?>" class="fw-semibold text-body"><?= e($m['nombre']) ?></a></td>
                        <td><span class="badge rounded-pill text-bg-<?= e($color) ?>-subtle text-<?= e($color) ?>-emphasis"><i class="bi <?= e($icono) ?> me-1"></i><?= e($etiqueta) ?></span></td>
                        <td class="text-end num fw-semibold <?= $c < 0 ? 'text-danger' : 'text-success' ?>"><?= $c > 0 ? '+' : '' ?><?= e(cantidad_fmt($c)) ?></td>
                        <td class="text-end num"><?= e(cantidad_fmt($m['existencia_final'])) ?></td>
                        <td class="small text-body-secondary">
                            <?php if ($m['venta_id']): ?><a href="<?= e(url('ventas/detalle.php?id=' . (int) $m['venta_id'])) ?>">Venta #<?= e(folio($m['venta_id'])) ?></a><?php endif; ?>
                            <?= $m['costo'] !== null ? ' Costo ' . e(dinero($m['costo'])) : '' ?>
                            <?= $m['nota'] ? ' ' . e($m['nota']) : '' ?>
                            <?= $m['usuario_id'] ? ' · ' . e(nombre_usuario((int) $m['usuario_id'])) : '' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= paginacion_html($pag) ?>
</div>
<?php require __DIR__ . '/../includes/pie.php'; ?>
