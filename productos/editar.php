<?php
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('productos');

$id = filter_var($_REQUEST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    flash('error', 'Producto no válido.');
    redirigir('productos/index.php');
}

$st = db()->prepare('SELECT * FROM productos WHERE id = :id');
$st->execute([':id' => $id]);
$original = $st->fetch();
if (!$original) {
    flash('error', 'El producto no existe.');
    redirigir('productos/index.php');
}

$errores  = [];
$producto = $original;
$producto['existencia']   = cantidad_fmt($original['existencia']);
// Componentes del kit y contenido del paquete, para mostrarlos en el formulario.
$st = db()->prepare('SELECT producto_id, cantidad FROM kit_componentes WHERE kit_id = :id ORDER BY id');
$st->execute([':id' => $id]);
$componentes = $st->fetchAll();
$producto['kit_producto'] = array_column($componentes, 'producto_id');
$producto['kit_cantidad'] = array_map(fn($c) => cantidad_fmt($c['cantidad']), $componentes);
$producto['factor_base'] = $original['factor_base'] !== null ? str_replace(',', '', cantidad_fmt($original['factor_base'])) : '';
// Precios por cantidad actuales, para mostrarlos en el formulario.
$st = db()->prepare('SELECT desde, precio FROM precios_volumen WHERE producto_id = :id ORDER BY desde');
$st->execute([':id' => $id]);
$producto['volumen_desde']  = array_map(fn($f) => cantidad_fmt($f['desde']), $st->fetchAll());
$st->execute([':id' => $id]);
$producto['volumen_precio'] = array_map(fn($f) => number_format((float) $f['precio'], 2, '.', ''), $st->fetchAll());
$producto['stock_minimo'] = cantidad_fmt($original['stock_minimo']);
foreach (['existencia', 'stock_minimo'] as $c) {
    $producto[$c] = str_replace(',', '', $producto[$c]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_post_csrf();
    [$datos, $errores] = validar_producto($_POST);

    if (!isset($errores['codigo_interno']) && codigo_interno_duplicado($datos['codigo_interno'], $id)) {
        $errores['codigo_interno'] = 'Ese código interno ya lo tiene otro producto.';
    }
    if (!isset($errores['codigo_barras']) && codigo_barras_duplicado($datos['codigo_barras'], $id)) {
        $errores['codigo_barras'] = 'Ese código de barras ya está registrado en otro producto.';
    }

    if (!$errores) {
        try {
            $st = db()->prepare('UPDATE productos SET
                    nombre = :nombre, codigo_barras = :codigo_barras, precio = :precio, costo = :costo,
                    existencia = :existencia, stock_minimo = :stock_minimo, controlar_stock = :controlar_stock,
                    permite_fraccion = :permite_fraccion, tasa_iva = :tasa_iva, activo = :activo
                WHERE id = :id');
            $st->execute([
                ':nombre'           => $datos['nombre'],
                ':codigo_barras'    => $datos['codigo_barras'],
                ':precio'           => $datos['precio'],
                ':costo'            => $datos['costo'],
                ':existencia'       => $datos['existencia'],
                ':stock_minimo'     => $datos['stock_minimo'],
                ':controlar_stock'  => $datos['controlar_stock'],
                ':permite_fraccion' => $datos['permite_fraccion'],
                ':tasa_iva'         => $datos['tasa_iva'],
                ':activo'           => $datos['activo'],
                ':id'               => $id,
            ]);
            // Si se cambió la existencia a mano, queda en el historial como ajuste.
            $diferencia = a_milesimas($datos['existencia']) - a_milesimas($original['existencia']);
            if ($datos['controlar_stock'] && $diferencia !== 0) {
                anotar_movimiento_inventario($id, (int) $original['controlar_stock'] ? 'ajuste' : 'inicial', $diferencia,
                    null, 'Cambio en la ficha del producto');
            }
            $cambios = [];
            foreach (['precio' => 'precio', 'costo' => 'costo'] as $campo => $etiqueta) {
                if (a_centavos($original[$campo] ?? 0) !== a_centavos($datos[$campo] ?? 0) || ($original[$campo] === null) !== ($datos[$campo] === null)) {
                    $cambios[] = "$etiqueta " . ($original[$campo] === null ? '—' : dinero($original[$campo])) . ' → '
                        . ($datos[$campo] === null ? '—' : dinero($datos[$campo]));
                }
            }
            if ($diferencia !== 0) {
                $cambios[] = 'existencia ' . cantidad_fmt($original['existencia']) . ' → ' . cantidad_fmt($datos['existencia']);
            }
            if ((int) $original['activo'] !== (int) $datos['activo']) {
                $cambios[] = $datos['activo'] ? 'activado' : 'desactivado';
            }
            guardar_catalogo_producto($id, $datos);
            auditar('producto_modificado', "«{$datos['nombre']}»" . ($cambios ? ': ' . implode(', ', $cambios) : ''), 'producto', $id);
            flash('exito', 'Cambios guardados en «' . $datos['nombre'] . '».');
            redirigir('productos/index.php');
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                $errores['codigo_barras'] = 'Ese código de barras ya está registrado en otro producto.';
            } else {
                throw $ex;
            }
        }
    }

    $producto = array_merge($producto, $_POST);
    $producto['id']               = $id;
    $producto['controlar_stock']  = !empty($_POST['controlar_stock']);
    $producto['permite_fraccion'] = !empty($_POST['permite_fraccion']);
    $producto['activo']           = !empty($_POST['activo']);
    if ($errores) {
        flash('error', 'Revisa los campos marcados en rojo.');
    }
}

// Ventas registradas de este producto (informativo).
$st = db()->prepare("SELECT COUNT(DISTINCT d.venta_id) AS ventas, COALESCE(SUM(d.cantidad), 0) AS unidades
                     FROM detalle_ventas d JOIN ventas v ON v.id = d.venta_id
                     WHERE d.producto_id = :id AND v.estado = 'completada'");
$st->execute([':id' => $id]);
$uso = $st->fetch();

$titulo    = 'Editar producto';
$seccion   = 'productos';
$accion    = url('productos/editar.php');
$esEdicion = true;
require __DIR__ . '/../includes/encabezado.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <nav aria-label="Ruta">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= e(url('productos/index.php')) ?>">Productos</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e($original['nombre']) ?></li>
        </ol>
    </nav>
    <small class="text-body-secondary">
        <i class="bi bi-clock-history me-1"></i>Registrado el <?= e(fecha_fmt($original['creado_en'], false)) ?>
        · aparece en <?= (int) $uso['ventas'] ?> venta(s), <?= e(cantidad_fmt($uso['unidades'])) ?> unidad(es) vendidas
        <?php if (permiso('inventario')): ?>
            · <a href="<?= e(url('inventario/index.php?producto=' . (int) $id)) ?>">ver historial de inventario</a>
        <?php endif; ?>
    </small>
</div>
<?php require __DIR__ . '/_formulario.php'; ?>
<?php require __DIR__ . '/../includes/pie.php'; ?>
