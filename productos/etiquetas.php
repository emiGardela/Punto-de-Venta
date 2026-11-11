<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Etiquetas con código de barras: elegir productos y cuántas etiquetas,
 * y el formato (hoja carta o rollo de impresora de etiquetas).
 */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/codigo_barras.php';

requerir_permiso('productos');

const FORMATOS_ETIQUETA = [
    'carta30'   => ['Hoja carta, 30 por hoja (3 × 10, tipo Avery 5160)', 66.7, 25.4],
    'carta65'   => ['Hoja carta, 65 por hoja (5 × 13, chicas)', 38.1, 21.2],
    'rollo5025' => ['Rollo de impresora de etiquetas 50 × 25 mm', 50, 25],
    'rollo4030' => ['Rollo de impresora de etiquetas 40 × 30 mm', 40, 30],
];

// Asignar códigos internos a productos sin código de barras (para poder escanearlos).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'asignar') {
    requerir_post_csrf();
    $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
    $asignados = 0;
    transaccion_iniciar();
    try {
        $st = db()->prepare('UPDATE productos SET codigo_barras = :codigo WHERE id = :id AND codigo_barras IS NULL');
        foreach ($ids as $id) {
            if ($id > 0 && !codigo_barras_duplicado(ean13_interno($id))) {
                $st->execute([':codigo' => ean13_interno($id), ':id' => $id]);
                $asignados += $st->rowCount();
            }
        }
        transaccion_confirmar();
    } catch (Throwable $e) {
        transaccion_revertir();
        throw $e;
    }
    auditar('producto_modificado', "Asignó código de barras interno a $asignados producto(s)");
    flash('exito', "Se asignó código de barras a $asignados producto(s). Ya puedes imprimir sus etiquetas.");
    redirigir('productos/etiquetas.php?' . http_build_query(['q' => $_POST['q'] ?? '', 'categoria' => $_POST['categoria'] ?? '']));
}

