<?php
/**
 * Usuarios: el dueño crea cajeros, les pone PIN y elige sus permisos.
 * Mientras no haya usuarios, cualquiera puede entrar aquí para crear al dueño.
 */
require_once __DIR__ . '/includes/app.php';

requerir_dueno();

$primerUsuario = !hay_usuarios();
$editarId = filter_var($_REQUEST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$errores = [];
$form = ['nombre' => '', 'rol' => $primerUsuario ? 'dueno' : 'cajero', 'permisos' => [], 'activo' => 1];

if ($editarId) {
    $st = db()->prepare('SELECT * FROM usuarios WHERE id = :id');
    $st->execute([':id' => $editarId]);
    $original = $st->fetch();
    if (!$original) {
        flash('error', 'El usuario no existe.');
        redirigir('usuarios.php');
    }
    $form = ['nombre' => $original['nombre'], 'rol' => $original['rol'],
             'permisos' => array_filter(explode(',', $original['permisos'])), 'activo' => (int) $original['activo']];
}

/** Dueños activos sin contar al usuario $excepto. */
function otros_duenos_activos(?int $excepto): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM usuarios WHERE rol = 'dueno' AND activo = 1 AND id <> :id");
    $st->execute([':id' => $excepto ?? 0]);
    return (int) $st->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_post_csrf();
    $nombre = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['nombre'] ?? '')) ?? '');
    $pin    = (string) ($_POST['pin'] ?? '');
    $pin2   = (string) ($_POST['pin2'] ?? '');
    $rol    = $primerUsuario ? 'dueno' : (array_key_exists($_POST['rol'] ?? '', ROLES) ? $_POST['rol'] : 'cajero');
    $permisos = $rol === 'dueno' ? [] : array_values(array_intersect(array_keys(PERMISOS), (array) ($_POST['permisos'] ?? [])));
    $activo = $editarId ? (!empty($_POST['activo']) ? 1 : 0) : 1;

    if ($nombre === '' || mb_strlen($nombre) > 40) {
        $errores['nombre'] = 'Escribe un nombre de hasta 40 caracteres.';
    } else {
        $st = db()->prepare('SELECT COUNT(*) FROM usuarios WHERE nombre = :n AND id <> :id');
        $st->execute([':n' => $nombre, ':id' => $editarId ?? 0]);
        if ((int) $st->fetchColumn() > 0) {
            $errores['nombre'] = 'Ya hay un usuario con ese nombre.';
        }
    }
    if (!$editarId || $pin !== '') {
        if (!pin_valido($pin)) {
            $errores['pin'] = 'El PIN debe tener de 4 a 6 números.';
        } elseif ($pin !== $pin2) {
            $errores['pin2'] = 'Los dos PIN no coinciden.';
        }
    }
    // Siempre debe quedar al menos un dueño activo, si no nadie podría administrar.
    if ($editarId && $form['rol'] === 'dueno' && $form['activo'] && ($rol !== 'dueno' || !$activo) && otros_duenos_activos($editarId) === 0) {
        $errores['rol'] = 'Debe quedar al menos un dueño activo.';
    }

    if (!$errores) {
        if ($editarId) {
            $sql = 'UPDATE usuarios SET nombre = :nombre, rol = :rol, permisos = :permisos, activo = :activo'
                . ($pin !== '' ? ', pin_hash = :pin' : '') . ' WHERE id = :id';
            $params = [':nombre' => $nombre, ':rol' => $rol, ':permisos' => implode(',', $permisos), ':activo' => $activo, ':id' => $editarId];
            if ($pin !== '') {
                $params[':pin'] = password_hash($pin, PASSWORD_DEFAULT);
            }
            db()->prepare($sql)->execute($params);
            auditar('usuario_guardado', 'Modificó a «' . $nombre . '» (' . ($rol === 'dueno' ? 'dueño' : 'cajero')
                . ($pin !== '' ? ', PIN nuevo' : '') . ($activo ? '' : ', desactivado') . ')', 'usuario', $editarId);
            flash('exito', 'Cambios guardados en «' . $nombre . '».');
        } else {
            db()->prepare('INSERT INTO usuarios (nombre, pin_hash, rol, permisos) VALUES (:nombre, :pin, :rol, :permisos)')
                ->execute([':nombre' => $nombre, ':pin' => password_hash($pin, PASSWORD_DEFAULT), ':rol' => $rol, ':permisos' => implode(',', $permisos)]);
            $nuevoId = (int) db()->lastInsertId();
            auditar('usuario_guardado', 'Creó a «' . $nombre . '» (' . ($rol === 'dueno' ? 'dueño' : 'cajero') . ')', 'usuario', $nuevoId);
            if ($primerUsuario) {
                iniciar_sesion_usuario($nuevoId);
                flash('exito', 'Listo. Desde ahora el sistema pedirá el PIN. Ya puedes crear a tus cajeros.');
            } else {
                flash('exito', 'Usuario «' . $nombre . '» creado.');
            }
        }
        redirigir('usuarios.php');
    }
    $form = ['nombre' => $nombre, 'rol' => $rol, 'permisos' => $permisos, 'activo' => $activo];
    flash('error', 'Revisa los campos marcados en rojo.');
}

$usuarios = db()->query("SELECT * FROM usuarios ORDER BY activo DESC, rol = 'dueno' DESC, nombre")->fetchAll();
$invalido = fn(string $c) => isset($errores[$c]) ? ' is-invalid' : '';
$mensaje  = fn(string $c) => isset($errores[$c]) ? '<div class="invalid-feedback">' . e($errores[$c]) . '</div>' : '';

