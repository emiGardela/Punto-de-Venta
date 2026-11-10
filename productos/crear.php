<?php
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('productos');

$errores  = [];
$producto = [
    'nombre' => '', 'codigo_barras' => trim((string) ($_GET['codigo'] ?? '')), 'precio' => '', 'costo' => '',
    'existencia' => '0', 'stock_minimo' => '5', 'controlar_stock' => 1, 'permite_fraccion' => 0, 'activo' => 1, 'tasa_iva' => '16',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_post_csrf();
    [$datos, $errores] = validar_producto($_POST);
    unset($datos['activo']);

    if (!isset($errores['codigo_interno']) && codigo_interno_duplicado($datos['codigo_interno'])) {
        $errores['codigo_interno'] = 'Ese código interno ya lo tiene otro producto.';
    }
    if (!isset($errores['codigo_barras']) && codigo_barras_duplicado($datos['codigo_barras'])) {
        $errores['codigo_barras'] = 'Ese código de barras ya está registrado en otro producto.';
    }

    if (!$errores) {
        try {
            $st = db()->prepare('INSERT INTO productos
                    (nombre, codigo_barras, precio, costo, existencia, stock_minimo, controlar_stock, permite_fraccion, tasa_iva, activo)
                VALUES (:nombre, :codigo_barras, :precio, :costo, :existencia, :stock_minimo, :controlar_stock, :permite_fraccion, :tasa_iva, 1)');
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
            ]);
            $nuevoId = (int) db()->lastInsertId();
            if ($datos['controlar_stock']) {
                anotar_movimiento_inventario($nuevoId, 'inicial', a_milesimas($datos['existencia']), $datos['costo'], 'Alta del producto');
            }
            guardar_catalogo_producto($nuevoId, $datos);
            auditar('producto_creado', "«{$datos['nombre']}» a " . dinero($datos['precio']), 'producto', $nuevoId);
            flash('exito', 'Producto «' . $datos['nombre'] . '» registrado.');
            redirigir(isset($_POST['otro']) ? 'productos/crear.php' : 'productos/index.php');
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                $errores['codigo_barras'] = 'Ese código de barras ya está registrado en otro producto.';
            } else {
                throw $ex;
            }
        }
    }

    $producto = array_merge($producto, $_POST);
    $producto['controlar_stock']  = !empty($_POST['controlar_stock']);
    $producto['permite_fraccion'] = !empty($_POST['permite_fraccion']);
    if ($errores) {
        flash('error', 'Revisa los campos marcados en rojo.');
    }
}

$titulo    = 'Nuevo producto';
$seccion   = 'productos';
$accion    = url('productos/crear.php');
$esEdicion = false;
require __DIR__ . '/../includes/encabezado.php';
?>
<nav aria-label="Ruta" class="mb-3">
    <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= e(url('productos/index.php')) ?>">Productos</a></li>
        <li class="breadcrumb-item active" aria-current="page">Nuevo</li>
    </ol>
</nav>
<?php require __DIR__ . '/_formulario.php'; ?>
<?php require __DIR__ . '/../includes/pie.php'; ?>
