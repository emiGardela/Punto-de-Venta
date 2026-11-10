<?php
/**
 * Activa o desactiva un producto. Nunca lo borra físicamente,
 * así se conserva el historial de ventas que lo incluye.
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('productos');

requerir_post_csrf();

$id     = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$activo = ($_POST['activo'] ?? '') === '1' ? 1 : 0;

// Regresa al listado conservando los filtros que tenía el usuario.
parse_str((string) ($_POST['volver'] ?? ''), $filtros);
$filtros = array_intersect_key($filtros, array_flip(['q', 'estado', 'filtro', 'pagina']));
$volver  = 'productos/index.php' . ($filtros ? '?' . http_build_query($filtros) : '');

if (!$id) {
    flash('error', 'Producto no válido.');
    redirigir($volver);
}

$st = db()->prepare('SELECT nombre FROM productos WHERE id = :id');
$st->execute([':id' => $id]);
$nombre = $st->fetchColumn();
if ($nombre === false) {
    flash('error', 'El producto no existe.');
    redirigir($volver);
}

$st = db()->prepare('UPDATE productos SET activo = :activo WHERE id = :id');
$st->execute([':activo' => $activo, ':id' => $id]);
auditar('producto_estado', "«{$nombre}» " . ($activo ? 'activado' : 'desactivado'), 'producto', $id);

flash('exito', $activo ? "«{$nombre}» está disponible de nuevo." : "«{$nombre}» fue desactivado.");
redirigir($volver);
