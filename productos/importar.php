<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Importar productos desde Excel (archivo CSV).
 *
 * - ?plantilla=1 descarga una plantilla con los encabezados.
 * - Se revisan TODOS los renglones antes de guardar: si alguno tiene error,
 *   no se importa nada y se muestra la lista de errores.
 * - Un producto ya existente (mismo código de barras, o mismo nombre si no
 *   tiene código) se actualiza; los demás se crean.
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('productos');

const IMPORTAR_MAX_RENGLONES = 5000;

// Encabezados aceptados (sin acentos ni mayúsculas) → campo.
const IMPORTAR_COLUMNAS = [
    'nombre' => 'nombre', 'producto' => 'nombre', 'articulo' => 'nombre',
    'descripcion' => 'descripcion', 'detalle' => 'descripcion',
    'codigo_interno' => 'codigo_interno', 'codigo interno' => 'codigo_interno', 'sku' => 'codigo_interno',
    'categoria' => 'categoria', 'departamento' => 'categoria', 'linea' => 'categoria',
    'marca' => 'marca', 'unidad' => 'unidad', 'unidad de medida' => 'unidad', 'proveedor' => 'proveedor',
    'mayoreo_desde' => 'mayoreo_desde', 'mayoreo desde' => 'mayoreo_desde', 'precio_mayoreo' => 'precio_mayoreo',
    'precio mayoreo' => 'precio_mayoreo', 'mayoreo' => 'precio_mayoreo', 'activo' => 'activo',
    'codigo' => 'codigo_barras', 'codigo_barras' => 'codigo_barras', 'codigo de barras' => 'codigo_barras', 'clave' => 'codigo_barras',
    'precio' => 'precio', 'precio_venta' => 'precio', 'precio de venta' => 'precio', 'venta' => 'precio',
    'costo' => 'costo', 'precio_costo' => 'costo', 'precio de costo' => 'costo', 'compra' => 'costo',
    'existencia' => 'existencia', 'existencias' => 'existencia', 'cantidad' => 'existencia', 'stock' => 'existencia', 'inventario' => 'existencia',
    'minimo' => 'stock_minimo', 'stock_minimo' => 'stock_minimo', 'stock minimo' => 'stock_minimo', 'avisar' => 'stock_minimo',
    'servicio' => 'servicio', 'granel' => 'granel', 'fraccion' => 'granel', 'fracciones' => 'granel',
    'iva' => 'tasa_iva', 'tasa_iva' => 'tasa_iva', 'impuesto' => 'tasa_iva',
];

