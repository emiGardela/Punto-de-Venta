<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Lista escolar imprimible (para entregar a los papás como cotización).
 */
require_once __DIR__ . '/../includes/app.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$st = db()->prepare('SELECT * FROM listas WHERE id = :id');
$st->execute([':id' => $id]);
$lista = $st->fetch();
if (!$lista) {
    flash('error', 'La lista no existe.');
    redirigir('listas/index.php');
}
$st = db()->prepare('SELECT lp.cantidad, p.nombre, p.precio, p.marca FROM lista_productos lp JOIN productos p ON p.id = lp.producto_id
                     WHERE lp.lista_id = :id ORDER BY lp.id');
$st->execute([':id' => $id]);
$items = $st->fetchAll();
$total = array_sum(array_map(fn($i) => importe_centavos(a_centavos($i['precio']), a_milesimas($i['cantidad'])), $items)) / 100;
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= e($lista['nombre']) ?> · <?= e(nombre_negocio() ?: APP_NOMBRE) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <style>
        body { background: #fff; font-size: 14px; }
        .hoja { max-width: 720px; margin: 24px auto; }
        @media print { .no-imprimir { display: none !important; } .hoja { margin: 0 auto; } }
    </style>
</head>
<body>
<div class="hoja">
    <div class="d-flex justify-content-between no-imprimir mb-3">
        <a href="<?= e(url('listas/editar.php?id=' . $id)) ?>" class="btn btn-light border btn-sm">← Regresar</a>
        <button onclick="window.print()" class="btn btn-primary btn-sm">Imprimir</button>
    </div>
    <div class="text-center mb-3">
        <h1 class="h4 fw-bold mb-0"><?= e(nombre_negocio()) ?></h1>
        <?php if (ajuste('negocio_datos') !== ''): ?><div class="small" style="white-space:pre-line"><?= e(ajuste('negocio_datos')) ?></div><?php endif; ?>
    </div>
    <h2 class="h5 mb-0">Lista de útiles: <?= e($lista['nombre']) ?></h2>
    <?php if ($lista['escuela']): ?><div class="text-body-secondary"><?= e($lista['escuela']) ?></div><?php endif; ?>
    <table class="table table-sm mt-3">
        <thead><tr><th style="width:40px"></th><th class="text-end" style="width:70px">Cant.</th><th>Artículo</th><th class="text-end">Precio</th><th class="text-end">Importe</th></tr></thead>
        <tbody>
        <?php foreach ($items as $i): ?>
            <tr>
                <td>☐</td>
                <td class="text-end"><?= e(cantidad_fmt($i['cantidad'])) ?></td>
                <td><?= e($i['nombre']) ?><?= $i['marca'] ? ' <span class="text-body-secondary">(' . e($i['marca']) . ')</span>' : '' ?></td>
                <td class="text-end"><?= dinero($i['precio']) ?></td>
                <td class="text-end"><?= dinero(importe_centavos(a_centavos($i['precio']), a_milesimas($i['cantidad'])) / 100) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="fw-bold"><td colspan="4" class="text-end">Total</td><td class="text-end"><?= dinero($total) ?></td></tr></tfoot>
    </table>
    <?php if ($lista['notas']): ?><p style="white-space:pre-line"><strong>Notas:</strong> <?= e($lista['notas']) ?></p><?php endif; ?>
    <p class="small text-body-secondary">Precios al <?= e(fecha_fmt(date('Y-m-d H:i:s'), false)) ?>, sujetos a cambio y a existencias.</p>
</div>
</body>
</html>