$titulo  = 'Usuarios';
$seccion = 'ajustes';
require __DIR__ . '/includes/encabezado.php';
require __DIR__ . '/includes/pestanas_ajustes.php';
?>
<?php if ($primerUsuario): ?>
    <div class="alert alert-info d-flex gap-3 align-items-start">
        <i class="bi bi-person-lock fs-3"></i>
        <div>
            <strong>Primero crea tu usuario de dueño.</strong>
            Mientras no haya usuarios, cualquiera puede usar el sistema sin clave. En cuanto lo crees, el sistema pedirá el PIN
            al abrirse y podrás dar de alta a tus cajeros con los permisos que tú elijas.
            <div class="small mt-1">Anota tu PIN en un lugar seguro: si lo olvidas, necesitarás a quien te instaló el sistema.</div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><h2><i class="bi bi-person-plus me-2 text-primary"></i><?= $editarId ? 'Editar usuario' : ($primerUsuario ? 'Crear usuario de dueño' : 'Nuevo usuario') ?></h2></div>
            <div class="card-body p-4">
                <form method="post" novalidate autocomplete="off">
                    <?= csrf_campo() ?>
                    <?php if ($editarId): ?><input type="hidden" name="id" value="<?= (int) $editarId ?>"><?php endif; ?>
                    <div class="mb-3">
                        <label for="nombre" class="form-label">Nombre</label>
                        <input type="text" class="form-control form-control-lg<?= $invalido('nombre') ?>" id="nombre" name="nombre"
                               value="<?= e($form['nombre']) ?>" maxlength="40" required autofocus placeholder="Ej. Ana">
                        <?= $mensaje('nombre') ?>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label for="pin" class="form-label">PIN <?= $editarId ? '<span class="text-body-secondary fw-normal">(vacío = no cambiar)</span>' : '' ?></label>
                            <input type="password" class="form-control<?= $invalido('pin') ?>" id="pin" name="pin" inputmode="numeric"
                                   maxlength="6" pattern="\d{4,6}" placeholder="4 a 6 números" autocomplete="new-password">
                            <?= $mensaje('pin') ?>
                        </div>
                        <div class="col-6">
                            <label for="pin2" class="form-label">Repite el PIN</label>
                            <input type="password" class="form-control<?= $invalido('pin2') ?>" id="pin2" name="pin2" inputmode="numeric"
                                   maxlength="6" autocomplete="new-password">
                            <?= $mensaje('pin2') ?>
                        </div>
                    </div>

                    <?php if (!$primerUsuario): ?>
                        <div class="mb-3">
                            <label class="form-label" for="rol">Tipo de usuario</label>
                            <select class="form-select<?= $invalido('rol') ?>" id="rol" name="rol">
                                <?php foreach (ROLES as $clave => $etiqueta): ?>
                                    <option value="<?= e($clave) ?>" <?= $form['rol'] === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= $mensaje('rol') ?>
                        </div>
                        <div id="cajaPermisos" class="mb-3">
                            <div class="form-label">Qué puede hacer <span class="text-body-secondary fw-normal small">(vender y abrir caja siempre puede)</span></div>
                            <?php foreach (PERMISOS as $clave => $etiqueta): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="permisos[]" value="<?= e($clave) ?>" id="p_<?= e($clave) ?>"
                                        <?= in_array($clave, $form['permisos'], true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="p_<?= e($clave) ?>"><?= e($etiqueta) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($editarId): ?>
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="activo" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="activo" name="activo" value="1" <?= $form['activo'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="activo">Activo (puede entrar al sistema)</label>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex gap-2 justify-content-end">
                        <?php if ($editarId): ?><a href="<?= e(url('usuarios.php')) ?>" class="btn btn-light border">Cancelar</a><?php endif; ?>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i><?= $editarId ? 'Guardar cambios' : 'Crear usuario' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><h2><i class="bi bi-people me-2 text-primary"></i>Usuarios</h2></div>
            <?php if (!$usuarios): ?>
                <div class="card-body text-body-secondary">Todavía no hay usuarios. El sistema se usa sin clave.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Nombre</th><th>Tipo</th><th>Permisos</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($usuarios as $u): ?>
                            <tr class="<?= $u['activo'] ? '' : 'fila-inactiva' ?>">
                                <td class="fw-semibold"><?= e($u['nombre']) ?><?= $u['activo'] ? '' : ' <span class="badge text-bg-secondary">Inactivo</span>' ?></td>
                                <td><?= $u['rol'] === 'dueno' ? '<span class="badge text-bg-primary">Dueño</span>' : 'Cajero' ?></td>
                                <td class="small text-body-secondary">
                                    <?php if ($u['rol'] === 'dueno'): ?>Todo
                                    <?php else: ?>
                                        <?= e(implode(', ', array_map(fn($p) => PERMISOS[$p] ?? $p, array_filter(explode(',', $u['permisos'])))) ?: 'Solo vender') ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= e(url('usuarios.php?id=' . (int) $u['id'])) ?>"><i class="bi bi-pencil"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    const rol = document.getElementById('rol');
    const caja = document.getElementById('cajaPermisos');
    if (!rol || !caja) { return; }
    const actualizar = () => caja.classList.toggle('d-none', rol.value === 'dueno');
    rol.addEventListener('change', actualizar);
    actualizar();
})();
</script>
<?php require __DIR__ . '/includes/pie.php'; ?>
