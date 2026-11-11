<?php
// SPDX-License-Identifier: GPL-3.0-only
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('productos');

$q         = trim((string) ($_GET['q'] ?? ''));
$estado    = in_array($_GET['estado'] ?? '', ['activos', 'inactivos', 'todos'], true) ? $_GET['estado'] : 'activos';
$filtro    = in_array($_GET['filtro'] ?? '', ['bajo', 'servicios', 'inventario'], true) ? $_GET['filtro'] : '';
$categoria = filter_var($_GET['categoria'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$categoria = $categoria === false ? null : $categoria;   // 0 = sin categoría

$where  = [];
$params = [];
if ($estado === 'activos') {
    $where[] = 'p.activo = 1';
} elseif ($estado === 'inactivos') {
    $where[] = 'p.activo = 0';
}
if ($q !== '') {
    $where[] = "(sin_acentos(p.nombre) LIKE sin_acentos(:q_nombre) ESCAPE '\' OR p.codigo_barras LIKE :q_codigo ESCAPE '\'
                 OR p.codigo_interno LIKE :q_interno ESCAPE '\' OR sin_acentos(p.marca) LIKE sin_acentos(:q_marca) ESCAPE '\')";
    $params[':q_nombre'] = $params[':q_codigo'] = $params[':q_interno'] = $params[':q_marca'] = like($q);
}
if ($filtro === 'bajo') {
    $where[] = 'p.controlar_stock = 1 AND p.existencia <= p.stock_minimo';
} elseif ($filtro === 'servicios') {
    $where[] = 'p.controlar_stock = 0';
} elseif ($filtro === 'inventario') {
    $where[] = 'p.controlar_stock = 1';
}
if ($categoria === 0) {
    $where[] = 'p.categoria_id IS NULL';
} elseif ($categoria) {
    $where[] = 'p.categoria_id = :categoria';
    $params[':categoria'] = $categoria;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Exportar a Excel (CSV) con los mismos filtros.
if (isset($_GET['exportar'])) {
    $st = db()->prepare("SELECT p.*, c.nombre AS categoria, pr.nombre AS proveedor FROM productos p
                         LEFT JOIN categorias c ON c.id = p.categoria_id LEFT JOIN proveedores pr ON pr.id = p.proveedor_id
                         $whereSql ORDER BY sin_acentos(p.nombre)");
    $st->execute($params);
    $escalones = escalones_de(array_map(fn($f) => (int) $f['id'], $filas = $st->fetchAll()));
    exportar_csv('productos-' . date('Y-m-d') . '.csv',
        ['nombre', 'codigo_barras', 'codigo_interno', 'categoria', 'marca', 'unidad', 'precio', 'costo', 'existencia', 'minimo',
         'servicio', 'granel', 'iva', 'mayoreo_desde', 'precio_mayoreo', 'proveedor', 'descripcion', 'activo'],
        array_map(function ($p) use ($escalones) {
            $primero = $escalones[(int) $p['id']][0] ?? null;   // el primer escalón de mayoreo
            return [$p['nombre'], $p['codigo_barras'], $p['codigo_interno'], $p['categoria'], $p['marca'], $p['unidad'],
                $p['precio'], $p['costo'], $p['controlar_stock'] ? $p['existencia'] : '', $p['controlar_stock'] ? $p['stock_minimo'] : '',
                $p['controlar_stock'] ? 'no' : 'si', $p['permite_fraccion'] ? 'si' : 'no', $p['tasa_iva'],
                $primero ? $primero[0] / 1000 : '', $primero ? $primero[1] / 100 : '', $p['proveedor'], $p['descripcion'], $p['activo'] ? 'si' : 'no'];
        }, $filas));
}

$st = db()->prepare("SELECT COUNT(*) FROM productos p $whereSql");
$st->execute($params);
$pag = paginar((int) $st->fetchColumn());

$st = db()->prepare("SELECT p.id, p.nombre, p.codigo_barras, p.codigo_interno, p.marca, p.precio, p.costo, p.existencia, p.stock_minimo,
                            p.controlar_stock, p.permite_fraccion, p.activo, p.tipo, c.nombre AS categoria,
                            (SELECT COUNT(*) FROM precios_volumen v WHERE v.producto_id = p.id) AS escalones
                     FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id $whereSql
                     ORDER BY p.activo DESC, sin_acentos(p.nombre) ASC
                     LIMIT {$pag['por_pagina']} OFFSET {$pag['offset']}");
$st->execute($params);
$productos = $st->fetchAll();
$disponibles = disponibilidad(array_map(fn($p) => (int) $p['id'], array_filter($productos, fn($p) => $p['tipo'] !== 'normal')));
$categorias = db()->query('SELECT id, nombre FROM categorias ORDER BY sin_acentos(nombre)')->fetchAll(PDO::FETCH_KEY_PAIR);
$resumen = db()->query("SELECT
        SUM(activo = 1) AS activos,
        SUM(activo = 0) AS inactivos,
        SUM(activo = 1 AND controlar_stock = 1 AND existencia <= stock_minimo) AS bajos
    FROM productos")->fetch();

$titulo  = 'Productos';
$seccion = 'productos';
require __DIR__ . '/../includes/encabezado.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
        <span class="badge rounded-pill text-bg-light border px-3 py-2"><i class="bi bi-check-circle text-success me-1"></i><?= (int) $resumen['activos'] ?> activos</span>
        <span class="badge rounded-pill text-bg-light border px-3 py-2"><i class="bi bi-slash-circle text-secondary me-1"></i><?= (int) $resumen['inactivos'] ?> desactivados</span>
        <a href="?filtro=bajo" class="badge rounded-pill text-bg-light border px-3 py-2 text-decoration-none"><i class="bi bi-exclamation-triangle text-warning me-1"></i><?= (int) $resumen['bajos'] ?> con existencia baja</a>
    </div>
    <div class="d-flex gap-2">
        <div class="dropdown">
            <button class="btn btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-three-dots me-1"></i>Más</button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="<?= e(url('productos/importar.php')) ?>"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Importar de Excel</a></li>
                <li><a class="dropdown-item" href="?<?= e(http_build_query(array_merge($_GET, ['exportar' => 1]))) ?>"><i class="bi bi-download me-2"></i>Exportar a Excel</a></li>
                <li><a class="dropdown-item" href="<?= e(url('productos/etiquetas.php')) ?>"><i class="bi bi-upc me-2"></i>Imprimir etiquetas</a></li>
                <li><a class="dropdown-item" href="<?= e(url('productos/categorias.php')) ?>"><i class="bi bi-tags me-2"></i>Categorías</a></li>
            </ul>
        </div>
        <?php if (permiso('inventario')): ?>
            <a href="<?= e(url('inventario/index.php')) ?>" class="btn btn-light border"><i class="bi bi-boxes me-1"></i>Inventario</a>
        <?php endif; ?>
        <a href="<?= e(url('productos/crear.php')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuevo producto</a>
    </div>
</div>

<div class="card">
    <?php if ((int) $resumen['activos'] + (int) $resumen['inactivos'] > 0): // sin productos, los filtros solo estorban ?>
    <form class="barra-filtros row g-2 align-items-end" method="get" role="search">
        <div class="col-12 col-md-3">
            <label for="q" class="form-label small mb-1">Buscar</label>
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>" placeholder="Nombre, código o marca" autofocus>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <label for="categoria" class="form-label small mb-1">Categoría</label>
            <select class="form-select" id="categoria" name="categoria">
                <option value="">Todas</option>
                <?php foreach ($categorias as $cid => $cnombre): ?>
                    <option value="<?= (int) $cid ?>" <?= $categoria === (int) $cid ? 'selected' : '' ?>><?= e($cnombre) ?></option>
                <?php endforeach; ?>
                <option value="0" <?= $categoria === 0 ? 'selected' : '' ?>>Sin categoría</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label for="estado" class="form-label small mb-1">Estado</label>
            <select class="form-select" id="estado" name="estado">
                <option value="activos" <?= $estado === 'activos' ? 'selected' : '' ?>>Activos</option>
                <option value="inactivos" <?= $estado === 'inactivos' ? 'selected' : '' ?>>Desactivados</option>
                <option value="todos" <?= $estado === 'todos' ? 'selected' : '' ?>>Todos</option>
            </select>
        </div>
        <div class="col-6 col-md-3 col-lg-3">
            <label for="filtro" class="form-label small mb-1">Tipo</label>
            <select class="form-select" id="filtro" name="filtro">
                <option value="">Todos los tipos</option>
                <option value="inventario" <?= $filtro === 'inventario' ? 'selected' : '' ?>>Con inventario</option>
                <option value="servicios" <?= $filtro === 'servicios' ? 'selected' : '' ?>>Servicios (sin inventario)</option>
                <option value="bajo" <?= $filtro === 'bajo' ? 'selected' : '' ?>>Existencia baja</option>
            </select>
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
            <button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-funnel me-1"></i>Filtrar</button>
            <a class="btn btn-light border" href="<?= e(url('productos/index.php')) ?>" title="Limpiar filtros"><i class="bi bi-x-lg"></i></a>
        </div>
    </form>
    <?php endif; ?>

    <?php if (!$productos): ?>
        <div class="estado-vacio">
            <i class="bi bi-box-seam"></i>
            <?= $q !== '' || $filtro !== '' ? 'No se encontraron productos con esos filtros.' : 'Todavía no hay productos registrados.' ?>
            <div class="mt-3"><a href="<?= e(url('productos/crear.php')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Registrar producto</a></div>
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
            <tr>
                <th>Producto</th>
                <th>Código</th>
                <th class="text-end">Precio</th>
                <th class="text-end">Costo</th>
                <th class="text-end">Existencia</th>
                <th>Estado</th>
                <th class="text-end">Acciones</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($productos as $p):
                $bajo = $p['controlar_stock'] && (float) $p['existencia'] <= (float) $p['stock_minimo']; ?>
                <tr class="<?= $p['activo'] ? '' : 'fila-inactiva' ?>">
                    <td>
                        <div class="fw-semibold"><?= e($p['nombre']) ?></div>
                        <small class="text-body-secondary">
                            <?= match ($p['tipo']) {
                                'paquete' => '<i class="bi bi-box2 me-1"></i>Paquete',
                                'kit'     => '<i class="bi bi-backpack me-1"></i>Kit',
                                default   => $p['controlar_stock'] ? '<i class="bi bi-box me-1"></i>Con inventario' : '<i class="bi bi-printer me-1"></i>Servicio',
                            } ?>
                            <?= $p['permite_fraccion'] ? ' · fraccionable' : '' ?>
                            <?= $p['categoria'] !== null ? ' · <i class="bi bi-tag"></i> ' . e($p['categoria']) : '' ?>
                            <?= $p['marca'] !== null ? ' · ' . e($p['marca']) : '' ?>
                            <?= $p['escalones'] ? ' · <span class="text-success"><i class="bi bi-tags"></i> mayoreo</span>' : '' ?>
                        </small>
                    </td>
                    <td>
                        <?= $p['codigo_barras'] !== null ? '<code class="text-body">' . e($p['codigo_barras']) . '</code>' : '<span class="text-body-secondary">—</span>' ?>
                        <?= $p['codigo_interno'] !== null ? '<div class="small text-body-secondary">' . e($p['codigo_interno']) . '</div>' : '' ?>
                    </td>
                    <td class="text-end num fw-semibold"><?= dinero($p['precio']) ?></td>
                    <td class="text-end num"><?= $p['costo'] !== null ? dinero($p['costo']) : '<span class="text-body-secondary">—</span>' ?></td>
                    <td class="text-end num">
                        <?php if ($p['tipo'] !== 'normal'): ?>
                            <span class="text-body-secondary" title="Según la existencia de lo que contiene"><?= e(cantidad_fmt($disponibles[(int) $p['id']]['existencia'] ?? 0)) ?> <i class="bi bi-link-45deg"></i></span>
                        <?php elseif (!$p['controlar_stock']): ?>
                            <span class="text-body-secondary" title="No descuenta inventario">∞</span>
                        <?php elseif ($bajo): ?>
                            <span class="badge text-bg-warning" title="Mínimo: <?= e(cantidad_fmt($p['stock_minimo'])) ?>"><i class="bi bi-exclamation-triangle me-1"></i><?= e(cantidad_fmt($p['existencia'])) ?></span>
                        <?php else: ?>
                            <?= e(cantidad_fmt($p['existencia'])) ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= $p['activo']
                            ? '<span class="badge rounded-pill text-bg-success-subtle text-success-emphasis">Activo</span>'
                            : '<span class="badge rounded-pill text-bg-secondary">Desactivado</span>' ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="<?= e(url('productos/editar.php?id=' . (int) $p['id'])) ?>" class="btn btn-sm btn-light border" title="Editar"><i class="bi bi-pencil"></i></a>
                        <form method="post" action="<?= e(url('productos/cambiar_estado.php')) ?>" class="d-inline"
                              data-confirmar="<?= $p['activo'] ? '¿Desactivar «' . e($p['nombre']) . '»?' : '¿Activar «' . e($p['nombre']) . '»?' ?>"
                              data-confirmar-detalle="<?= $p['activo'] ? 'Ya no aparecerá en el punto de venta. Su historial de ventas se conserva.' : 'Volverá a estar disponible para vender.' ?>"
                              data-confirmar-boton="<?= $p['activo'] ? 'Sí, desactivar' : 'Sí, activar' ?>"
                              data-confirmar-color="<?= $p['activo'] ? '#dc3545' : '#198754' ?>"
                              data-confirmar-icono="warning">
                            <?= csrf_campo() ?>
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <input type="hidden" name="activo" value="<?= $p['activo'] ? 0 : 1 ?>">
                            <input type="hidden" name="volver" value="<?= e($_SERVER['QUERY_STRING'] ?? '') ?>">
                            <?php if ($p['activo']): ?>
                                <button type="submit" class="btn btn-sm btn-light border text-danger" title="Desactivar"><i class="bi bi-slash-circle"></i></button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-sm btn-light border text-success" title="Activar"><i class="bi bi-arrow-counterclockwise"></i></button>
                            <?php endif; ?>
                        </form>
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
