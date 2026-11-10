<?php
/**
 * Listas escolares: la lista de útiles de cada escuela y grado.
 * En el punto de venta se cargan completas con un clic.
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('productos');

$listas = db()->query("SELECT l.*, COUNT(lp.id) AS articulos,
        COALESCE(SUM(ROUND(lp.cantidad * p.precio, 2)), 0) AS total
    FROM listas l LEFT JOIN lista_productos lp ON lp.lista_id = l.id LEFT JOIN productos p ON p.id = lp.producto_id
    GROUP BY l.id ORDER BY l.activo DESC, sin_acentos(COALESCE(l.escuela, '')), sin_acentos(l.nombre)")->fetchAll();

$titulo  = 'Listas escolares';
$seccion = 'listas';
require __DIR__ . '/../includes/encabezado.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <p class="text-body-secondary mb-0">Arma la lista de útiles de cada escuela y grado. En el punto de venta se carga completa
        con el botón <strong>"Lista escolar"</strong> y quitas lo que el cliente ya tenga.</p>
    <a href="<?= e(url('listas/editar.php')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nueva lista</a>
</div>

<div class="card">
    <?php if (!$listas): ?>
        <div class="estado-vacio"><i class="bi bi-card-checklist"></i>Todavía no hay listas. En temporada de regreso a clases te ahorran mucho tiempo.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Lista</th><th>Escuela</th><th class="text-end">Artículos</th><th class="text-end">Total a precio actual</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($listas as $l): ?>
                    <tr class="fila-enlace<?= $l['activo'] ? '' : ' fila-inactiva' ?>" data-href="<?= e(url('listas/editar.php?id=' . (int) $l['id'])) ?>">
                        <td class="fw-semibold"><?= e($l['nombre']) ?></td>
                        <td><?= e($l['escuela'] ?? '') ?></td>
                        <td class="text-end"><?= (int) $l['articulos'] ?></td>
                        <td class="text-end num fw-semibold"><?= dinero($l['total']) ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-light border" href="<?= e(url('listas/imprimir.php?id=' . (int) $l['id'])) ?>" title="Imprimir"><i class="bi bi-printer"></i></a>
                            <a class="btn btn-sm btn-light border" href="<?= e(url('listas/editar.php?id=' . (int) $l['id'])) ?>" title="Editar"><i class="bi bi-pencil"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/pie.php'; ?>
