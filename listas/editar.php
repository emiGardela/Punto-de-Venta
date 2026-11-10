<?php
/**
 * Alta y edición de una lista escolar (nombre, escuela y artículos).
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('productos');

$id = filter_var($_REQUEST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$lista = ['nombre' => '', 'escuela' => '', 'notas' => '', 'activo' => 1];
$items = [];   // [producto_id => cantidad]
if ($id) {
    $st = db()->prepare('SELECT * FROM listas WHERE id = :id');
    $st->execute([':id' => $id]);
    $lista = $st->fetch();
    if (!$lista) {
        flash('error', 'La lista no existe.');
        redirigir('listas/index.php');
    }
    $st = db()->prepare('SELECT producto_id, cantidad FROM lista_productos WHERE lista_id = :id ORDER BY id');
    $st->execute([':id' => $id]);
    $items = array_map(fn($c) => cantidad_fmt($c), $st->fetchAll(PDO::FETCH_KEY_PAIR));
}
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_post_csrf();
    if (($_POST['accion'] ?? '') === 'copiar' && $id) {
        // Duplicar una lista (por ejemplo, para otro grupo del mismo grado).
        db()->prepare("INSERT INTO listas (nombre, escuela, notas) SELECT nombre || ' (copia)', escuela, notas FROM listas WHERE id = :id")->execute([':id' => $id]);
        $nueva = (int) db()->lastInsertId();
        db()->prepare('INSERT INTO lista_productos (lista_id, producto_id, cantidad) SELECT :nueva, producto_id, cantidad FROM lista_productos WHERE lista_id = :id')
            ->execute([':nueva' => $nueva, ':id' => $id]);
        flash('exito', 'Lista copiada.');
        redirigir('listas/editar.php?id=' . $nueva);
    }
    $lista = [
        'nombre'  => trim(preg_replace('/\s+/u', ' ', (string) ($_POST['nombre'] ?? '')) ?? ''),
        'escuela' => trim(preg_replace('/\s+/u', ' ', (string) ($_POST['escuela'] ?? '')) ?? ''),
        'notas'   => trim((string) ($_POST['notas'] ?? '')),
        'activo'  => $id ? (!empty($_POST['activo']) ? 1 : 0) : 1,
    ];
    if ($lista['nombre'] === '' || mb_strlen($lista['nombre']) > 80) {
        $errores['nombre'] = 'Escribe el grado o nombre de la lista (máximo 80 caracteres).';
    }
    if (mb_strlen($lista['escuela']) > 100 || mb_strlen($lista['notas']) > 500) {
        $errores['escuela'] = 'La escuela o las notas son demasiado largas.';
    }
    $items = [];
    $cantidades = (array) ($_POST['cantidad'] ?? []);
    foreach ((array) ($_POST['producto'] ?? []) as $i => $pid) {
        $pid = filter_var($pid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if (!$pid) {
            continue;
        }
        $cant = normalizar_decimal(($cantidades[$i] ?? '') === '' ? '1' : $cantidades[$i], 3, 6);
        if ($cant === null || a_milesimas($cant) <= 0) {
            $errores['items'] = 'Revisa las cantidades: deben ser mayores que cero.';
            continue;
        }
        $items[$pid] = cantidad_fmt(($items[$pid] ?? 0) + (float) $cant);
    }
    if (!$items && !isset($errores['items'])) {
        $errores['items'] = 'Agrega al menos un artículo.';
    }
    if (!$errores) {
        transaccion_iniciar();
        try {
            $params = [':nombre' => $lista['nombre'], ':escuela' => $lista['escuela'] ?: null, ':notas' => $lista['notas'] ?: null, ':activo' => $lista['activo']];
            if ($id) {
                db()->prepare('UPDATE listas SET nombre = :nombre, escuela = :escuela, notas = :notas, activo = :activo WHERE id = :id')
                    ->execute($params + [':id' => $id]);
            } else {
                db()->prepare('INSERT INTO listas (nombre, escuela, notas, activo) VALUES (:nombre, :escuela, :notas, :activo)')->execute($params);
                $id = (int) db()->lastInsertId();
            }
            db()->prepare('DELETE FROM lista_productos WHERE lista_id = :id')->execute([':id' => $id]);
            $ins = db()->prepare('INSERT INTO lista_productos (lista_id, producto_id, cantidad) VALUES (:lista, :producto, :cantidad)');
            foreach ($items as $pid => $cant) {
                $ins->execute([':lista' => $id, ':producto' => $pid, ':cantidad' => str_replace(',', '', $cant)]);
            }
            transaccion_confirmar();
        } catch (Throwable $e) {
            transaccion_revertir();
            throw $e;
        }
        flash('exito', 'Lista «' . $lista['nombre'] . '» guardada.');
        redirigir('listas/editar.php?id=' . $id);
    }
    flash('error', 'Revisa los campos marcados en rojo.');
}

$productos = db()->query('SELECT id, nombre, precio FROM productos WHERE activo = 1 ORDER BY sin_acentos(nombre)')->fetchAll();
$precios = array_column($productos, 'precio', 'id');
$filas = max(8, count($items) + 4);
$itemIds = array_keys($items);
$itemCant = array_values($items);
$total = 0.0;
foreach ($items as $pid => $cant) {
    $total += (float) ($precios[$pid] ?? 0) * (float) str_replace(',', '', $cant);
}

$titulo  = $id ? 'Lista: ' . $lista['nombre'] : 'Nueva lista escolar';
$seccion = 'listas';
require __DIR__ . '/../includes/encabezado.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <nav aria-label="Ruta"><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= e(url('listas/index.php')) ?>">Listas escolares</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= $id ? e($lista['nombre']) : 'Nueva' ?></li>
    </ol></nav>
    <?php if ($id): ?>
        <div class="d-flex gap-2">
            <a href="<?= e(url('listas/imprimir.php?id=' . $id)) ?>" class="btn btn-light border"><i class="bi bi-printer me-1"></i>Imprimir</a>
            <form method="post"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="copiar">
                <button type="submit" class="btn btn-light border"><i class="bi bi-copy me-1"></i>Copiar</button></form>
        </div>
    <?php endif; ?>
</div>

<form method="post" novalidate>
    <?= csrf_campo() ?>
    <?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label for="nombre" class="form-label">Grado o nombre <span class="text-danger">*</span></label>
                        <input type="text" class="form-control<?= isset($errores['nombre']) ? ' is-invalid' : '' ?>" id="nombre" name="nombre"
                               value="<?= e($lista['nombre']) ?>" maxlength="80" required autofocus placeholder="Ej. 3° primaria">
                        <?php if (isset($errores['nombre'])): ?><div class="invalid-feedback"><?= e($errores['nombre']) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="escuela" class="form-label">Escuela</label>
                        <input type="text" class="form-control" id="escuela" name="escuela" value="<?= e($lista['escuela'] ?? '') ?>" maxlength="100" placeholder="Ej. Esc. Benito Juárez, turno matutino">
                    </div>
                    <div class="mb-3">
                        <label for="notas" class="form-label">Notas</label>
                        <textarea class="form-control" id="notas" name="notas" rows="2" maxlength="500" placeholder="Forrar de azul, etiquetas con nombre…"><?= e($lista['notas'] ?? '') ?></textarea>
                    </div>
                    <?php if ($id): ?>
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="activo" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="activo" name="activo" value="1" <?= (int) $lista['activo'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="activo">Activa (aparece en el punto de venta)</label>
                        </div>
                    <?php endif; ?>
                    <div class="bg-primary-suave rounded-4 text-center py-3">
                        <div class="small text-body-secondary">Total a precio actual</div>
                        <div class="fs-3 fw-bold num"><?= dinero($total) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h2><i class="bi bi-card-checklist me-2 text-primary"></i>Artículos</h2></div>
                <div class="card-body">
                    <?php if (isset($errores['items'])): ?><div class="text-danger small mb-2"><?= e($errores['items']) ?></div><?php endif; ?>
                    <?php for ($i = 0; $i < $filas; $i++): ?>
                        <div class="input-group mb-2">
                            <input type="text" class="form-control num" name="cantidad[]" value="<?= e($itemCant[$i] ?? '') ?>" inputmode="decimal" placeholder="1" style="max-width:80px" aria-label="Cantidad">
                            <select class="form-select" name="producto[]" aria-label="Producto">
                                <option value="">—</option>
                                <?php foreach ($productos as $p): ?>
                                    <option value="<?= (int) $p['id'] ?>" <?= (int) ($itemIds[$i] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?> · <?= e(dinero($p['precio'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endfor; ?>
                    <div class="form-text">¿Necesitas más renglones? Guarda y aparecerán más.</div>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-3">
                <a href="<?= e(url('listas/index.php')) ?>" class="btn btn-light border btn-lg">Cancelar</a>
                <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check2-circle me-1"></i>Guardar lista</button>
            </div>
        </div>
    </div>
</form>
<?php require __DIR__ . '/../includes/pie.php'; ?>
