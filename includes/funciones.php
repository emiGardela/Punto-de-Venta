<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * TiendaWeb - Funciones de apoyo compartidas por todos los módulos.
 */

declare(strict_types=1);

/* =====================================================================
 *  Salida segura y formato
 * ===================================================================== */

/** Escapa texto para imprimirlo en HTML (protección contra XSS). */
function e(mixed $valor): string
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Construye una URL dentro del sistema: url('ventas/index.php'). */
function url(string $ruta = ''): string
{
    return BASE_URL . '/' . ltrim($ruta, '/');
}

/** Formato de moneda: $1,234.50 */
function dinero(mixed $monto): string
{
    $n = (float) ($monto ?? 0);
    return ($n < 0 ? '-' : '') . APP_MONEDA . number_format(abs($n), 2, '.', ',');
}

/** Formato de cantidad sin ceros sobrantes: 3, 1.5, 0.250 → 0.25 */
function cantidad_fmt(mixed $cantidad): string
{
    $txt = number_format((float) ($cantidad ?? 0), 3, '.', ',');
    return rtrim(rtrim($txt, '0'), '.');
}

/** Fecha y hora legibles: 20/10/2026 09:15 a. m. */
function fecha_fmt(?string $fecha, bool $conHora = true): string
{
    if ($fecha === null || $fecha === '') {
        return '';
    }
    $ts = strtotime($fecha);
    if ($ts === false) {
        return $fecha;
    }
    if (!$conHora) {
        return date('d/m/Y', $ts);
    }
    return date('d/m/Y', $ts) . ' ' . date('h:i', $ts) . (date('H', $ts) < 12 ? ' a. m.' : ' p. m.');
}

