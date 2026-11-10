<?php
/**
 * Proveedores: lista, alta y edición (en la misma pantalla).
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('inventario');

$id = filter_var($_REQUEST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$campos = ['nombre' => 100, 'contacto' => 100, 'telefono' => 30, 'correo' => 100, 'rfc' => 13, 'notas' => 300];
$form = array_fill_keys(array_keys($campos), '') + ['activo' => 1];
$errores = [];

if ($id) {
    $st = db()->prepare('SELECT * FROM proveedores WHERE id = :id');
    $st->execute([':id' => $id]);
    $form = $st->fetch() ?: $form;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_post_csrf();
    foreach ($campos as $campo => $max) {
        $form[$campo] = trim((string) ($_POST[$campo] ?? ''));
        if (mb_strlen($form[$campo]) > $max) {
            $errores[$campo] = "Máximo $max caracteres.";
        }
    }
    $form['rfc'] = mb_strtoupper($form['rfc']);
    $form['activo'] = $id ? (!empty($_POST['activo']) ? 1 : 0) : 1;
    if ($form['nombre'] === '') {
        $errores['nombre'] = 'Escribe el nombre del proveedor.';
    }
    if ($form['correo'] !== '' && !filter_var($form['correo'], FILTER_VALIDATE_EMAIL)) {
        $errores['correo'] = 'El correo no es válido.';
    }
    if ($form['rfc'] !== '' && !preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', $form['rfc'])) {
        $errores['rfc'] = 'El RFC no tiene un formato válido (12 o 13 caracteres).';
    }
    if (!$errores) {
        $params = [];
        foreach (array_keys($campos) as $c) {
            $params[":$c"] = $form[$c] !== '' ? $form[$c] : null;
        }
        $params[':activo'] = $form['activo'];
        if ($id) {
            $params[':id'] = $id;
            db()->prepare('UPDATE proveedores SET nombre = :nombre, contacto = :contacto, telefono = :telefono, correo = :correo,
                                                  rfc = :rfc, notas = :notas, activo = :activo WHERE id = :id')->execute($params);
        } else {
            db()->prepare('INSERT INTO proveedores (nombre, contacto, telefono, correo, rfc, notas, activo)
                           VALUES (:nombre, :contacto, :telefono, :correo, :rfc, :notas, :activo)')->execute($params);
        }
        flash('exito', 'Proveedor «' . $form['nombre'] . '» guardado.');
        redirigir('proveedores/index.php');
    }
    flash('error', 'Revisa los campos marcados en rojo.');
}

$proveedores = db()->query('SELECT p.*, (SELECT COUNT(*) FROM productos x WHERE x.proveedor_id = p.id) AS productos
                            FROM proveedores p ORDER BY p.activo DESC, sin_acentos(p.nombre)')->fetchAll();
$v = fn(string $c) => e($form[$c] ?? '');
$invalido = fn(string $c) => isset($errores[$c]) ? ' is-invalid' : '';
$mensaje  = fn(string $c) => isset($errores[$c]) ? '<div class="invalid-feedback">' . e($errores[$c]) . '</div>' : '';

$titulo  = 'Proveedores';
$seccion = 'compras';
require __DIR__ . '/../includes/encabezado.php';
?>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><h2><i class="bi bi-truck me-2 text-primary"></i><?= $id ? 'Editar proveedor' : 'Nuevo proveedor' ?></h2></div>
            <div class="card-body p-4">
                <form method="post" novalidate>
                    <?= csrf_campo() ?>
                    <?php if ($id): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>
                    <div class="mb-3">
                        <label for="nombre" class="form-label">Nombre <span class="text-danger">*</span></label>
                        <input type="text" class="form-control<?= $invalido('nombre') ?>" id="nombre" name="nombre" value="<?= $v('nombre') ?>" maxlength="100" required>
                        <?= $mensaje('nombre') ?>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="contacto" class="form-label">Contacto</label>
                            <input type="text" class="form-control<?= $invalido('contacto') ?>" id="contacto" name="contacto" value="<?= $v('contacto') ?>" maxlength="100" placeholder="Nombre del vendedor">
                        </div>
                        <div class="col-sm-6">
                            <label for="telefono" class="form-label">Teléfono</label>
                            <input type="tel" class="form-control<?= $invalido('telefono') ?>" id="telefono" name="telefono" value="<?= $v('telefono') ?>" maxlength="30">
                        </div>
                        <div class="col-sm-7">
                            <label for="correo" class="form-label">Correo</label>
                            <input type="email" class="form-control<?= $invalido('correo') ?>" id="correo" name="correo" value="<?= $v('correo') ?>" maxlength="100">
                            <?= $mensaje('correo') ?>
                        </div>
                        <div class="col-sm-5">
                            <label for="rfc" class="form-label">RFC</label>
                            <input type="text" class="form-control<?= $invalido('rfc') ?>" id="rfc" name="rfc" value="<?= $v('rfc') ?>" maxlength="13">
                            <?= $mensaje('rfc') ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="notas" class="form-label">Notas</label>
                        <textarea class="form-control" id="notas" name="notas" rows="2" maxlength="300" placeholder="Días de visita, condiciones…"><?= $v('notas') ?></textarea>
                    </div>
                    <?php if ($id): ?>
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="activo" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="activo" name="activo" value="1" <?= (int) $form['activo'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="activo">Activo</label>
                        </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-end gap-2">
                        <?php if ($id): ?><a href="<?= e(url('proveedores/index.php')) ?>" class="btn btn-light border">Cancelar</a><?php endif; ?>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><h2><i class="bi bi-list-ul me-2 text-primary"></i>Proveedores</h2></div>
            <?php if (!$proveedores): ?>
                <div class="card-body text-body-secondary">Todavía no hay proveedores.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Nombre</th><th>Contacto</th><th class="text-end">Productos</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($proveedores as $p): ?>
                            <tr class="<?= $p['activo'] ? '' : 'fila-inactiva' ?>">
                                <td class="fw-semibold"><?= e($p['nombre']) ?><div class="small text-body-secondary fw-normal"><?= e($p['rfc'] ?? '') ?></div></td>
                                <td class="small"><?= e($p['contacto'] ?? '') ?><div class="text-body-secondary"><?= e($p['telefono'] ?? '') ?></div></td>
                                <td class="text-end"><?= (int) $p['productos'] ?></td>
                                <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= e(url('proveedores/index.php?id=' . (int) $p['id'])) ?>"><i class="bi bi-pencil"></i></a></td>
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
