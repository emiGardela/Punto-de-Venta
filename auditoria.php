<?php
/**
 * Bitácora de auditoría (solo el dueño): quién hizo qué y cuándo.
 */
require_once __DIR__ . '/includes/app.php';

requerir_dueno();

$accion  = array_key_exists($_GET['accion'] ?? '', ACCIONES_AUDITORIA) ? $_GET['accion'] : '';
$usuario = filter_var($_GET['usuario'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$desde   = fecha_valida($_GET['desde'] ?? '');
$hasta   = fecha_valida($_GET['hasta'] ?? '');
$q       = trim((string) ($_GET['q'] ?? ''));

$where = [];
$params = [];
if ($accion !== '') {
    $where[] = 'accion = :accion';
    $params[':accion'] = $accion;
}
if ($usuario) {
    $where[] = 'usuario_id = :usuario';
    $params[':usuario'] = $usuario;
}
if ($desde || $hasta) {
    [$inicio, $fin, $desde, $hasta] = rango_sql($desde ?? $hasta, $hasta ?? $desde);
    $where[] = 'fecha >= :inicio AND fecha < :fin';
    $params[':inicio'] = $inicio;
    $params[':fin'] = $fin;
}
if ($q !== '') {
    $where[] = "sin_acentos(detalle) LIKE sin_acentos(:q) ESCAPE '\\'";
    $params[':q'] = like($q);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = db()->prepare("SELECT COUNT(*) FROM auditoria $whereSql");
$st->execute($params);
$pag = paginar((int) $st->fetchColumn(), 30);
$st = db()->prepare("SELECT * FROM auditoria $whereSql ORDER BY id DESC LIMIT {$pag['por_pagina']} OFFSET {$pag['offset']}");
$st->execute($params);
$registros = $st->fetchAll();

$usuarios = db()->query('SELECT id, nombre FROM usuarios ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);

// Acciones que conviene revisar con atención.
const ACCIONES_DELICADAS = ['venta_cancelada', 'cajon_abierto', 'sesion_fallida', 'abono_cancelado', 'respaldo_restaurado', 'base_limpiada'];

$titulo  = 'Bitácora';
$seccion = 'ajustes';
require __DIR__ . '/includes/encabezado.php';
require __DIR__ . '/includes/pestanas_ajustes.php';
?>
<p class="text-body-secondary">Registro de las operaciones importantes: inicios de sesión, cancelaciones, descuentos, cajón abierto sin venta,
    cambios de precio, cortes y configuración. No se puede modificar ni borrar desde el sistema.</p>

<div class="card">
    <form class="barra-filtros" method="get">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="accion" class="form-label small mb-1">Qué</label>
                <select class="form-select" id="accion" name="accion">
                    <option value="">Todo</option>
                    <?php foreach (ACCIONES_AUDITORIA as $clave => $etiqueta): ?>
                        <option value="<?= e($clave) ?>" <?= $accion === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="usuario" class="form-label small mb-1">Quién</label>
                <select class="form-select" id="usuario" name="usuario">
                    <option value="">Todos</option>
                    <?php foreach ($usuarios as $id => $nombre): ?>
                        <option value="<?= (int) $id ?>" <?= $usuario === (int) $id ? 'selected' : '' ?>><?= e($nombre) ?></option>
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
            <div class="col-md-3 d-flex gap-2">
                <input type="search" class="form-control" name="q" value="<?= e($q) ?>" placeholder="Buscar texto">
                <button class="btn btn-primary" type="submit" title="Filtrar"><i class="bi bi-funnel"></i></button>
                <a class="btn btn-light border" href="<?= e(url('auditoria.php')) ?>" title="Limpiar"><i class="bi bi-x-lg"></i></a>
            </div>
        </div>
    </form>

    <?php if (!$registros): ?>
        <div class="estado-vacio"><i class="bi bi-journal-check"></i>No hay registros que coincidan.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr><th>Fecha</th><th>Quién</th><th>Qué</th><th>Detalle</th></tr></thead>
                <tbody>
                <?php foreach ($registros as $r): ?>
                    <tr>
                        <td class="num small text-nowrap"><?= e(fecha_fmt($r['fecha'])) ?></td>
                        <td class="small"><?= $r['usuario'] !== null ? e($r['usuario']) : '<span class="text-body-secondary">—</span>' ?></td>
                        <td><span class="badge rounded-pill <?= in_array($r['accion'], ACCIONES_DELICADAS, true) ? 'text-bg-warning' : 'text-bg-light border' ?>">
                            <?= e(ACCIONES_AUDITORIA[$r['accion']] ?? $r['accion']) ?></span></td>
                        <td class="small">
                            <?= e($r['detalle']) ?>
                            <?php if ($r['entidad'] === 'venta' && $r['entidad_id']): ?>
                                · <a href="<?= e(url('ventas/detalle.php?id=' . (int) $r['entidad_id'])) ?>">ver venta</a>
                            <?php elseif ($r['entidad'] === 'cliente' && $r['entidad_id']): ?>
                                · <a href="<?= e(url('clientes/detalle.php?id=' . (int) $r['entidad_id'])) ?>">ver cliente</a>
                            <?php elseif ($r['entidad'] === 'turno' && $r['entidad_id']): ?>
                                · <a href="<?= e(url('caja/corte.php?id=' . (int) $r['entidad_id'])) ?>">ver corte</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= paginacion_html($pag) ?>
</div>
<?php require __DIR__ . '/includes/pie.php'; ?>
