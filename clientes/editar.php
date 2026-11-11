<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Alta y edición de clientes.
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('credito');

$id = filter_var($_REQUEST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$cliente = ['nombre' => '', 'telefono' => '', 'notas' => '', 'limite_credito' => '', 'activo' => 1,
            'rfc' => '', 'razon_social' => '', 'cp_fiscal' => '', 'regimen_fiscal' => '', 'uso_cfdi' => '', 'correo' => ''];
if ($id) {
    $cliente = cliente_por_id($id);
    if (!$cliente) {
        flash('error', 'El cliente no existe.');
        redirigir('clientes/index.php');
    }
}
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_post_csrf();
    $datos = [
        'nombre'   => trim(preg_replace('/\s+/u', ' ', (string) ($_POST['nombre'] ?? '')) ?? ''),
        'telefono' => trim((string) ($_POST['telefono'] ?? '')),
        'notas'    => trim((string) ($_POST['notas'] ?? '')),
        'limite_credito' => trim((string) ($_POST['limite_credito'] ?? '')),
        'activo'   => $id ? (!empty($_POST['activo']) ? 1 : 0) : 1,
    ];
    if ($datos['nombre'] === '' || mb_strlen($datos['nombre']) > 100) {
        $errores['nombre'] = 'Escribe el nombre del cliente (máximo 100 caracteres).';
    }
    if (mb_strlen($datos['telefono']) > 30) {
        $errores['telefono'] = 'Máximo 30 caracteres.';
    }
    if (mb_strlen($datos['notas']) > 300) {
        $errores['notas'] = 'Máximo 300 caracteres.';
    }
    // Datos fiscales: opcionales, pero si se llena alguno deben ir completos y correctos.
    $camposFiscales = ['rfc', 'razon_social', 'cp_fiscal', 'regimen_fiscal', 'uso_cfdi', 'correo'];
    $fiscales = array_fill_keys($camposFiscales, null);
    if (array_filter(array_map(fn($c) => trim((string) ($_POST[$c] ?? '')), ['rfc', 'razon_social', 'cp_fiscal', 'regimen_fiscal']))) {
        [$fiscales, $erroresFiscales] = validar_datos_fiscales($_POST);
        $errores += $erroresFiscales;
        $fiscales = array_map(fn($x) => $x === '' ? null : $x, $fiscales);
    } elseif (trim((string) ($_POST['correo'] ?? '')) !== '') {
        $fiscales['correo'] = mb_strtolower(trim((string) $_POST['correo']));
        if (!filter_var($fiscales['correo'], FILTER_VALIDATE_EMAIL)) {
            $errores['correo'] = 'El correo no es válido.';
        }
    }
    $datos += array_map(fn($x) => $x ?? '', $fiscales);
    $limite = null;
    if ($datos['limite_credito'] !== '') {
        $limite = normalizar_decimal($datos['limite_credito'], 2, 8);
        if ($limite === null) {
            $errores['limite_credito'] = 'Escribe un monto válido o déjalo vacío para no poner límite.';
        }
    }

    if (!$errores) {
        $params = [':nombre' => $datos['nombre'], ':telefono' => $datos['telefono'] ?: null, ':notas' => $datos['notas'] ?: null,
                   ':limite' => $limite, ':activo' => $datos['activo'], ':rfc' => $fiscales['rfc'], ':razon' => $fiscales['razon_social'],
                   ':cp' => $fiscales['cp_fiscal'], ':regimen' => $fiscales['regimen_fiscal'], ':uso' => $fiscales['uso_cfdi'], ':correo' => $fiscales['correo']];
        if ($id) {
            $params[':id'] = $id;
            db()->prepare('UPDATE clientes SET nombre = :nombre, telefono = :telefono, notas = :notas, limite_credito = :limite, activo = :activo,
                                               rfc = :rfc, razon_social = :razon, cp_fiscal = :cp, regimen_fiscal = :regimen, uso_cfdi = :uso, correo = :correo
                           WHERE id = :id')->execute($params);
        } else {
            db()->prepare('INSERT INTO clientes (nombre, telefono, notas, limite_credito, activo, rfc, razon_social, cp_fiscal, regimen_fiscal, uso_cfdi, correo)
                           VALUES (:nombre, :telefono, :notas, :limite, :activo, :rfc, :razon, :cp, :regimen, :uso, :correo)')->execute($params);
            $id = (int) db()->lastInsertId();
        }
        auditar('cliente_guardado', '«' . $datos['nombre'] . '»' . ($limite !== null ? ', límite ' . dinero($limite) : ', sin límite'), 'cliente', $id);
        flash('exito', 'Cliente «' . $datos['nombre'] . '» guardado.');
        redirigir('clientes/detalle.php?id=' . $id);
    }
    $cliente = array_merge($cliente, $datos);
    flash('error', 'Revisa los campos marcados en rojo.');
}

$v = fn(string $c) => e($cliente[$c] ?? '');
$invalido = fn(string $c) => isset($errores[$c]) ? ' is-invalid' : '';
$mensaje  = fn(string $c) => isset($errores[$c]) ? '<div class="invalid-feedback">' . e($errores[$c]) . '</div>' : '';

$titulo  = $id ? 'Editar cliente' : 'Nuevo cliente';
$seccion = 'clientes';
require __DIR__ . '/../includes/encabezado.php';
?>
<nav aria-label="Ruta" class="mb-3"><ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= e(url('clientes/index.php')) ?>">Clientes</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= $id ? e($cliente['nombre']) : 'Nuevo' ?></li>
</ol></nav>

<form method="post" novalidate style="max-width:640px">
    <?= csrf_campo() ?>
    <?php if ($id): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>
    <div class="card">
        <div class="card-body p-4">
            <div class="mb-3">
                <label for="nombre" class="form-label">Nombre <span class="text-danger">*</span></label>
                <input type="text" class="form-control form-control-lg<?= $invalido('nombre') ?>" id="nombre" name="nombre"
                       value="<?= $v('nombre') ?>" maxlength="100" required autofocus placeholder="Ej. Doña Mary (la de la esquina)">
                <?= $mensaje('nombre') ?>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-sm-6">
                    <label for="telefono" class="form-label">Teléfono <span class="text-body-secondary fw-normal">(opcional)</span></label>
                    <input type="tel" class="form-control<?= $invalido('telefono') ?>" id="telefono" name="telefono" value="<?= $v('telefono') ?>" maxlength="30">
                    <?= $mensaje('telefono') ?>
                </div>
                <div class="col-sm-6">
                    <label for="limite_credito" class="form-label">Límite de crédito <span class="text-body-secondary fw-normal">(opcional)</span></label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="text" class="form-control num<?= $invalido('limite_credito') ?>" id="limite_credito" name="limite_credito"
                               value="<?= $v('limite_credito') ?>" inputmode="decimal" placeholder="Sin límite">
                        <?= $mensaje('limite_credito') ?>
                    </div>
                    <div class="form-text">Lo máximo que puede deber. Vacío = sin límite.</div>
                </div>
            </div>
            <div class="mb-3">
                <label for="notas" class="form-label">Notas <span class="text-body-secondary fw-normal">(opcional)</span></label>
                <textarea class="form-control<?= $invalido('notas') ?>" id="notas" name="notas" rows="2" maxlength="300"
                          placeholder="Dirección, cuándo paga, etc."><?= $v('notas') ?></textarea>
                <?= $mensaje('notas') ?>
            </div>
            <?php $conFiscales = (string) ($cliente['rfc'] ?? '') !== '' || array_intersect_key($errores, array_flip(['rfc', 'razon_social', 'cp_fiscal', 'regimen_fiscal', 'uso_cfdi', 'correo'])); ?>
            <button class="btn btn-link px-0 text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#datosFiscales" aria-expanded="<?= $conFiscales ? 'true' : 'false' ?>">
                <i class="bi bi-receipt-cutoff me-1"></i>Datos para factura <span class="text-body-secondary small">(opcional)</span>
            </button>
            <div class="collapse<?= $conFiscales ? ' show' : '' ?> mb-3" id="datosFiscales">
                <div class="row g-3 pt-2">
                    <div class="col-sm-6">
                        <label for="rfc" class="form-label">RFC</label>
                        <input type="text" class="form-control num text-uppercase<?= $invalido('rfc') ?>" id="rfc" name="rfc" value="<?= $v('rfc') ?>" maxlength="13" autocomplete="off">
                        <?= $mensaje('rfc') ?>
                    </div>
                    <div class="col-sm-6">
                        <label for="cp_fiscal" class="form-label">Código postal</label>
                        <input type="text" class="form-control num<?= $invalido('cp_fiscal') ?>" id="cp_fiscal" name="cp_fiscal" value="<?= $v('cp_fiscal') ?>" maxlength="5" inputmode="numeric">
                        <?= $mensaje('cp_fiscal') ?>
                    </div>
                    <div class="col-12">
                        <label for="razon_social" class="form-label">Nombre o razón social</label>
                        <input type="text" class="form-control text-uppercase<?= $invalido('razon_social') ?>" id="razon_social" name="razon_social" value="<?= $v('razon_social') ?>" maxlength="254">
                        <?= $mensaje('razon_social') ?>
                    </div>
                    <div class="col-sm-6">
                        <label for="regimen_fiscal" class="form-label">Régimen fiscal</label>
                        <select class="form-select<?= $invalido('regimen_fiscal') ?>" id="regimen_fiscal" name="regimen_fiscal">
                            <option value="">Elige…</option>
                            <?php foreach (REGIMENES_FISCALES as $clave => $etiqueta): ?>
                                <option value="<?= e($clave) ?>" <?= (string) ($cliente['regimen_fiscal'] ?? '') === (string) $clave ? 'selected' : '' ?>><?= e($clave . ' · ' . $etiqueta) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $mensaje('regimen_fiscal') ?>
                    </div>
                    <div class="col-sm-6">
                        <label for="uso_cfdi" class="form-label">Uso de la factura</label>
                        <select class="form-select<?= $invalido('uso_cfdi') ?>" id="uso_cfdi" name="uso_cfdi">
                            <?php foreach (USOS_CFDI as $clave => $etiqueta): ?>
                                <option value="<?= e($clave) ?>" <?= (($cliente['uso_cfdi'] ?? '') ?: 'G03') === $clave ? 'selected' : '' ?>><?= e($clave . ' · ' . $etiqueta) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $mensaje('uso_cfdi') ?>
                    </div>
                    <div class="col-12">
                        <label for="correo" class="form-label">Correo</label>
                        <input type="email" class="form-control<?= $invalido('correo') ?>" id="correo" name="correo" value="<?= $v('correo') ?>" maxlength="120">
                        <?= $mensaje('correo') ?>
                    </div>
                </div>
            </div>
            <?php if ($id): ?>
                <div class="form-check form-switch">
                    <input type="hidden" name="activo" value="0">
                    <input class="form-check-input" type="checkbox" role="switch" id="activo" name="activo" value="1" <?= (int) $cliente['activo'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="activo">Activo (aparece al cobrar)</label>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex justify-content-end gap-2 mt-3">
        <a href="<?= e(url($id ? 'clientes/detalle.php?id=' . $id : 'clientes/index.php')) ?>" class="btn btn-light border btn-lg">Cancelar</a>
        <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check2-circle me-1"></i>Guardar</button>
    </div>
</form>
<?php require __DIR__ . '/../includes/pie.php'; ?>
