<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Clientes y crédito: lista con lo que debe cada uno.
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('credito');

$q      = trim((string) ($_GET['q'] ?? ''));
$filtro = in_array($_GET['filtro'] ?? '', ['deben', 'inactivos'], true) ? $_GET['filtro'] : '';

$where  = [$filtro === 'inactivos' ? 'c.activo = 0' : 'c.activo = 1'];
$params = [];
if ($q !== '') {
    $where[] = "(sin_acentos(c.nombre) LIKE sin_acentos(:q) ESCAPE '\\' OR c.telefono LIKE :q2 ESCAPE '\\')";
    $params[':q']  = like($q);
    $params[':q2'] = like($q);
}
$whereSql = 'WHERE ' . implode(' AND ', $where);
$having   = $filtro === 'deben' ? 'WHERE saldo > 0.004' : '';

$st = db()->prepare("SELECT * FROM (
        SELECT c.*, " . SQL_SALDO_CLIENTE . " AS saldo,
               (SELECT MAX(v.fecha) FROM ventas v WHERE v.cliente_id = c.id AND v.estado = 'completada') AS ultima_compra
        FROM clientes c $whereSql
    ) t $having ORDER BY saldo > 0.004 DESC, sin_acentos(nombre)");
$st->execute($params);
$clientes = $st->fetchAll();

$totalDeuda = (float) db()->query('SELECT COALESCE(SUM(' . SQL_SALDO_CLIENTE . '), 0) FROM clientes c')->fetchColumn();
$deudores   = (int) db()->query('SELECT COUNT(*) FROM clientes c WHERE ' . SQL_SALDO_CLIENTE . ' > 0.004')->fetchColumn();

$titulo  = 'Clientes y crédito';
$seccion = 'clientes';
require __DIR__ . '/../includes/encabezado.php';
?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-4">
        <div class="card tarjeta-stat h-100"><div class="card-body d-flex align-items-center gap-3">
            <span class="stat-icono icono-rojo"><i class="bi bi-wallet2"></i></span>
            <div><div class="stat-valor num"><?= dinero($totalDeuda) ?></div><div class="stat-etiqueta">Te deben en total</div></div>
        </div></div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="card tarjeta-stat h-100"><div class="card-body d-flex align-items-center gap-3">
            <span class="stat-icono icono-azul"><i class="bi bi-people"></i></span>
            <div><div class="stat-valor num"><?= $deudores ?></div><div class="stat-etiqueta">Clientes con saldo pendiente</div></div>
        </div></div>
    </div>
    <div class="col-lg-4 d-flex align-items-center justify-content-lg-end">
        <a href="<?= e(url('clientes/editar.php')) ?>" class="btn btn-primary btn-lg"><i class="bi bi-person-plus me-1"></i>Nuevo cliente</a>
    </div>
</div>

<div class="card">
    <form class="barra-filtros" method="get">
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label for="q" class="form-label small mb-1">Buscar</label>
                <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>" placeholder="Nombre o teléfono">
            </div>
            <div class="col-md-4">
                <label for="filtro" class="form-label small mb-1">Mostrar</label>
                <select class="form-select" id="filtro" name="filtro">
                    <option value="">Todos los activos</option>
                    <option value="deben" <?= $filtro === 'deben' ? 'selected' : '' ?>>Solo los que deben</option>
                    <option value="inactivos" <?= $filtro === 'inactivos' ? 'selected' : '' ?>>Desactivados</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-funnel me-1"></i>Filtrar</button>
                <a class="btn btn-light border" href="<?= e(url('clientes/index.php')) ?>" title="Limpiar"><i class="bi bi-x-lg"></i></a>
            </div>
        </div>
    </form>

    <?php if (!$clientes): ?>
        <div class="estado-vacio">
            <i class="bi bi-person-vcard"></i>
            <?= $q !== '' || $filtro ? 'No hay clientes que coincidan.' : 'Todavía no hay clientes. Da de alta a quienes compran fiado.' ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr><th>Cliente</th><th>Teléfono</th><th>Última compra</th><th class="text-end">Límite</th><th class="text-end">Debe</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($clientes as $c): $saldo = (float) $c['saldo']; ?>
                    <tr class="fila-enlace" data-href="<?= e(url('clientes/detalle.php?id=' . (int) $c['id'])) ?>">
                        <td class="fw-semibold"><?= e($c['nombre']) ?></td>
                        <td class="num"><?= e($c['telefono'] ?? '') ?></td>
                        <td class="small text-body-secondary"><?= e(fecha_fmt($c['ultima_compra'], false)) ?></td>
                        <td class="text-end num small"><?= $c['limite_credito'] !== null ? dinero($c['limite_credito']) : 'Sin límite' ?></td>
                        <td class="text-end num fw-bold <?= $saldo > 0.004 ? 'text-danger' : 'text-body-secondary' ?>"><?= dinero($saldo) ?></td>
                        <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= e(url('clientes/detalle.php?id=' . (int) $c['id'])) ?>"><i class="bi bi-eye"></i></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/pie.php'; ?>
