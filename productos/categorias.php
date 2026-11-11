<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Categorías de productos: alta, cambio de nombre y desactivar.
 * Nunca se borran (los productos y reportes las siguen usando).
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('productos');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_post_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $nombre = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['nombre'] ?? '')) ?? '');

    if ($accion === 'guardar') {
        if ($nombre === '' || mb_strlen($nombre) > 60) {
            flash('error', 'Escribe el nombre de la categoría (máximo 60 caracteres).');
        } else {
            try {
                if ($id) {
                    db()->prepare('UPDATE categorias SET nombre = :n WHERE id = :id')->execute([':n' => $nombre, ':id' => $id]);
                } else {
                    db()->prepare('INSERT INTO categorias (nombre) VALUES (:n)')->execute([':n' => $nombre]);
                }
                flash('exito', 'Categoría «' . $nombre . '» guardada.');
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                flash('error', 'Ya existe una categoría con ese nombre.');
            }
        }
    } elseif ($accion === 'estado' && $id) {
        db()->prepare('UPDATE categorias SET activo = 1 - activo WHERE id = :id')->execute([':id' => $id]);
        flash('exito', 'Categoría actualizada.');
    }
    redirigir('productos/categorias.php');
}

$categorias = db()->query('SELECT c.*, (SELECT COUNT(*) FROM productos p WHERE p.categoria_id = c.id) AS productos
                           FROM categorias c ORDER BY c.activo DESC, sin_acentos(c.nombre)')->fetchAll();
$sugeridas = giro_actual()['categorias'];
$existentes = array_map(fn($c) => sin_acentos($c['nombre']), $categorias);

$titulo  = 'Categorías';
$seccion = 'productos';
require __DIR__ . '/../includes/encabezado.php';
?>
<nav aria-label="Ruta" class="mb-3"><ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= e(url('productos/index.php')) ?>">Productos</a></li>
    <li class="breadcrumb-item active" aria-current="page">Categorías</li>
</ol></nav>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><h2><i class="bi bi-tags me-2 text-primary"></i>Nueva categoría</h2></div>
            <div class="card-body p-4">
                <form method="post" class="d-flex gap-2">
                    <?= csrf_campo() ?>
                    <input type="hidden" name="accion" value="guardar">
                    <input type="text" class="form-control" name="nombre" maxlength="60" required placeholder="Ej. <?= e($sugeridas[0]) ?>" autofocus>
                    <button type="submit" class="btn btn-primary">Agregar</button>
                </form>
                <div class="small text-body-secondary mt-3 mb-1">Sugerencias (un clic para agregar):</div>
                <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($sugeridas as $s): if (in_array(sin_acentos($s), $existentes, true)) { continue; } ?>
                        <form method="post">
                            <?= csrf_campo() ?>
                            <input type="hidden" name="accion" value="guardar">
                            <input type="hidden" name="nombre" value="<?= e($s) ?>">
                            <button type="submit" class="btn btn-sm btn-light border">+ <?= e($s) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><h2><i class="bi bi-list-ul me-2 text-primary"></i>Categorías</h2></div>
            <?php if (!$categorias): ?>
                <div class="card-body text-body-secondary">Todavía no hay categorías.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Nombre</th><th class="text-end">Productos</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($categorias as $c): ?>
                            <tr class="<?= $c['activo'] ? '' : 'fila-inactiva' ?>">
                                <td>
                                    <form method="post" class="d-flex gap-2">
                                        <?= csrf_campo() ?>
                                        <input type="hidden" name="accion" value="guardar">
                                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                        <input type="text" class="form-control form-control-sm" name="nombre" value="<?= e($c['nombre']) ?>" maxlength="60" required>
                                        <button type="submit" class="btn btn-sm btn-light border" title="Guardar nombre"><i class="bi bi-check2"></i></button>
                                    </form>
                                </td>
                                <td class="text-end">
                                    <a href="<?= e(url('productos/index.php?categoria=' . (int) $c['id'])) ?>"><?= (int) $c['productos'] ?></a>
                                </td>
                                <td class="text-end">
                                    <form method="post">
                                        <?= csrf_campo() ?>
                                        <input type="hidden" name="accion" value="estado">
                                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-light border"><?= $c['activo'] ? 'Desactivar' : 'Activar' ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/pie.php'; ?>
