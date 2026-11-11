<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Pestañas de la sección Configuración (solo el dueño): configuración,
 * usuarios, respaldos y bitácora. Se incluye después del encabezado.
 */
$pestanas = [
    'ajustes.php'   => ['Configuración', 'bi-gear'],
    'usuarios.php'  => ['Usuarios', 'bi-people'],
    'respaldos.php' => ['Respaldos', 'bi-shield-check'],
    'auditoria.php' => ['Bitácora', 'bi-journal-text'],
];
$pestanaActual = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
?>
<ul class="nav nav-pills gap-1 mb-4 no-imprimir">
    <?php foreach ($pestanas as $ruta => [$etiqueta, $icono]): ?>
        <li class="nav-item">
            <a class="nav-link<?= $pestanaActual === $ruta ? ' active' : '' ?>" href="<?= e(url($ruta)) ?>"<?= $pestanaActual === $ruta ? ' aria-current="page"' : '' ?>>
                <i class="bi <?= e($icono) ?> me-1"></i><?= e($etiqueta) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
