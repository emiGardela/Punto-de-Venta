<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Descarga el XML o el PDF guardado de una factura.
 */
require_once __DIR__ . '/../includes/app.php';

if (!puede_administrar_facturas()) {
    negar_acceso('Tu usuario no puede ver los archivos de las facturas.');
}

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$tipo = ($_GET['tipo'] ?? '') === 'pdf' ? 'pdf' : 'xml';
$st = db()->prepare('SELECT nombre, contenido FROM factura_archivos WHERE factura_id = :id AND tipo = :tipo');
$st->execute([':id' => $id, ':tipo' => $tipo]);
$archivo = $st->fetch();
if (!$archivo) {
    http_response_code(404);
    flash('error', 'Ese archivo no existe.');
    redirigir('facturas/index.php');
}
header('Content-Type: ' . ($tipo === 'pdf' ? 'application/pdf' : 'application/xml; charset=utf-8'));
header('Content-Disposition: ' . ($tipo === 'pdf' ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '', $archivo['nombre']) . '"');
header('Cache-Control: no-store');
echo $archivo['contenido'];
