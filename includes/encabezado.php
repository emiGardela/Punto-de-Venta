<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Encabezado y menú lateral comunes.
 * Antes de incluirlo, cada página define:
 *   $titulo  - título de la página
 *   $seccion - clave del menú ($menu) que se marca como activa
 */
$titulo  = $titulo ?? APP_NOMBRE;
$seccion = $seccion ?? '';

// clave => [etiqueta, icono, ruta, permiso necesario (null = todos, 'dueno' = solo el dueño), solo con funciones avanzadas]
// Usuarios, respaldos y bitácora están dentro de Configuración; proveedores, dentro de Compras.
// Ventas hechas se abre desde Vender. Inventario, desde Productos; quien no puede entrar a Productos lo conserva aquí.
$menu = [
    'inicio'     => ['Inicio',            'bi-house',           'index.php',            null,         false],
    'pos'        => ['Vender',            'bi-cart3',           'ventas/index.php',     null,         false],
    'caja'       => ['Caja y corte',      'bi-cash-coin',       'caja/index.php',       'caja',       false],
    'historial'  => ['Ventas hechas',     'bi-receipt',         'ventas/historial.php', null,         false],
    'productos'  => ['Productos',         'bi-box-seam',        'productos/index.php',  'productos',  false],
    'inventario' => ['Inventario',        'bi-boxes',           'inventario/index.php', 'inventario', false],
    'compras'    => ['Compras',           'bi-bag-check',       'compras/index.php',    'inventario', true],
    'listas'     => ['Listas escolares',  'bi-card-checklist',  'listas/index.php',     'productos',  true],
    'clientes'   => ['Clientes y fiado',  'bi-person-vcard',    'clientes/index.php',   'credito',    false],
    'reportes'   => ['Reportes',          'bi-bar-chart-line',  'reportes/index.php',   'reportes',   false],
    'ajustes'    => ['Configuración',     'bi-gear',            'ajustes.php',          'dueno',      false],
];
$avanzadas = funciones_avanzadas();
$seccionMenu = ['historial' => 'pos', 'inventario' => permiso('productos') ? 'productos' : 'inventario'][$seccion] ?? $seccion;
$fueraDelMenu = ['historial' => true, 'inventario' => permiso('productos'), 'listas' => !usa_listas_escolares() && $seccion !== 'listas'];
$menu = array_filter($menu, fn($m, $clave) => empty($fueraDelMenu[$clave]) && ($avanzadas || !$m[4] || $seccion === $clave)
    && ($m[3] === null || ($m[3] === 'dueno' ? es_dueno() : permiso($m[3]))), ARRAY_FILTER_USE_BOTH);
$usuarioActual = usuario_actual();
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($titulo) ?> · <?= e(APP_NOMBRE) ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><rect width='16' height='16' rx='4' fill='%231d5fd1'/><path d='M4 5h8l-1 5H5z' fill='white'/></svg>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/estilos.css')) ?>?v=<?= e(APP_VERSION) ?>">
</head>
<body class="<?= $seccion === 'pos' ? 'pagina-pos' : '' ?>">

<aside class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="menuLateral" aria-label="Menú principal">
    <div class="sidebar-marca">
        <a href="<?= e(url('index.php')) ?>" class="d-flex align-items-center gap-2 text-decoration-none">
            <span class="marca-logo"><i class="bi bi-shop"></i></span>
            <span>
                <span class="marca-nombre"><?= e(APP_NOMBRE) ?></span>
                <span class="marca-sub d-block"><?= e(nombre_negocio()) ?></span>
            </span>
        </a>
        <button type="button" class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#menuLateral" aria-label="Cerrar menú"></button>
    </div>
    <nav class="sidebar-nav">
        <?php foreach ($menu as $clave => [$etiqueta, $icono, $ruta]): ?>
            <a href="<?= e(url($ruta)) ?>" class="sidebar-link<?= $seccionMenu === $clave ? ' activo' : '' ?>"<?= $seccionMenu === $clave ? ' aria-current="page"' : '' ?>>
                <i class="bi <?= e($icono) ?>"></i><span><?= e($etiqueta) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-pie">
        <a href="<?= e(url('ventas/index.php')) ?>" class="btn btn-primary w-100 btn-lg fw-semibold">
            <i class="bi bi-upc-scan me-2"></i>Nueva venta
        </a>
        <small class="d-block text-center mt-3 opacity-50">v<?= e(APP_VERSION) ?></small>
    </div>
</aside>

<div class="contenido">
    <header class="barra-superior">
        <button class="btn btn-light d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#menuLateral" aria-controls="menuLateral" aria-label="Abrir menú">
            <i class="bi bi-list fs-5"></i>
        </button>
        <h1 class="barra-titulo"><?= e($titulo) ?></h1>
        <div class="ms-auto d-flex align-items-center gap-3">
            <span class="d-none d-md-inline text-body-secondary small" id="relojSistema" data-fecha="<?= e(date('c')) ?>">
                <i class="bi bi-calendar3 me-1"></i><?= e(fecha_fmt(date('Y-m-d H:i:s'))) ?>
            </span>
            <a href="<?= e(url('ayuda.php')) ?>" class="btn btn-light border btn-sm d-inline-flex align-items-center<?= $seccion === 'ayuda' ? ' active' : '' ?>" title="Cómo se usa">
                <i class="bi bi-question-circle me-1"></i>Ayuda
            </a>
            <a href="<?= e(url('ventas/index.php')) ?>" class="btn btn-primary btn-sm d-none d-sm-inline-flex align-items-center">
                <i class="bi bi-cart-plus me-1"></i>Vender
            </a>
            <?php if ($usuarioActual): ?>
                <div class="dropdown">
                    <button class="btn btn-light btn-sm border usuario-barra dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="login-inicial"><?= e(mb_strtoupper(mb_substr($usuarioActual['nombre'], 0, 1))) ?></span>
                        <span class="d-none d-md-inline"><?= e($usuarioActual['nombre']) ?></span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end">
                        <span class="dropdown-item-text small text-body-secondary"><?= $usuarioActual['rol'] === 'dueno' ? 'Dueño' : 'Cajero' ?></span>
                        <form method="post" action="<?= e(url('salir.php')) ?>">
                            <?= csrf_campo() ?>
                            <button type="submit" class="dropdown-item"><i class="bi bi-arrow-left-right me-2"></i>Cambiar de usuario</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </header>
    <main class="area-principal">