// Impresión: ?imprimir=1&formato=…&cant[id]=n
if (isset($_GET['imprimir'])) {
    $formato = array_key_exists($_GET['formato'] ?? '', FORMATOS_ETIQUETA) ? $_GET['formato'] : 'carta30';
    [, $ancho, $alto] = FORMATOS_ETIQUETA[$formato];
    $mostrarPrecio = !empty($_GET['precio']);
    $cantidades = array_filter(array_map('intval', (array) ($_GET['cant'] ?? [])), fn($n) => $n > 0 && $n <= 500);
    $etiquetas = [];
    if ($cantidades) {
        $marcas = implode(',', array_fill(0, count($cantidades), '?'));
        $st = db()->prepare("SELECT id, nombre, codigo_barras, codigo_interno, precio FROM productos WHERE id IN ($marcas) ORDER BY sin_acentos(nombre)");
        $st->execute(array_keys($cantidades));
        foreach ($st->fetchAll() as $p) {
            $codigo = $p['codigo_barras'] ?? $p['codigo_interno'];
            for ($i = 0; $i < $cantidades[(int) $p['id']]; $i++) {
                $etiquetas[] = $p + ['codigo' => $codigo];
            }
        }
    }
    $rollo = str_starts_with($formato, 'rollo');
    ?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Etiquetas · <?= e(APP_NOMBRE) ?></title>
    <style>
        @page { <?= $rollo ? "size: {$ancho}mm {$alto}mm; margin: 0;" : 'size: letter; margin: 12.7mm 4.8mm;' ?> }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; }
        .barra { padding: 10px; background: #f3f6fb; border-bottom: 1px solid #ccc; font-size: 14px; }
        .hoja { display: flex; flex-wrap: wrap; <?= $rollo ? '' : 'gap: 0 3.2mm;' ?> }
        .etiqueta { width: <?= $ancho ?>mm; height: <?= $alto ?>mm; padding: 1.2mm 2mm; overflow: hidden;
                    display: flex; flex-direction: column; justify-content: space-between; <?= $rollo ? 'page-break-after: always;' : '' ?> }
        .nombre { font-size: <?= $alto < 22 ? '6.5pt' : '8pt' ?>; line-height: 1.1; max-height: 2.2em; overflow: hidden; }
        .precio { font-size: <?= $alto < 22 ? '9pt' : '12pt' ?>; font-weight: bold; }
        .codigo svg { width: 100%; height: <?= $alto < 22 ? '6mm' : '9mm' ?>; display: block; }
        .texto { font-size: 6.5pt; text-align: center; letter-spacing: .5px; }
        @media print { .barra { display: none; } }
        @media screen { .etiqueta { outline: 1px dashed #bbb; margin: 2px; } }
    </style>
</head>
<body>
<div class="barra">
    <a href="<?= e(url('productos/etiquetas.php')) ?>">← Regresar</a> ·
    <?= count($etiquetas) ?> etiqueta(s), <?= e(FORMATOS_ETIQUETA[$formato][0]) ?> ·
    <button onclick="window.print()">Imprimir</button>
    <small>En el cuadro de impresión elige "Tamaño real" (100 %) y sin encabezados ni pies de página.</small>
</div>
<div class="hoja">
    <?php foreach ($etiquetas as $p): ?>
        <div class="etiqueta">
            <div class="nombre"><?= e($p['nombre']) ?></div>
            <?php if ($mostrarPrecio): ?><div class="precio"><?= dinero($p['precio']) ?></div><?php endif; ?>
            <?php if ($p['codigo'] !== null): ?>
                <div class="codigo"><?= code128_svg($p['codigo']) ?></div>
                <div class="texto"><?= e($p['codigo']) ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>
<?php
    exit;
}

// Selección de productos.
$q = trim((string) ($_GET['q'] ?? ''));
$categoria = filter_var($_GET['categoria'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$where = ['p.activo = 1'];
$params = [];
if ($q !== '') {
    $where[] = "(sin_acentos(p.nombre) LIKE sin_acentos(:q) ESCAPE '\\' OR p.codigo_barras LIKE :q2 ESCAPE '\\' OR p.codigo_interno LIKE :q3 ESCAPE '\\')";
    $params[':q'] = $params[':q2'] = $params[':q3'] = like($q);
}
if ($categoria) {
    $where[] = 'p.categoria_id = :categoria';
    $params[':categoria'] = $categoria;
}
$st = db()->prepare('SELECT p.id, p.nombre, p.codigo_barras, p.codigo_interno, p.precio, p.existencia, p.controlar_stock
                     FROM productos p WHERE ' . implode(' AND ', $where) . ' ORDER BY sin_acentos(p.nombre) LIMIT 300');
$st->execute($params);
$productos = $st->fetchAll();
$sinCodigo = array_filter($productos, fn($p) => $p['codigo_barras'] === null);
$categorias = db()->query('SELECT id, nombre FROM categorias WHERE activo = 1 ORDER BY sin_acentos(nombre)')->fetchAll(PDO::FETCH_KEY_PAIR);

$titulo  = 'Etiquetas';
$seccion = 'productos';
require __DIR__ . '/../includes/encabezado.php';
?>
<nav aria-label="Ruta" class="mb-3"><ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= e(url('productos/index.php')) ?>">Productos</a></li>
    <li class="breadcrumb-item active" aria-current="page">Etiquetas con código de barras</li>
</ol></nav>

<div class="card mb-3">
    <form class="barra-filtros row g-2 align-items-end" method="get">
        <div class="col-md-5">
            <label for="q" class="form-label small mb-1">Buscar</label>
            <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>" placeholder="Nombre o código">
        </div>
        <div class="col-md-4">
            <label for="categoria" class="form-label small mb-1">Categoría</label>
            <select class="form-select" id="categoria" name="categoria">
                <option value="">Todas</option>
                <?php foreach ($categorias as $cid => $cn): ?><option value="<?= (int) $cid ?>" <?= $categoria === (int) $cid ? 'selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel me-1"></i>Filtrar</button></div>
    </form>
</div>

<?php if ($sinCodigo): ?>
    <form method="post" class="alert alert-info d-flex flex-wrap align-items-center gap-2">
        <?= csrf_campo() ?>
        <input type="hidden" name="accion" value="asignar">
        <input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="categoria" value="<?= (int) $categoria ?>">
        <?php foreach ($sinCodigo as $p): ?><input type="hidden" name="ids[]" value="<?= (int) $p['id'] ?>"><?php endforeach; ?>
        <i class="bi bi-upc"></i>
        <span><?= count($sinCodigo) ?> producto(s) de esta lista no tienen código de barras. Puedes asignarles uno interno (empieza con 200)
            para pegarles etiqueta y escanearlos.</span>
        <button type="submit" class="btn btn-sm btn-primary ms-auto">Asignar códigos</button>
    </form>
<?php endif; ?>

<form method="get" target="_blank">
    <input type="hidden" name="imprimir" value="1">
    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <select class="form-select w-auto" name="formato" aria-label="Formato">
                <?php foreach (FORMATOS_ETIQUETA as $clave => [$etiqueta]): ?><option value="<?= e($clave) ?>"><?= e($etiqueta) ?></option><?php endforeach; ?>
            </select>
            <div class="form-check ms-2">
                <input class="form-check-input" type="checkbox" name="precio" value="1" id="conPrecio" checked>
                <label class="form-check-label" for="conPrecio">Con precio</label>
            </div>
            <button type="button" class="btn btn-sm btn-light border ms-auto" id="btnUno">1 de cada uno</button>
            <button type="button" class="btn btn-sm btn-light border" id="btnExistencia">Según existencia</button>
            <button type="button" class="btn btn-sm btn-light border" id="btnCero">Ninguna</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-printer me-1"></i>Ver e imprimir</button>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Producto</th><th>Código</th><th class="text-end">Precio</th><th style="width:120px">Etiquetas</th></tr></thead>
                <tbody>
                <?php foreach ($productos as $p): ?>
                    <tr>
                        <td><?= e($p['nombre']) ?></td>
                        <td class="small"><?= e($p['codigo_barras'] ?? $p['codigo_interno'] ?? '') ?: '<span class="text-danger">sin código</span>' ?></td>
                        <td class="text-end num"><?= dinero($p['precio']) ?></td>
                        <td><input type="number" class="form-control form-control-sm cant-etiqueta" name="cant[<?= (int) $p['id'] ?>]" min="0" max="500" value="0"
                                   data-existencia="<?= $p['controlar_stock'] ? (int) floor((float) $p['existencia']) : 1 ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>
<script>
(function () {
    const fijar = f => document.querySelectorAll('.cant-etiqueta').forEach(i => { i.value = f(i); });
    document.getElementById('btnUno').addEventListener('click', () => fijar(() => 1));
    document.getElementById('btnExistencia').addEventListener('click', () => fijar(i => Math.min(500, Number(i.dataset.existencia) || 0)));
    document.getElementById('btnCero').addEventListener('click', () => fijar(() => 0));
})();
</script>
<?php require __DIR__ . '/../includes/pie.php'; ?>