/** Folio con ceros a la izquierda: 000042 */
function folio(int|string $id): string
{
    return str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

/* =====================================================================
 *  Métodos de pago y estados
 * ===================================================================== */

const METODOS_PAGO = [
    'efectivo'      => ['etiqueta' => 'Efectivo',      'icono' => 'bi-cash-coin',       'color' => 'success'],
    'transferencia' => ['etiqueta' => 'Transferencia', 'icono' => 'bi-bank',            'color' => 'info'],
    'tarjeta'       => ['etiqueta' => 'Tarjeta',       'icono' => 'bi-credit-card-2-front', 'color' => 'primary'],
    'credito'       => ['etiqueta' => 'Crédito',       'icono' => 'bi-person-vcard',    'color' => 'warning'],
];

/**
 * Tasas de IVA. En México el precio al público ya incluye el IVA, así que
 * el IVA se calcula "hacia adentro" del precio.
 *   clave => [etiqueta, puntos base (1600 = 16%)]
 */
const TASAS_IVA = [
    '16'     => ['IVA 16% (general)', 1600],
    '8'      => ['IVA 8% (región fronteriza)', 800],
    '0'      => ['IVA 0% (libros, alimentos, medicinas…)', 0],
    'exento' => ['Exento de IVA', 0],
];

/** Unidades de medida: clave => [singular, abreviatura]. */
const UNIDADES = [
    'pieza'    => ['Pieza', 'pza'],
    'paquete'  => ['Paquete', 'paq'],
    'caja'     => ['Caja', 'caja'],
    'juego'    => ['Juego', 'jgo'],
    'kit'      => ['Kit', 'kit'],
    'hoja'     => ['Hoja', 'hoja'],
    'pliego'   => ['Pliego', 'plg'],
    'rollo'    => ['Rollo', 'rollo'],
    'metro'    => ['Metro', 'm'],
    'kilo'     => ['Kilo', 'kg'],
    'gramo'    => ['Gramo', 'g'],
    'litro'    => ['Litro', 'l'],
    'servicio' => ['Servicio', 'serv'],
];

/**
 * Precio unitario según la cantidad (precios por volumen o mayoreo).
 * $escalones: [[desde en milésimas, precio en centavos], ...]. Gana el escalón más alto alcanzado.
 */
function precio_por_cantidad(int $precioCentavos, array $escalones, int $milesimas): int
{
    $mejorDesde = 0;
    foreach ($escalones as [$desde, $precio]) {
        if ($milesimas >= $desde && $desde > $mejorDesde) {
            $mejorDesde = $desde;
            $precioCentavos = $precio;
        }
    }
    return $precioCentavos;
}

/** Escalones de precio por cantidad de varios productos: [producto_id => [[desde milésimas, precio centavos], ...]]. */
function escalones_de(array $productoIds): array
{
    if (!$productoIds) {
        return [];
    }
    $marcas = implode(',', array_fill(0, count($productoIds), '?'));
    $st = db()->prepare("SELECT producto_id, desde, precio FROM precios_volumen WHERE producto_id IN ($marcas) ORDER BY desde");
    $st->execute(array_values($productoIds));
    $escalones = [];
    foreach ($st->fetchAll() as $f) {
        $escalones[(int) $f['producto_id']][] = [a_milesimas($f['desde']), a_centavos($f['precio'])];
    }
    return $escalones;
}

/** IVA contenido en un importe que ya lo incluye, en centavos (redondeo al centavo). */
function iva_contenido(int $importeCentavos, ?string $tasa): int
{
    $bp = TASAS_IVA[$tasa][1] ?? 0;
    return $bp === 0 ? 0 : (int) round($importeCentavos * $bp / (10000 + $bp));
}

/**
 * Desglose de IVA de una venta a partir de sus renglones (detalle_ventas):
 * [tasa => ['base' => centavos sin IVA, 'iva' => centavos]]. Las ventas antiguas sin tasa no aparecen.
 */
function desglose_iva(array $detalle): array
{
    $desglose = [];
    foreach ($detalle as $d) {
        if (($d['tasa_iva'] ?? null) === null) {
            continue;
        }
        $neto = a_centavos($d['subtotal']) - a_centavos($d['descuento'] ?? 0);
        $iva  = a_centavos($d['iva'] ?? 0);
        $desglose[$d['tasa_iva']]['base'] = ($desglose[$d['tasa_iva']]['base'] ?? 0) + $neto - $iva;
        $desglose[$d['tasa_iva']]['iva']  = ($desglose[$d['tasa_iva']]['iva'] ?? 0) + $iva;
    }
    ksort($desglose);
    return $desglose;
}

/** Texto corto de una tasa para tickets: "IVA 16%", "IVA 0%", "Exento". */
function tasa_iva_corta(string $tasa): string
{
    return $tasa === 'exento' ? 'Exento' : 'IVA ' . $tasa . '%';
}

/** Formas en que un cliente puede abonar a su cuenta (todas menos crédito). */
const METODOS_ABONO = [
    'efectivo'      => 'Efectivo',
    'transferencia' => 'Transferencia',
    'tarjeta'       => 'Tarjeta',
];

/** Pagos de una venta: [metodo => monto]. Una venta "mixto" tiene varios. */
function pagos_venta(int $ventaId): array
{
    $st = db()->prepare('SELECT metodo, monto FROM pagos WHERE venta_id = :id ORDER BY id');
    $st->execute([':id' => $ventaId]);
    return array_map('floatval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Etiqueta de un método de pago, incluido "mixto" (pago dividido). */
function metodo_pago_etiqueta(string $metodo): string
{
    return $metodo === 'mixto' ? 'Pago dividido' : (METODOS_PAGO[$metodo]['etiqueta'] ?? $metodo);
}

function metodo_pago_badge(string $metodo): string
{
    $m = $metodo === 'mixto'
        ? ['etiqueta' => 'Pago dividido', 'icono' => 'bi-intersect', 'color' => 'secondary']
        : (METODOS_PAGO[$metodo] ?? ['etiqueta' => $metodo, 'icono' => 'bi-question-circle', 'color' => 'secondary']);
    return '<span class="badge rounded-pill text-bg-light border badge-metodo"><i class="bi ' . e($m['icono'])
        . ' text-' . e($m['color']) . ' me-1"></i>' . e($m['etiqueta']) . '</span>';
}

function estado_venta_badge(string $estado): string
{
    return $estado === 'cancelada'
        ? '<span class="badge rounded-pill text-bg-danger-subtle text-danger-emphasis"><i class="bi bi-x-circle me-1"></i>Cancelada</span>'
        : '<span class="badge rounded-pill text-bg-success-subtle text-success-emphasis"><i class="bi bi-check-circle me-1"></i>Completada</span>';
}

/* =====================================================================
 *  Navegación, avisos y respuestas
 * ===================================================================== */

function redirigir(string $ruta): void
{
    header('Location: ' . url($ruta));
    exit;
}

/** Guarda un aviso que se mostrará (con SweetAlert2) en la siguiente página. */
function flash(string $tipo, string $mensaje): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

/** Devuelve y borra los avisos pendientes. */
function flash_obtener(): array
{
    $avisos = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $avisos;
}

function peticion_espera_json(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xhr    = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return str_contains($accept, 'application/json') || strcasecmp($xhr, 'XMLHttpRequest') === 0;
}

function responder_json(array $datos, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/* =====================================================================
 *  Protección CSRF
 * ===================================================================== */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_valido(?string $token): bool
{
    return is_string($token) && $token !== '' && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Exige que la petición sea POST y traiga un token CSRF válido
 * (en el campo csrf_token o en la cabecera X-CSRF-Token).
 */
function requerir_post_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        if (peticion_espera_json()) {
            responder_json(['ok' => false, 'mensaje' => 'Método no permitido.'], 405);
        }
        exit('Método no permitido.');
    }
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!csrf_valido(is_string($token) ? $token : null)) {
        if (peticion_espera_json()) {
            responder_json(['ok' => false, 'mensaje' => 'La sesión expiró. Recarga la página e intenta de nuevo.'], 419);
        }
        flash('error', 'La sesión expiró o el formulario no es válido. Intenta de nuevo.');
        redirigir('index.php');
    }
}

/* =====================================================================
 *  Números exactos (dinero en centavos, cantidades en milésimas)
 * ===================================================================== */

/**
 * Interpreta un número escrito por el usuario. Acepta "35", "35.5", "35,50".
 * Devuelve el texto normalizado ("35.50") o null si no es válido.
 */
function normalizar_decimal(mixed $valor, int $decimales, int $enteros = 8): ?string
{
    if (is_int($valor) || is_float($valor)) {
        $valor = (string) $valor;
    }
    if (!is_string($valor)) {
        return null;
    }
    $txt = str_replace([' ', APP_MONEDA], '', trim($valor));
    if (substr_count($txt, ',') === 1 && !str_contains($txt, '.')) {
        $txt = str_replace(',', '.', $txt);      // coma decimal
    } else {
        $txt = str_replace(',', '', $txt);       // separador de miles
    }
    $patron = '/^\d{1,' . $enteros . '}(\.\d{0,' . $decimales . '})?$/';
    if (!preg_match($patron, $txt)) {
        return null;
    }
    return number_format((float) $txt, $decimales, '.', '');
}

function a_centavos(mixed $monto): int
{
    return (int) round(((float) $monto) * 100);
}

function a_milesimas(mixed $cantidad): int
{
    return (int) round(((float) $cantidad) * 1000);
}

function centavos_a_decimal(int $centavos): string
{
    return number_format($centavos / 100, 2, '.', '');
}

/** Importe exacto de precio × cantidad, redondeado al centavo. */
function importe_centavos(int $precioCentavos, int $cantidadMilesimas): int
{
    return (int) round($precioCentavos * $cantidadMilesimas / 1000);
}

/* =====================================================================
 *  Productos
 * ===================================================================== */

/**
 * Valida los datos del formulario de productos.
 * Devuelve [datos_limpios, errores]; errores es un arreglo campo => mensaje.
 */
function validar_producto(array $entrada): array
{
    $errores = [];
    $datos   = [];

    $nombre = trim(preg_replace('/\s+/u', ' ', (string) ($entrada['nombre'] ?? '')) ?? '');
    if ($nombre === '') {
        $errores['nombre'] = 'Escribe el nombre del producto.';
    } elseif (mb_strlen($nombre) > 150) {
        $errores['nombre'] = 'El nombre no puede tener más de 150 caracteres.';
    }
    $datos['nombre'] = $nombre;

    $codigo = trim((string) ($entrada['codigo_barras'] ?? ''));
    if ($codigo === '') {
        $codigo = null;
    } elseif (mb_strlen($codigo) > 64 || !preg_match('/^[A-Za-z0-9\-_.]+$/', $codigo)) {
        $errores['codigo_barras'] = 'El código solo puede tener letras, números, guiones o puntos (máx. 64).';
    }
    $datos['codigo_barras'] = $codigo;

    $precio = normalizar_decimal($entrada['precio'] ?? '', 2);
    if ($precio === null) {
        $errores['precio'] = 'Escribe un precio válido (por ejemplo 35 o 35.50).';
    }
    $datos['precio'] = $precio ?? '0.00';

    $costoTxt = trim((string) ($entrada['costo'] ?? ''));
    $costo = null;
    if ($costoTxt !== '') {
        $costo = normalizar_decimal($costoTxt, 2);
        if ($costo === null) {
            $errores['costo'] = 'Escribe un costo válido o déjalo vacío.';
        }
    }
    $datos['costo'] = $costo;

    $controlar = !empty($entrada['controlar_stock']) ? 1 : 0;
    $fraccion  = !empty($entrada['permite_fraccion']) ? 1 : 0;
    $datos['controlar_stock']  = $controlar;
    $datos['permite_fraccion'] = $fraccion;

    foreach (['existencia' => 'La existencia', 'stock_minimo' => 'El mínimo'] as $campo => $etiqueta) {
        if (!$controlar) {
            $datos[$campo] = '0.000';
            continue;
        }
        $txt = trim((string) ($entrada[$campo] ?? ''));
        if ($txt === '') {
            $txt = '0';
        }
        $valor = normalizar_decimal($txt, 3, 9);
        if ($valor === null) {
            $errores[$campo] = $etiqueta . ' debe ser un número igual o mayor que cero.';
            $valor = '0.000';
        } elseif (!$fraccion && a_milesimas($valor) % 1000 !== 0) {
            $errores[$campo] = $etiqueta . ' debe ser un número entero, o activa "Se vende en fracciones".';
        }
        $datos[$campo] = $valor;
    }

    // Catálogo (todos opcionales).
    $interno = trim((string) ($entrada['codigo_interno'] ?? ''));
    if ($interno !== '' && (mb_strlen($interno) > 30 || !preg_match('/^[A-Za-z0-9\-_.]+$/', $interno))) {
        $errores['codigo_interno'] = 'El código interno solo puede tener letras, números, guiones o puntos (máx. 30).';
    }
    $datos['codigo_interno'] = $interno !== '' ? $interno : null;
    $descripcion = trim((string) ($entrada['descripcion'] ?? ''));
    if (mb_strlen($descripcion) > 500) {
        $errores['descripcion'] = 'La descripción no puede tener más de 500 caracteres.';
    }
    $datos['descripcion'] = $descripcion !== '' ? $descripcion : null;
    $marca = trim(preg_replace('/\s+/u', ' ', (string) ($entrada['marca'] ?? '')) ?? '');
    if (mb_strlen($marca) > 60) {
        $errores['marca'] = 'La marca no puede tener más de 60 caracteres.';
    }
    $datos['marca'] = $marca !== '' ? $marca : null;
    $unidad = (string) ($entrada['unidad'] ?? 'pieza');
    if (!array_key_exists($unidad, UNIDADES)) {
        $errores['unidad'] = 'Elige una unidad de medida válida.';
        $unidad = 'pieza';
    }
    $datos['unidad'] = $unidad;
    foreach (['categoria_id' => 'categorias', 'proveedor_id' => 'proveedores'] as $campo => $tabla) {
        $id = filter_var($entrada[$campo] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        if ($id !== null) {
            $st = db()->prepare("SELECT COUNT(*) FROM $tabla WHERE id = :id");
            $st->execute([':id' => $id]);
            if ((int) $st->fetchColumn() === 0) {
                $errores[$campo] = 'La opción elegida ya no existe.';
                $id = null;
            }
        }
        $datos[$campo] = $id;
    }

    // Tipo: normal, paquete (N unidades de otro producto) o kit (varios productos).
    $tipo = (string) ($entrada['tipo'] ?? 'normal');
    $tipo = in_array($tipo, ['normal', 'paquete', 'kit'], true) ? $tipo : 'normal';
    $propioId = filter_var($entrada['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $datos['tipo'] = $tipo;
    $datos['producto_base_id'] = null;
    $datos['factor_base'] = null;
    $datos['componentes'] = [];
    $productoValido = function (int $id, bool $permitePaquete) use ($propioId): ?array {
        $st = db()->prepare('SELECT id, nombre, tipo, controlar_stock FROM productos WHERE id = :id');
        $st->execute([':id' => $id]);
        $p = $st->fetch() ?: null;
        if (!$p || (int) $p['id'] === $propioId || $p['tipo'] === 'kit' || (!$permitePaquete && $p['tipo'] !== 'normal')) {
            return null;
        }
        return $p;
    };
    if ($tipo === 'paquete') {
        $baseId = filter_var($entrada['producto_base_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $base = $baseId ? $productoValido($baseId, false) : null;
        $factor = normalizar_decimal($entrada['factor_base'] ?? '', 3, 6);
        if (!$base || !(int) $base['controlar_stock']) {
            $errores['producto_base_id'] = 'Elige el producto que contiene el paquete (uno normal que lleve inventario).';
        }
        if ($factor === null || a_milesimas($factor) <= 0) {
            $errores['factor_base'] = 'Escribe cuántas unidades trae el paquete.';
        }
        $datos['producto_base_id'] = $base ? (int) $base['id'] : null;
        $datos['factor_base'] = $factor;
    } elseif ($tipo === 'kit') {
        $ids = (array) ($entrada['kit_producto'] ?? []);
        $cantidades = (array) ($entrada['kit_cantidad'] ?? []);
        foreach ($ids as $i => $cid) {
            $cid = filter_var($cid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
            if (!$cid) {
                continue;
            }
            $cant = normalizar_decimal(($cantidades[$i] ?? '') === '' ? '1' : $cantidades[$i], 3, 6);
            if (!$productoValido($cid, true) || $cant === null || a_milesimas($cant) <= 0) {
                $errores['kit'] = 'Revisa los productos del kit: no puede incluir otro kit ni a sí mismo, y cada cantidad debe ser mayor que cero.';
                continue;
            }
            $datos['componentes'][$cid] = number_format(a_milesimas($cant) / 1000 + (float) ($datos['componentes'][$cid] ?? 0), 3, '.', '');
        }
        if (!$datos['componentes'] && !isset($errores['kit'])) {
            $errores['kit'] = 'Agrega al menos un producto al kit.';
        }
    }
    if ($tipo !== 'normal') {
        // La existencia vive en el producto base o en los componentes.
        $datos['controlar_stock'] = 0;
        $datos['existencia'] = '0.000';
        $datos['stock_minimo'] = '0.000';
        unset($errores['existencia'], $errores['stock_minimo']);
    }

    // Precios por cantidad: pares "desde" / "precio" (los renglones vacíos se ignoran).
    $escalones = [];
    $desdes = (array) ($entrada['volumen_desde'] ?? []);
    $precios = (array) ($entrada['volumen_precio'] ?? []);
    foreach ($desdes as $i => $desdeTxt) {
        $desdeTxt = trim((string) $desdeTxt);
        $precioTxt = trim((string) ($precios[$i] ?? ''));
        if ($desdeTxt === '' && $precioTxt === '') {
            continue;
        }
        $desde = normalizar_decimal($desdeTxt, 3, 9);
        $precioVol = normalizar_decimal($precioTxt, 2);
        if ($desde === null || $precioVol === null || a_milesimas($desde) <= 1000) {
            $errores['volumen'] = 'En precios por cantidad, "desde" debe ser mayor que 1 y el precio un monto válido.';
            continue;
        }
        if (isset($escalones[$desde])) {
            $errores['volumen'] = 'Hay dos precios por cantidad con el mismo "desde".';
        }
        $escalones[$desde] = $precioVol;
    }
    ksort($escalones, SORT_NUMERIC);
    $datos['precios_volumen'] = $escalones;

    $tasa = (string) ($entrada['tasa_iva'] ?? '16');
    if (!array_key_exists($tasa, TASAS_IVA)) {
        $errores['tasa_iva'] = 'Elige una tasa de IVA válida.';
        $tasa = '16';
    }
    $datos['tasa_iva'] = $tasa;

    $datos['activo'] = array_key_exists('activo', $entrada) ? (!empty($entrada['activo']) ? 1 : 0) : 1;

    return [$datos, $errores];
}

/** Guarda los campos de catálogo y los precios por cantidad de un producto (después del INSERT/UPDATE). */
function guardar_catalogo_producto(int $id, array $datos): void
{
    db()->prepare('UPDATE productos SET codigo_interno = :interno, descripcion = :descripcion, categoria_id = :categoria,
                                        marca = :marca, unidad = :unidad, proveedor_id = :proveedor WHERE id = :id')
        ->execute([':interno' => $datos['codigo_interno'], ':descripcion' => $datos['descripcion'], ':categoria' => $datos['categoria_id'],
                   ':marca' => $datos['marca'], ':unidad' => $datos['unidad'], ':proveedor' => $datos['proveedor_id'], ':id' => $id]);
    db()->prepare('UPDATE productos SET tipo = :tipo, producto_base_id = :base, factor_base = :factor WHERE id = :id')
        ->execute([':tipo' => $datos['tipo'] ?? 'normal', ':base' => $datos['producto_base_id'] ?? null,
                   ':factor' => $datos['factor_base'] ?? null, ':id' => $id]);
    db()->prepare('DELETE FROM kit_componentes WHERE kit_id = :id')->execute([':id' => $id]);
    $insComp = db()->prepare('INSERT INTO kit_componentes (kit_id, producto_id, cantidad) VALUES (:kit, :producto, :cantidad)');
    foreach ($datos['componentes'] ?? [] as $cid => $cantidad) {
        $insComp->execute([':kit' => $id, ':producto' => $cid, ':cantidad' => $cantidad]);
    }
    db()->prepare('DELETE FROM precios_volumen WHERE producto_id = :id')->execute([':id' => $id]);
    $ins = db()->prepare('INSERT INTO precios_volumen (producto_id, desde, precio) VALUES (:id, :desde, :precio)');
    foreach ($datos['precios_volumen'] as $desde => $precio) {
        $ins->execute([':id' => $id, ':desde' => $desde, ':precio' => $precio]);
    }
}

/** Comprueba si un código interno ya lo usa otro producto. */
function codigo_interno_duplicado(?string $codigo, ?int $excluirId = null): bool
{
    if ($codigo === null || $codigo === '') {
        return false;
    }
    $st = db()->prepare('SELECT COUNT(*) FROM productos WHERE codigo_interno = :c AND id <> :id');
    $st->execute([':c' => $codigo, ':id' => $excluirId ?? 0]);
    return (int) $st->fetchColumn() > 0;
}

/** Comprueba si un código de barras ya lo usa otro producto. */
function codigo_barras_duplicado(?string $codigo, ?int $excluirId = null): bool
{
    if ($codigo === null || $codigo === '') {
        return false;
    }
    $sql = 'SELECT id FROM productos WHERE codigo_barras = :codigo';
    $params = [':codigo' => $codigo];
    if ($excluirId !== null) {
        $sql .= ' AND id <> :id';
        $params[':id'] = $excluirId;
    }
    $st = db()->prepare($sql . ' LIMIT 1');
    $st->execute($params);
    return (bool) $st->fetchColumn();
}

/* =====================================================================
 *  Fechas (zona horaria de México)
 * ===================================================================== */

/** Valida una fecha AAAA-MM-DD; devuelve la fecha o null. */
function fecha_valida(mixed $fecha): ?string
{
    if (!is_string($fecha) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $fecha);
    return ($d && $d->format('Y-m-d') === $fecha) ? $fecha : null;
}

/**
 * Convierte un rango de días en límites para SQL:
 * fecha >= inicio AND fecha < fin  (aprovecha el índice por fecha).
 */
function rango_sql(string $desde, string $hasta): array
{
    if ($desde > $hasta) {
        [$desde, $hasta] = [$hasta, $desde];
    }
    $fin = (new DateTime($hasta))->modify('+1 day')->format('Y-m-d');
    return [$desde . ' 00:00:00', $fin . ' 00:00:00', $desde, $hasta];
}

/* =====================================================================
 *  Paginación
 * ===================================================================== */

/** Calcula página actual, total de páginas y desplazamiento. */
function paginar(int $totalRegistros, int $porPagina = APP_POR_PAGINA): array
{
    $paginas = max(1, (int) ceil($totalRegistros / $porPagina));
    $pagina  = filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
    $pagina  = min($pagina, $paginas);
    return [
        'pagina'    => $pagina,
        'paginas'   => $paginas,
        'total'     => $totalRegistros,
        'por_pagina'=> $porPagina,
        'offset'    => ($pagina - 1) * $porPagina,
    ];
}

/** Dibuja los controles de paginación conservando los filtros actuales. */
function paginacion_html(array $p): string
{
    $desde = $p['total'] === 0 ? 0 : $p['offset'] + 1;
    $hasta = min($p['offset'] + $p['por_pagina'], $p['total']);
    $html  = '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2 border-top">';
    $html .= '<small class="text-body-secondary">Mostrando ' . $desde . '–' . $hasta . ' de ' . $p['total'] . '</small>';

    if ($p['paginas'] > 1) {
        $enlace = function (int $n) {
            $q = $_GET;
            $q['pagina'] = $n;
            return '?' . http_build_query($q);
        };
        $html .= '<nav><ul class="pagination pagination-sm mb-0">';
        $html .= '<li class="page-item' . ($p['pagina'] <= 1 ? ' disabled' : '') . '"><a class="page-link" href="'
            . e($enlace(max(1, $p['pagina'] - 1))) . '" aria-label="Anterior"><i class="bi bi-chevron-left"></i></a></li>';

        $inicio = max(1, $p['pagina'] - 2);
        $fin    = min($p['paginas'], $p['pagina'] + 2);
        if ($inicio > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="' . e($enlace(1)) . '">1</a></li>';
            if ($inicio > 2) {
                $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
        }
        for ($i = $inicio; $i <= $fin; $i++) {
            $html .= '<li class="page-item' . ($i === $p['pagina'] ? ' active' : '') . '"><a class="page-link" href="'
                . e($enlace($i)) . '">' . $i . '</a></li>';
        }
        if ($fin < $p['paginas']) {
            if ($fin < $p['paginas'] - 1) {
                $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
            $html .= '<li class="page-item"><a class="page-link" href="' . e($enlace($p['paginas'])) . '">' . $p['paginas'] . '</a></li>';
        }
        $html .= '<li class="page-item' . ($p['pagina'] >= $p['paginas'] ? ' disabled' : '') . '"><a class="page-link" href="'
            . e($enlace(min($p['paginas'], $p['pagina'] + 1))) . '" aria-label="Siguiente"><i class="bi bi-chevron-right"></i></a></li>';
        $html .= '</ul></nav>';
    }
    return $html . '</div>';
}

/**
 * Descarga un CSV que Excel abre bien (UTF-8 con BOM y separador coma) y termina la petición.
 * Evita "inyección de fórmulas": un texto que empieza con = + - @ se antepone con un apóstrofo.
 */
function exportar_csv(string $nombreArchivo, array $encabezados, array $renglones): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    header('Cache-Control: no-store');
    $salida = fopen('php://output', 'w');
    fwrite($salida, "\xEF\xBB\xBF");
    $limpiar = fn($v) => is_string($v) && $v !== '' && str_contains('=+-@', $v[0]) && !is_numeric($v) ? "'" . $v : $v;
    fputcsv($salida, $encabezados, ',', '"', '');
    foreach ($renglones as $r) {
        fputcsv($salida, array_map($limpiar, array_values($r)), ',', '"', '');
    }
    fclose($salida);
    exit;
}

/** Escapa los comodines de LIKE para buscar texto literal. */
function like(string $texto): string
{
    return '%' . addcslashes($texto, '\\%_') . '%';
}

/** Un número entero (0 a 999,999,999) escrito con letra, en mayúsculas: 125 → "CIENTO VEINTICINCO". */
function numero_en_letra(int $n): string
{
    static $unidades = ['', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE', 'DIEZ',
        'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE', 'VEINTE',
        'VEINTIÚN', 'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE'];
    static $decenas = ['', '', '', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
    static $centenas = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];
    if ($n === 0) {
        return 'CERO';
    }
    $menosDeMil = function (int $n) use ($unidades, $decenas, $centenas): string {
        if ($n === 100) {
            return 'CIEN';
        }
        $partes = [];
        if ($n >= 100) {
            $partes[] = $centenas[intdiv($n, 100)];
            $n %= 100;
        }
        if ($n >= 30) {
            $partes[] = $decenas[intdiv($n, 10)] . ($n % 10 ? ' Y ' . $unidades[$n % 10] : '');
        } elseif ($n > 0) {
            $partes[] = $unidades[$n];
        }
        return implode(' ', $partes);
    };
    $partes = [];
    $millones = intdiv($n, 1000000);
    $miles = intdiv($n % 1000000, 1000);
    $resto = $n % 1000;
    if ($millones) {
        $partes[] = $millones === 1 ? 'UN MILLÓN' : $menosDeMil($millones) . ' MILLONES';
    }
    if ($miles) {
        $partes[] = $miles === 1 ? 'MIL' : $menosDeMil($miles) . ' MIL';
    }
    if ($resto) {
        $partes[] = $menosDeMil($resto);
    }
    return implode(' ', $partes);
}

/** Importe con letra como en las facturas: 12550 centavos → "CIENTO VEINTICINCO PESOS 50/100 M.N." */
function importe_en_letra(int $centavos): string
{
    $pesos = intdiv(abs($centavos), 100);
    $letra = numero_en_letra($pesos);
    // "UN" antes de "PESOS" se queda; "MILLÓN"/"MILLONES" llevan "DE".
    $de = preg_match('/MILL(ÓN|ONES)$/u', $letra) ? ' DE' : '';
    return $letra . $de . ($pesos === 1 ? ' PESO ' : ' PESOS ') . sprintf('%02d', abs($centavos) % 100) . '/100 M.N.';
}

/**
 * Renglones del desglose de IVA para el ticket: [[etiqueta, centavos], ...].
 * Ej.: "Sin IVA (16%)" $86.21 · "IVA 16%" $13.79 · "Tasa 0%" $32.00 · "Exento" $10.00
 */
function renglones_desglose_iva(array $detalle): array
{
    $r = [];
    foreach (desglose_iva($detalle) as $tasa => $d) {
        $tasa = (string) $tasa;
        if ($tasa === 'exento') {
            $r[] = ['Exento de IVA', $d['base']];
        } elseif ($tasa === '0') {
            $r[] = ['Con IVA 0%', $d['base']];
        } else {
            $r[] = ['Sin IVA (' . $tasa . '%)', $d['base']];
            $r[] = ['IVA ' . $tasa . '%', $d['iva']];
        }
    }
    return $r;
}