if (isset($_GET['plantilla'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="plantilla-productos.csv"');
    echo "\xEF\xBB\xBF";   // BOM: Excel abre bien los acentos
    // Ejemplos del giro del negocio: uno con código y mayoreo, uno a granel y uno sin inventario (servicio).
    $giro = giro_actual();
    $ejemplos = $giro['productos'];
    $elegidos = array_values(array_filter([
        $ejemplos[0],
        current(array_filter($ejemplos, fn($p) => $p[7] === 1)) ?: null,
        current(array_filter($ejemplos, fn($p) => $p[6] === 0)) ?: null,
    ]));
    $salida = fopen('php://output', 'w');
    fputcsv($salida, ['nombre', 'codigo_barras', 'codigo_interno', 'categoria', 'marca', 'unidad', 'precio', 'costo', 'existencia', 'minimo',
                      'servicio', 'granel', 'iva', 'mayoreo_desde', 'precio_mayoreo', 'proveedor', 'descripcion'], ',', '"', '', "\r\n");
    foreach ($elegidos as $i => [$nombre, $codigo, $precio, $costo, $existencia, $minimo, $controla, $granel]) {
        $mayoreo = $i === 0;
        fputcsv($salida, [$nombre, $codigo ?? '', '', $giro['categorias'][$i] ?? '', '', $granel ? 'kg' : ($controla ? 'pieza' : 'servicio'),
                          $precio, $costo ?? '', $controla ? $existencia : '', $controla ? $minimo : '', $controla ? 'no' : 'si', $granel ? 'si' : 'no', '16',
                          $mayoreo ? 12 : '', $mayoreo ? round($precio * 0.9, 2) : '', '', ''], ',', '"', '', "\r\n");
    }
    exit;
}

/** "Sí", "si", "1", "x" → true. */
function importar_si(string $valor): bool
{
    return in_array(sin_acentos(trim($valor)), ['si', 's', '1', 'x', 'yes', 'verdadero'], true);
}

/** "16", "16%", "0.16", "exento" → clave de TASAS_IVA (vacío = 16). Un valor desconocido se devuelve tal cual para que la validación lo marque. */
function importar_tasa_iva(string $valor): string
{
    $v = str_replace([' ', '%'], '', sin_acentos($valor));
    return match (true) {
        $v === '' => '16',
        $v === 'exento', $v === 'ex' => 'exento',
        in_array($v, ['16', '0.16', '.16'], true) => '16',
        in_array($v, ['8', '0.08', '.08'], true) => '8',
        in_array($v, ['0', '0.0', '0.00'], true) => '0',
        default => $valor,
    };
}

/** Lee el CSV y devuelve [renglones (campo => valor, con número de renglón), errores]. */
function importar_leer(string $ruta): array
{
    $texto = (string) file_get_contents($ruta);
    if (str_starts_with($texto, "\xEF\xBB\xBF")) {
        $texto = substr($texto, 3);
    } elseif (!mb_check_encoding($texto, 'UTF-8')) {
        $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');   // CSV de Excel en español
    }
    $lineas = preg_split('/\r\n|\r|\n/', $texto);
    $primera = (string) ($lineas[0] ?? '');
    $separador = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : (str_contains($primera, "\t") ? "\t" : ',');

    $fh = fopen('php://temp', 'w+');
    fwrite($fh, $texto);
    rewind($fh);
    $encabezados = fgetcsv($fh, 0, $separador, '"', '');
    if (!$encabezados) {
        return [[], ['El archivo está vacío.']];
    }
    $mapa = [];
    foreach ($encabezados as $i => $titulo) {
        $clave = sin_acentos(trim((string) $titulo));
        if (isset(IMPORTAR_COLUMNAS[$clave])) {
            $mapa[$i] = IMPORTAR_COLUMNAS[$clave];
        }
    }
    // Un archivo que solo trae "descripción" la usa como nombre.
    if (!in_array('nombre', $mapa, true) && ($i = array_search('descripcion', $mapa, true)) !== false) {
        $mapa[$i] = 'nombre';
    }
    if (!in_array('nombre', $mapa, true) || !in_array('precio', $mapa, true)) {
        return [[], ['El archivo debe tener al menos las columnas "nombre" y "precio". Descarga la plantilla para ver el formato.']];
    }

    $renglones = [];
    $numero = 1;
    while (($fila = fgetcsv($fh, 0, $separador, '"', '')) !== false) {
        $numero++;
        if ($fila === [null] || implode('', array_map('trim', $fila)) === '') {
            continue;   // renglón vacío
        }
        $r = ['_renglon' => $numero];
        foreach ($mapa as $i => $campo) {
            $r[$campo] = trim((string) ($fila[$i] ?? ''));
        }
        $renglones[] = $r;
        if (count($renglones) > IMPORTAR_MAX_RENGLONES) {
            return [[], ['El archivo tiene más de ' . IMPORTAR_MAX_RENGLONES . ' productos. Divídelo en varios archivos.']];
        }
    }
    fclose($fh);
    $GLOBALS['importar_columnas'] = array_values($mapa);   // qué columnas trae el archivo
    return [$renglones, $renglones ? [] : ['El archivo no tiene productos debajo de los encabezados.']];
}

/** "Kilo", "kg", "pza", "Pieza" → clave de UNIDADES (vacío = pieza). */
function importar_unidad(string $valor): string
{
    $v = sin_acentos(trim($valor));
    if ($v === '') {
        return 'pieza';
    }
    foreach (UNIDADES as $clave => [$nombre, $abreviatura]) {
        if (in_array($v, [$clave, sin_acentos($nombre), sin_acentos($abreviatura), $clave . 's'], true)) {
            return $clave;
        }
    }
    return $valor;   // la validación lo marca
}

/** Id de la categoría o proveedor con ese nombre; si no existe, lo crea. */
function importar_id(string $tabla, string $nombre): ?int
{
    $nombre = trim(preg_replace('/\s+/u', ' ', $nombre) ?? '');
    if ($nombre === '') {
        return null;
    }
    $st = db()->prepare("SELECT id FROM $tabla WHERE sin_acentos(nombre) = sin_acentos(:n) LIMIT 1");
    $st->execute([':n' => $nombre]);
    $id = $st->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    db()->prepare("INSERT INTO $tabla (nombre) VALUES (:n)")->execute([':n' => mb_substr($nombre, 0, 60)]);
    return (int) db()->lastInsertId();
}

$errores = [];
$resultado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_post_csrf();
    $archivo = $_FILES['archivo'] ?? null;
    if (!is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errores[] = 'Elige el archivo CSV que guardaste desde Excel.';
    } else {
        [$renglones, $errores] = importar_leer($archivo['tmp_name']);
    }

    // 1. Validación completa (con las mismas reglas que el formulario de productos).
    $validos = [];
    $codigosVistos = [];
    $internosVistos = [];
    foreach ($renglones ?? [] as $r) {
        $n = $r['_renglon'];
        $servicio = importar_si($r['servicio'] ?? '');
        [$datos, $err] = validar_producto([
            'nombre'           => $r['nombre'] ?? '',
            'codigo_barras'    => $r['codigo_barras'] ?? '',
            'precio'           => $r['precio'] ?? '',
            'costo'            => $r['costo'] ?? '',
            'existencia'       => $r['existencia'] ?? '',
            'stock_minimo'     => ($r['stock_minimo'] ?? '') === '' ? '5' : $r['stock_minimo'],
            'controlar_stock'  => $servicio ? '' : '1',
            'permite_fraccion' => importar_si($r['granel'] ?? '') ? '1' : '',
            'tasa_iva'         => importar_tasa_iva($r['tasa_iva'] ?? ''),
            'codigo_interno'   => $r['codigo_interno'] ?? '',
            'descripcion'      => $r['descripcion'] ?? '',
            'marca'            => $r['marca'] ?? '',
            'unidad'           => importar_unidad($r['unidad'] ?? ''),
            'volumen_desde'    => [$r['mayoreo_desde'] ?? ''],
            'volumen_precio'   => [$r['precio_mayoreo'] ?? ''],
        ]);
        foreach ($err as $mensaje) {
            $errores[] = "Renglón $n: $mensaje";
        }
        if ($datos['codigo_interno'] !== null) {
            if (isset($internosVistos[$datos['codigo_interno']])) {
                $errores[] = "Renglón $n: el código interno {$datos['codigo_interno']} ya viene en el renglón {$internosVistos[$datos['codigo_interno']]}.";
            }
            $internosVistos[$datos['codigo_interno']] = $n;
        }
        $datos['_categoria'] = $r['categoria'] ?? '';
        $datos['_proveedor'] = $r['proveedor'] ?? '';
        $datos['_activo'] = isset($r['activo']) && $r['activo'] !== '' ? (importar_si($r['activo']) ? 1 : 0) : 1;
        if ($datos['codigo_barras'] !== null) {
            if (isset($codigosVistos[$datos['codigo_barras']])) {
                $errores[] = "Renglón $n: el código {$datos['codigo_barras']} ya viene en el renglón {$codigosVistos[$datos['codigo_barras']]}.";
            }
            $codigosVistos[$datos['codigo_barras']] = $n;
        }
        $datos['_trae_existencia'] = ($r['existencia'] ?? '') !== '';
        $datos['_trae_costo'] = ($r['costo'] ?? '') !== '';
        $validos[] = $datos;
    }
    $errores = array_slice($errores, 0, 50);

    // 2. Si todo está bien, se guarda todo junto (o nada).
    if (!$errores) {
        $resultado = ['nuevos' => 0, 'actualizados' => 0];
        transaccion_iniciar();
        try {
            $porCodigo = db()->prepare('SELECT * FROM productos WHERE codigo_barras = :c');
            $porNombre = db()->prepare('SELECT * FROM productos WHERE codigo_barras IS NULL AND sin_acentos(nombre) = sin_acentos(:n) LIMIT 1');
            $insertar = db()->prepare('INSERT INTO productos (nombre, codigo_barras, precio, costo, existencia, stock_minimo, controlar_stock, permite_fraccion, tasa_iva)
                                       VALUES (:nombre, :codigo, :precio, :costo, :existencia, :minimo, :controla, :fraccion, :tasa)');
            $columnas = $GLOBALS['importar_columnas'] ?? [];
            // Campos de catálogo: si el archivo no trae la columna, el producto existente conserva su valor.
            $catalogo = function (array $d, ?array $existente, int $productoId) use ($columnas): array {
                $c = $d;
                foreach (['codigo_interno' => 'codigo_interno', 'descripcion' => 'descripcion', 'marca' => 'marca', 'unidad' => 'unidad',
                          'categoria_id' => 'categoria', 'proveedor_id' => 'proveedor'] as $campo => $columna) {
                    if ($existente && !in_array($columna, $columnas, true)) {
                        $c[$campo] = $existente[$campo];
                    } elseif ($campo === 'categoria_id') {
                        $c[$campo] = importar_id('categorias', $d['_categoria']);
                    } elseif ($campo === 'proveedor_id') {
                        $c[$campo] = importar_id('proveedores', $d['_proveedor']);
                    }
                }
                if ($existente && !in_array('mayoreo_desde', $columnas, true) && !in_array('precio_mayoreo', $columnas, true)) {
                    $st = db()->prepare('SELECT desde, precio FROM precios_volumen WHERE producto_id = :id');
                    $st->execute([':id' => $productoId]);
                    $c['precios_volumen'] = $st->fetchAll(PDO::FETCH_KEY_PAIR);
                }
                if ($existente) {   // paquetes y kits se configuran en su ficha, no desde Excel
                    $c['tipo'] = $existente['tipo'];
                    $c['producto_base_id'] = $existente['producto_base_id'];
                    $c['factor_base'] = $existente['factor_base'];
                    $st = db()->prepare('SELECT producto_id, cantidad FROM kit_componentes WHERE kit_id = :id');
                    $st->execute([':id' => $productoId]);
                    $c['componentes'] = $st->fetchAll(PDO::FETCH_KEY_PAIR);
                }
                if (codigo_interno_duplicado($c['codigo_interno'], $productoId)) {
                    throw new RuntimeException("El código interno {$c['codigo_interno']} («{$d['nombre']}») ya lo tiene otro producto.");
                }
                return $c;
            };

            foreach ($validos as $d) {
                $existente = null;
                if ($d['codigo_barras'] !== null) {
                    $porCodigo->execute([':c' => $d['codigo_barras']]);
                    $existente = $porCodigo->fetch() ?: null;
                } else {
                    $porNombre->execute([':n' => $d['nombre']]);
                    $existente = $porNombre->fetch() ?: null;
                }

                if (!$existente) {
                    $insertar->execute([':nombre' => $d['nombre'], ':codigo' => $d['codigo_barras'], ':precio' => $d['precio'],
                        ':costo' => $d['costo'], ':existencia' => $d['existencia'], ':minimo' => $d['stock_minimo'],
                        ':controla' => $d['controlar_stock'], ':fraccion' => $d['permite_fraccion'], ':tasa' => $d['tasa_iva']]);
                    $nuevoId = (int) db()->lastInsertId();
                    if ($d['controlar_stock']) {
                        anotar_movimiento_inventario($nuevoId, 'importacion', a_milesimas($d['existencia']), $d['costo'], 'Importado de Excel');
                    }
                    guardar_catalogo_producto($nuevoId, $catalogo($d, null, $nuevoId));
                    if (!$d['_activo']) {
                        db()->prepare('UPDATE productos SET activo = 0 WHERE id = :id')->execute([':id' => $nuevoId]);
                    }
                    $resultado['nuevos']++;
                    continue;
                }

                // Existente: se actualizan nombre, precio, mínimo y tipo; costo y existencia solo si vienen en el archivo.
                $sets = ['nombre = :nombre', 'precio = :precio', 'stock_minimo = :minimo', 'controlar_stock = :controla', 'permite_fraccion = :fraccion', 'tasa_iva = :tasa', 'activo = :activo'];
                $params = [':nombre' => $d['nombre'], ':precio' => $d['precio'], ':minimo' => $d['stock_minimo'], ':activo' => $d['_activo'],
                           ':controla' => $d['controlar_stock'], ':fraccion' => $d['permite_fraccion'], ':tasa' => $d['tasa_iva'], ':id' => $existente['id']];
                if ($d['_trae_costo']) {
                    $sets[] = 'costo = :costo';
                    $params[':costo'] = $d['costo'];
                }
                if ($d['_trae_existencia'] && $d['controlar_stock']) {
                    $sets[] = 'existencia = :existencia';
                    $params[':existencia'] = $d['existencia'];
                }
                db()->prepare('UPDATE productos SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
                guardar_catalogo_producto((int) $existente['id'], $catalogo($d, $existente, (int) $existente['id']));
                $delta = a_milesimas($d['existencia']) - a_milesimas($existente['existencia']);
                if ($d['_trae_existencia'] && $d['controlar_stock'] && $delta !== 0) {
                    anotar_movimiento_inventario((int) $existente['id'], 'importacion', $delta, null, 'Actualizado desde Excel');
                }
                $resultado['actualizados']++;
            }
            transaccion_confirmar();
        } catch (Throwable $e) {
            transaccion_revertir();
            if (!($e instanceof RuntimeException) || $e instanceof PDOException) {
                throw $e;
            }
            $errores[] = $e->getMessage();
        }
    }
    if (!$errores && $resultado) {
        auditar('productos_importados', "Importó de Excel: {$resultado['nuevos']} nuevos y {$resultado['actualizados']} actualizados");
        flash('exito', "Importación terminada: {$resultado['nuevos']} producto(s) nuevos y {$resultado['actualizados']} actualizados.");
        redirigir('productos/index.php');
    }
}

$titulo  = 'Importar productos';
$seccion = 'productos';
require __DIR__ . '/../includes/encabezado.php';
?>
<nav aria-label="Ruta" class="mb-3"><ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= e(url('productos/index.php')) ?>">Productos</a></li>
    <li class="breadcrumb-item active" aria-current="page">Importar desde Excel</li>
</ol></nav>

<?php if ($errores): ?>
    <div class="alert alert-danger">
        <strong><i class="bi bi-x-octagon me-1"></i>No se importó nada.</strong> Corrige esto en tu archivo y vuelve a subirlo:
        <ul class="mb-0 mt-2"><?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h2><i class="bi bi-1-circle me-2 text-primary"></i>Prepara tu archivo</h2></div>
            <div class="card-body p-4">
                <ol class="mb-3">
                    <li>Descarga la plantilla y ábrela con Excel.</li>
                    <li>Escribe un producto por renglón. Solo <strong>nombre</strong> y <strong>precio</strong> son obligatorios.</li>
                    <li>En <em>servicio</em> pon "si" para copias, impresiones, etc. (no llevan inventario).
                        En <em>granel</em> pon "si" para lo que se vende por kilo o metro.
                        En <em>iva</em> pon 16, 8, 0 o exento (si lo dejas vacío, 16).
                        Las categorías y proveedores que no existan se crean solos.
                        Con <em>mayoreo_desde</em> y <em>precio_mayoreo</em> pones un precio por cantidad.</li>
                    <li>Guárdalo como <strong>"CSV UTF-8 (delimitado por comas)"</strong>.</li>
                </ol>
                <a href="?plantilla=1" class="btn btn-light border"><i class="bi bi-download me-1"></i>Descargar plantilla</a>
                <div class="form-text mt-3">Si un producto ya existe (mismo código de barras, o mismo nombre cuando no tiene código), se actualiza su precio y datos.
                    El costo y la existencia solo cambian si vienen escritos en el archivo.</div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h2><i class="bi bi-2-circle me-2 text-primary"></i>Súbelo</h2></div>
            <div class="card-body p-4">
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_campo() ?>
                    <input type="file" name="archivo" class="form-control form-control-lg mb-3" accept=".csv,text/csv" required>
                    <button type="submit" class="btn btn-primary btn-lg w-100"><i class="bi bi-upload me-1"></i>Revisar e importar</button>
                </form>
                <p class="small text-body-secondary mt-3 mb-0">Primero se revisa todo el archivo. Si hay algún error no se guarda nada, así no quedan productos a medias.</p>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/pie.php'; ?>
