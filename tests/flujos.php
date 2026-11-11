<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Pruebas de punta a punta: un servidor de prueba con base propia y un
 * "navegador" que usa el sistema como lo haría el cajero.
 */

declare(strict_types=1);

$raiz = dirname(__DIR__);

/* ---------------------------------------------------------------------
 *  Sesión perdida: nunca se pierde lo escrito ni queda sin explicación.
 * --------------------------------------------------------------------- */
$srv = new ServidorPrueba($raiz);
$nav = new Navegador($srv->url);
try {
    Pruebas::grupo('Sesión perdida');
    $nav->get('bienvenida.php');
    $h = $nav->post('bienvenida.php', ['csrf_token' => 'vencido', 'giro' => 'licoreria', 'negocio_nombre' => 'Mi tienda', 'negocio_datos' => 'Calle 1',
        'productos' => 'ejemplos', 'proteger' => '1', 'nombre' => 'Pepe', 'pin' => '1234', 'pin2' => '1234']);
    Pruebas::igual(0, (int) $srv->valor('SELECT COUNT(*) FROM usuarios'), 'el asistente no guarda sin token válido');
    Pruebas::igual(0, (int) $srv->valor('SELECT COUNT(*) FROM productos'), 'ni carga productos');
    Pruebas::contiene('value="Mi tienda"', $h, 'conserva el nombre del negocio');
    Pruebas::contiene('Calle 1', $h, 'conserva los datos del ticket');
    Pruebas::contiene('value="Pepe"', $h, 'conserva el nombre del dueño');
    Pruebas::verdadero((bool) preg_match('/id="giro_licoreria"[^>]*checked/', $h), 'conserva el giro');
    Pruebas::contiene('Tus datos siguen aquí', $h, 'explica qué pasó');
    $nav->enviar('bienvenida.php', ['giro' => 'licoreria', 'negocio_nombre' => 'Mi tienda', 'productos' => 'ejemplos',
        'proteger' => '1', 'nombre' => 'Pepe', 'pin' => '1234', 'pin2' => '1234'], 'bienvenida.php');
    Pruebas::igual(1, (int) $srv->valor('SELECT COUNT(*) FROM usuarios'), 'al reintentar sí se guarda');
    $nav->enviar('salir.php', []);
    $nav->post('login.php', ['csrf_token' => 'vencido', 'usuario' => 1, 'pin' => '1234']);
    Pruebas::contiene('La sesión expiró', $nav->get('login.php'), 'la pantalla de PIN explica una sesión vencida');
} finally {
    $srv->detener();
}

/* ---------------------------------------------------------------------
 *  Tipos de negocio: el asistente y lo que cambia con cada giro.
 * --------------------------------------------------------------------- */
foreach (GIROS as $clave => $giro) {
    $srv = new ServidorPrueba($raiz);
    $nav = new Navegador($srv->url);
    try {
        Pruebas::grupo('Giro: ' . $giro['etiqueta']);
        $nav->get('index.php');
        Pruebas::contiene('value="' . $clave . '"', $nav->get('bienvenida.php'), 'el asistente ofrece este giro');
        $nav->enviar('bienvenida.php', ['giro' => $clave, 'negocio_nombre' => 'Mi negocio', 'productos' => 'ejemplos'], 'bienvenida.php');
        Pruebas::igual($clave, $srv->valor("SELECT valor FROM ajustes WHERE clave = 'giro'"), 'guarda el giro');
        Pruebas::igual(count($giro['productos']), (int) $srv->valor('SELECT COUNT(*) FROM productos'), 'carga sus productos de ejemplo');
        Pruebas::igual(0, (int) $srv->valor("SELECT COUNT(*) FROM productos WHERE tasa_iva <> '16'"), 'todos con IVA 16%');
        Pruebas::igual(count($giro['productos']), (int) $srv->valor('SELECT COUNT(DISTINCT nombre) FROM productos'), 'sin nombres repetidos');
        Pruebas::contiene($giro['categorias'][0], $nav->get('productos/categorias.php'), 'sugiere sus categorías');
        $plantilla = $nav->get('productos/importar.php?plantilla=1');
        Pruebas::contiene($giro['productos'][0][0], $plantilla, 'la plantilla de Excel trae ejemplos del giro');
        if (in_array($clave, ['abarrotes', 'licoreria'], true)) {
            Pruebas::verdadero(!str_contains($plantilla, 'Cuaderno') && !str_contains($plantilla, 'Lápiz'), 'y no trae ejemplos de papelería');
        }
        $antes = (int) $srv->valor('SELECT COUNT(*) FROM productos');
        $nav->subir('productos/importar.php', [], 'archivo', 'plantilla-productos.csv', $plantilla);
        Pruebas::igual($antes, (int) $srv->valor('SELECT COUNT(*) FROM productos'), 'importar la plantilla no duplica los ejemplos');
        $nav->enviar('caja/acciones.php', ['accion' => 'abrir', 'fondo' => '0']);
        $pos = $nav->get('ventas/index.php');
        Pruebas::contiene('Más vendidos', $pos, 'la venta muestra los más vendidos');
        Pruebas::contiene('ventas/historial.php', $pos, 'Ventas hechas se abre desde Vender');
        Pruebas::verdadero(!preg_match('/sidebar-link[^>]*>\s*<i[^>]*><\/i><span>(Ventas hechas|Inventario)</', $pos), 'el menú ya no trae Ventas hechas ni Inventario');
        Pruebas::contiene('inventario/index.php', $nav->get('productos/index.php'), 'Inventario se abre desde Productos');
        $srv->sql("INSERT INTO ajustes (clave, valor) VALUES ('funciones_avanzadas', '1')
                   ON CONFLICT (clave) DO UPDATE SET valor = '1'");
        Pruebas::igual($clave === 'papeleria', str_contains($nav->get('index.php'), 'listas/index.php'), 'las listas escolares son solo de papelería');
        if ($clave !== 'papeleria') {
            $srv->sql("INSERT INTO listas (nombre) VALUES ('Lista que ya existía')");
            Pruebas::contiene('listas/index.php', $nav->get('index.php'), 'pero no se esconden si ya hay listas');
        }
    } finally {
        $srv->detener();
    }
}

/* ---------------------------------------------------------------------
 *  Venta completa: asistente, caja, IVA, descuentos, pago dividido,
 *  crédito, cancelación, corte y bitácora.
 * --------------------------------------------------------------------- */
$srv = new ServidorPrueba($raiz);
$nav = new Navegador($srv->url);
$venta = function (array $datos) use ($nav): array {
    static $n = 0;
    return $nav->json('ventas/cobrar.php', ['token' => sprintf('%032x', ++$n + 1000)] + $datos);
};

try {
    Pruebas::grupo('Flujo: primer uso');
    $nav->get('index.php');
    Pruebas::contiene('bienvenida.php', (string) $nav->redireccion, 'una base nueva manda al asistente');
    $nav->enviar('bienvenida.php', ['negocio_nombre' => 'Papelería de prueba', 'giro' => 'papeleria', 'productos' => 'ejemplos', 'proteger' => '1',
        'nombre' => 'Dueña', 'pin' => '1234', 'pin2' => '1234'], 'bienvenida.php');
    Pruebas::igual(13, (int) $srv->valor('SELECT COUNT(*) FROM productos'), '13 productos de ejemplo');
    Pruebas::igual(13, (int) $srv->valor("SELECT COUNT(*) FROM productos WHERE tasa_iva = '16'"), 'todos los ejemplos llevan IVA 16%');
    $srv->sql("UPDATE productos SET tasa_iva = '0' WHERE nombre LIKE 'Azúcar%'");   // para probar ventas con dos tasas

    Pruebas::grupo('Flujo: caja y ventas');
    $r = $venta(['metodo_pago' => 'tarjeta', 'items' => [['id' => 1, 'cantidad' => '1']]]);
    Pruebas::verdadero(!empty($r['caja_cerrada']), 'sin caja abierta no se cobra');
    $nav->enviar('caja/acciones.php', ['accion' => 'abrir', 'fondo' => '500']);
    Pruebas::igual(500.0, (float) $srv->valor('SELECT fondo FROM turnos WHERE cerrado_en IS NULL'), 'caja abierta con $500');

    // 2 cuadernos (70) con 10% de descuento = 63; IVA 16% incluido = 8.69. Paga 100 → cambio 37.
    $r = $venta(['metodo_pago' => 'efectivo', 'recibido' => '100', 'descuento' => ['tipo' => 'porcentaje', 'valor' => '10'],
                 'items' => [['id' => 1, 'cantidad' => '2']]]);
    Pruebas::igual([70.0, 7.0, 63.0, 8.69, 37.0], [$r['venta']['subtotal'] ?? null, $r['venta']['descuento'] ?? null,
        $r['venta']['total'] ?? null, $r['venta']['iva'] ?? null, $r['venta']['cambio'] ?? null], 'efectivo con descuento: total, IVA y cambio');
    Pruebas::igual('000001', $r['venta']['folio'] ?? null, 'el primer folio es 000001');
    $ventaEfectivo = (int) ($r['venta']['id'] ?? 0);

    // Cuaderno 35 (16%) + 1 kg de azúcar 32 (0%) = 67. Tarjeta 40 + efectivo 50 → efectivo aplicado 27, cambio 23.
    $r = $venta(['metodo_pago' => 'mixto', 'pagos' => [['metodo' => 'tarjeta', 'monto' => '40'], ['metodo' => 'efectivo', 'monto' => '50']],
                 'items' => [['id' => 1, 'cantidad' => '1'], ['id' => 13, 'cantidad' => '1']]]);
    Pruebas::igual([67.0, 23.0, 4.83], [$r['venta']['total'] ?? null, $r['venta']['cambio'] ?? null, $r['venta']['iva'] ?? null],
        'pago dividido: total, cambio e IVA solo del producto con 16%');
    $ventaDividida = (int) ($r['venta']['id'] ?? 0);
    Pruebas::igual(['tarjeta' => 40.0, 'efectivo' => 27.0], array_map('floatval', array_column(
        $srv->sql('SELECT metodo, monto FROM pagos WHERE venta_id = ?', [$ventaDividida]), 'monto', 'metodo')), 'pagos guardados por método');
    Pruebas::igual('mixto', $srv->valor('SELECT metodo_pago FROM ventas WHERE id = ?', [$ventaDividida]), 'la venta queda como "pago dividido"');

    $r = $venta(['metodo_pago' => 'mixto', 'pagos' => [['metodo' => 'tarjeta', 'monto' => '100'], ['metodo' => 'efectivo', 'monto' => '1']],
                 'items' => [['id' => 2, 'cantidad' => '1']]]);
    Pruebas::contiene('suman más que el total', $r['mensaje'] ?? '', 'rechaza tarjeta mayor que el total');
    $r = $venta(['metodo_pago' => 'mixto', 'pagos' => [['metodo' => 'tarjeta', 'monto' => '10'], ['metodo' => 'efectivo', 'monto' => '10']],
                 'items' => [['id' => 1, 'cantidad' => '1']]]);
    Pruebas::contiene('no alcanza', $r['mensaje'] ?? '', 'rechaza un pago dividido que no cubre el total');
    $r = $venta(['metodo_pago' => 'efectivo', 'recibido' => '10', 'items' => [['id' => 1, 'cantidad' => '1']]]);
    Pruebas::verdadero(($r['ok'] ?? true) === false, 'rechaza efectivo insuficiente');

    Pruebas::grupo('Flujo: crédito');
    $nav->enviar('clientes/editar.php', ['nombre' => 'Doña Mary', 'limite_credito' => '100'], 'clientes/editar.php');
    $cliente = (int) $srv->valor("SELECT id FROM clientes WHERE nombre = 'Doña Mary'");
    // Cuaderno 35: 20 a crédito y efectivo 50 → efectivo aplicado 15, cambio 35. Debe 20.
    $r = $venta(['metodo_pago' => 'mixto', 'cliente_id' => $cliente,
                 'pagos' => [['metodo' => 'credito', 'monto' => '20'], ['metodo' => 'efectivo', 'monto' => '50']],
                 'items' => [['id' => 1, 'cantidad' => '1']]]);
    Pruebas::igual([35.0, 20.0], [$r['venta']['cambio'] ?? null, $r['venta']['saldo'] ?? null], 'parte a crédito: cambio y saldo');
    $r = $venta(['metodo_pago' => 'credito', 'cliente_id' => $cliente, 'items' => [['id' => 1, 'cantidad' => '3']]]);
    Pruebas::contiene('se pasaría', $r['mensaje'] ?? '', 'respeta el límite de crédito');
    $r = $venta(['metodo_pago' => 'credito', 'items' => [['id' => 2, 'cantidad' => '1']]]);
    Pruebas::contiene('elige al cliente', $r['mensaje'] ?? '', 'crédito sin cliente rechazado');

    Pruebas::grupo('Flujo: cancelación y corte');
    $nav->enviar('ventas/cancelar.php', ['id' => $ventaDividida, 'motivo' => 'Prueba de cancelación']);
    Pruebas::igual('cancelada', $srv->valor('SELECT estado FROM ventas WHERE id = ?', [$ventaDividida]), 'venta dividida cancelada');
    Pruebas::igual(0, (int) $srv->valor('SELECT COUNT(*) FROM movimientos_caja'), 'misma caja: no se registra salida (la venta deja de contar)');
    // Esperado: 500 + 63 (efectivo) + 15 (parte en efectivo de la venta a crédito) = 578.
    Pruebas::contiene('$578.00', $nav->get('caja/index.php'), 'la caja espera $578.00');
    $nav->enviar('caja/acciones.php', ['accion' => 'cerrar', 'contado' => '575', 'notas' => 'Faltaron 3']);
    Pruebas::igual(['efectivo_esperado' => 578.0, 'efectivo_contado' => 575.0], array_map('floatval',
        $srv->sql('SELECT efectivo_esperado, efectivo_contado FROM turnos WHERE id = 1')[0] ?? []), 'corte guardado con el faltante');
    $nav->enviar('caja/acciones.php', ['accion' => 'abrir', 'fondo' => '100']);
    $nav->enviar('ventas/cancelar.php', ['id' => $ventaEfectivo, 'motivo' => 'Devolución del cliente']);
    Pruebas::igual(['salida', 63.0], [$srv->valor('SELECT tipo FROM movimientos_caja WHERE turno_id = 2'),
        (float) $srv->valor('SELECT monto FROM movimientos_caja WHERE turno_id = 2')], 'venta de un turno anterior: sale el efectivo de la caja actual');

    Pruebas::grupo('Flujo: bitácora');
    $acciones = array_column($srv->sql('SELECT accion, COUNT(*) AS n FROM auditoria GROUP BY accion'), 'n', 'accion');
    foreach (['venta_descuento' => 1, 'venta_cancelada' => 2, 'caja_abierta' => 2, 'caja_cerrada' => 1, 'cliente_guardado' => 1] as $accion => $n) {
        Pruebas::igual($n, (int) ($acciones[$accion] ?? 0), "bitácora: $accion");
    }
    $nav2 = new Navegador($srv->url);
    $nav2->post('login.php', ['csrf_token' => $nav2->csrf('login.php'), 'usuario' => 1, 'pin' => '0000']);
    Pruebas::igual(1, (int) $srv->valor("SELECT COUNT(*) FROM auditoria WHERE accion = 'sesion_fallida'"), 'bitácora: PIN incorrecto');
    try {
        $srv->sql("UPDATE auditoria SET detalle = 'cambiado'");
        Pruebas::verdadero(false, 'la bitácora no se puede modificar');
    } catch (PDOException $e) {
        Pruebas::verdadero(true, 'la bitácora no se puede modificar');
    }
    Pruebas::contiene('Venta cancelada', $nav->get('auditoria.php'), 'pantalla de bitácora');

    Pruebas::grupo('Flujo: pantallas');
    foreach (['index.php', 'ventas/index.php', 'ventas/historial.php', 'ventas/historial.php?metodo=tarjeta',
              'ventas/detalle.php?id=' . $ventaDividida, 'caja/index.php', 'caja/corte.php?id=1', 'clientes/index.php',
              'clientes/detalle.php?id=' . $cliente, 'productos/index.php', 'productos/editar.php?id=1', 'inventario/index.php',
              'reportes/index.php?periodo=mes', 'ajustes.php', 'usuarios.php', 'respaldos.php', 'auditoria.php'] as $pagina) {
        $nav->get($pagina);
        Pruebas::igual(200, $nav->estado, "carga $pagina");
    }
    Pruebas::contiene('Pago dividido', $nav->get('ventas/detalle.php?id=' . $ventaDividida), 'el ticket muestra el pago dividido');
    $ticket = $nav->get('ventas/detalle.php?id=' . $ventaDividida);
    Pruebas::contiene('Sin IVA (16%):</span><span>$30.17', $ticket, 'el ticket desglosa lo que va sin IVA');
    Pruebas::contiene('IVA 16%:</span><span>$4.83', $ticket, 'y el IVA');
    Pruebas::contiene('Con IVA 0%:</span><span>$32.00', $ticket, 'y lo que va con tasa 0%');
    Pruebas::contiene('SESENTA Y SIETE PESOS 00/100 M.N.', $ticket, 'y el total con letra');
    Pruebas::igual(0, (int) $srv->valor('SELECT COUNT(*) FROM (SELECT 1 FROM pragma_foreign_key_check)'), 'llaves foráneas sanas');
    Pruebas::igual('', $srv->registroErrores(), 'sin errores de PHP');
} finally {
    $srv->detener();
}

/* ---------------------------------------------------------------------
 *  Actualización de una base de la versión 1 (sin pagos, sin IVA).
 * --------------------------------------------------------------------- */
$srv = new ServidorPrueba($raiz, __DIR__ . '/fixtures/base-v1.sqlite');
try {
    Pruebas::grupo('Migración desde la versión 1');
    $nav = new Navegador($srv->url);
    $nav->get('index.php');
    Pruebas::igual((int) DB_ESQUEMA_VERSION, (int) $srv->valor('PRAGMA user_version'), 'la base se actualiza a la versión actual');
    Pruebas::igual((int) $srv->valor('SELECT COUNT(*) FROM ventas'), (int) $srv->valor('SELECT COUNT(*) FROM pagos'), 'cada venta antigua tiene su pago');
    Pruebas::igual(0, (int) $srv->valor('SELECT COUNT(*) FROM ventas WHERE subtotal <> total'), 'subtotal = total en ventas antiguas');
    Pruebas::igual('16', $srv->valor('SELECT tasa_iva FROM productos LIMIT 1'), 'productos antiguos quedan con IVA 16%');
    Pruebas::igual('ok', $srv->valor('PRAGMA integrity_check'), 'integridad de la base');
    Pruebas::igual('', $srv->registroErrores(), 'sin errores de PHP');
} finally {
    $srv->detener();
}

/* ---------------------------------------------------------------------
 *  Catálogo: categorías, precios por cantidad, exportar e importar.
 * --------------------------------------------------------------------- */
$srv = new ServidorPrueba($raiz);
$nav = new Navegador($srv->url);
try {
    Pruebas::grupo('Catálogo y mayoreo');
    $nav->get('index.php');
    $nav->enviar('bienvenida.php', ['negocio_nombre' => 'Catálogo', 'giro' => 'papeleria', 'productos' => 'ejemplos'], 'bienvenida.php');
    $nav->enviar('caja/acciones.php', ['accion' => 'abrir', 'fondo' => '0']);
    $nav->enviar('productos/categorias.php', ['accion' => 'guardar', 'nombre' => 'Escritura'], 'productos/categorias.php');
    $categoria = (int) $srv->valor("SELECT id FROM categorias WHERE nombre = 'Escritura'");
    Pruebas::verdadero($categoria > 0, 'categoría creada');

    // Lápiz $5; desde 12 a $4.50; desde 100 a $4.00.
    $nav->enviar('productos/editar.php', ['id' => 2, 'nombre' => 'Lápiz grafito No. 2', 'codigo_barras' => '7501000000024', 'precio' => '5',
        'costo' => '2.40', 'existencia' => '500', 'stock_minimo' => '20', 'controlar_stock' => '1', 'activo' => '1', 'tasa_iva' => '16',
        'codigo_interno' => 'LAP-2', 'categoria_id' => $categoria, 'marca' => 'Mirado', 'unidad' => 'pieza',
        'volumen_desde' => ['12', '100', ''], 'volumen_precio' => ['4.50', '4', '']], 'productos/editar.php?id=2');
    Pruebas::igual(['LAP-2', $categoria, 'Mirado'], array_values($srv->sql('SELECT codigo_interno, categoria_id, marca FROM productos WHERE id = 2')[0] ?? []),
        'ficha con código interno, categoría y marca');
    Pruebas::igual(2, (int) $srv->valor('SELECT COUNT(*) FROM precios_volumen WHERE producto_id = 2'), 'dos precios por cantidad guardados');

    $busca = json_decode($nav->get('ventas/buscar.php?codigo=LAP-2'), true);
    Pruebas::igual([[12, 4.5], [100, 4]], $busca['producto']['precios'] ?? null, 'se encuentra por código interno y trae sus precios por cantidad');

    $n = 0;
    $venta = function (string $cant) use ($nav, &$n) { return $nav->json('ventas/cobrar.php', ['token' => sprintf('%032x', 5000 + ++$n), 'metodo_pago' => 'tarjeta',
        'items' => [['id' => 2, 'cantidad' => $cant]]]); };
    Pruebas::igual(55.0, $venta('11')['venta']['total'] ?? null, '11 lápices a precio normal = $55');
    Pruebas::igual(54.0, $venta('12')['venta']['total'] ?? null, '12 lápices a precio de mayoreo = $54');
    Pruebas::igual(400.0, $venta('100')['venta']['total'] ?? null, '100 lápices al segundo escalón = $400');
    Pruebas::igual(4.0, (float) $srv->valor('SELECT precio_unitario FROM detalle_ventas ORDER BY id DESC LIMIT 1'), 'el ticket guarda el precio aplicado');

    Pruebas::grupo('Exportar e importar');
    $csv = $nav->get('productos/index.php?exportar=1');
    Pruebas::contiene('nombre,codigo_barras,codigo_interno,categoria', $csv, 'exporta productos con columnas de catálogo');
    Pruebas::contiene('LAP-2,Escritura,Mirado', $csv, 'el lápiz sale con su código interno, categoría y marca');
    // Ida y vuelta: importar lo exportado no cambia nada ni duplica.
    $antes = (int) $srv->valor('SELECT COUNT(*) FROM productos');
    $nav->subir('productos/importar.php', [], 'archivo', 'productos.csv', $csv);
    Pruebas::igual($antes, (int) $srv->valor('SELECT COUNT(*) FROM productos'), 'importar lo exportado no duplica productos');
    Pruebas::igual(['LAP-2', $categoria, 12.0], [$srv->valor('SELECT codigo_interno FROM productos WHERE id = 2'),
        (int) $srv->valor('SELECT categoria_id FROM productos WHERE id = 2'), (float) $srv->valor('SELECT MIN(desde) FROM precios_volumen WHERE producto_id = 2')],
        'importar lo exportado conserva catálogo y mayoreo');

    $nuevo = "nombre,precio,categoria,proveedor,unidad,iva,mayoreo_desde,precio_mayoreo,codigo_interno\r\n"
           . "Papel china (pliego),3,Papel,Papelera del Norte,pliego,16,10,2.50,PCH-1\r\n";
    $nav->subir('productos/importar.php', [], 'archivo', 'nuevos.csv', $nuevo);
    $p = $srv->sql("SELECT p.unidad, c.nombre AS categoria, pr.nombre AS proveedor, (SELECT precio FROM precios_volumen v WHERE v.producto_id = p.id) AS mayoreo
                    FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id LEFT JOIN proveedores pr ON pr.id = p.proveedor_id
                    WHERE p.codigo_interno = 'PCH-1'")[0] ?? [];
    Pruebas::igual(['pliego', 'Papel', 'Papelera del Norte', 2.5], [$p['unidad'] ?? null, $p['categoria'] ?? null, $p['proveedor'] ?? null,
        isset($p['mayoreo']) ? (float) $p['mayoreo'] : null], 'importar crea categoría, proveedor y precio de mayoreo');
    $r = $nav->subir('productos/importar.php', [], 'archivo', 'dup.csv', "nombre,precio,codigo_interno\r\nOtro,1,PCH-1\r\n");
    Pruebas::contiene('ya lo tiene otro producto', $r, 'rechaza un código interno repetido');

    foreach (['ventas/historial.php?exportar=1' => 'folio,fecha', 'reportes/index.php?periodo=mes&exportar=resumen' => 'concepto,importe',
              'reportes/index.php?periodo=mes&exportar=inventario' => 'producto,codigo_barras'] as $pagina => $encabezado) {
        Pruebas::contiene($encabezado, $nav->get($pagina), "exporta $pagina");
    }
    foreach (['productos/categorias.php', 'proveedores/index.php', 'productos/index.php?categoria=' . $categoria, 'productos/crear.php'] as $pagina) {
        $nav->get($pagina);
        Pruebas::igual(200, $nav->estado, "carga $pagina");
    }
    Pruebas::igual('', $srv->registroErrores(), 'sin errores de PHP');
} finally {
    $srv->detener();
}

/* ---------------------------------------------------------------------
 *  Papelería: paquetes, kits, listas escolares y etiquetas.
 * --------------------------------------------------------------------- */
$srv = new ServidorPrueba($raiz);
$nav = new Navegador($srv->url);
try {
    Pruebas::grupo('Paquetes y kits');
    $nav->get('index.php');
    $nav->enviar('bienvenida.php', ['negocio_nombre' => 'Papelería', 'giro' => 'papeleria', 'productos' => 'ejemplos'], 'bienvenida.php');
    $nav->enviar('caja/acciones.php', ['accion' => 'abrir', 'fondo' => '0']);
    // Caja de 12 lápices (lápiz = producto 2, hay 120).
    $nav->enviar('productos/crear.php', ['nombre' => 'Caja de 12 lápices', 'precio' => '50', 'tasa_iva' => '16', 'unidad' => 'caja',
        'tipo' => 'paquete', 'producto_base_id' => 2, 'factor_base' => '12'], 'productos/crear.php');
    $caja = (int) $srv->valor("SELECT id FROM productos WHERE nombre = 'Caja de 12 lápices'");
    Pruebas::igual(['paquete', 2, 12.0], [$srv->valor('SELECT tipo FROM productos WHERE id = ?', [$caja]),
        (int) $srv->valor('SELECT producto_base_id FROM productos WHERE id = ?', [$caja]), (float) $srv->valor('SELECT factor_base FROM productos WHERE id = ?', [$caja])],
        'paquete creado con su producto base');
    // Kit escolar: 2 cuadernos (1) + 3 lápices (2) + 1 borrador (4).
    $nav->enviar('productos/crear.php', ['nombre' => 'Kit escolar básico', 'precio' => '90', 'tasa_iva' => '16', 'tipo' => 'kit',
        'kit_producto' => [1, 2, 4, ''], 'kit_cantidad' => ['2', '3', '1', '']], 'productos/crear.php');
    $kit = (int) $srv->valor("SELECT id FROM productos WHERE nombre = 'Kit escolar básico'");
    Pruebas::igual(3, (int) $srv->valor('SELECT COUNT(*) FROM kit_componentes WHERE kit_id = ?', [$kit]), 'kit con 3 componentes');
    $nav->enviar('productos/crear.php', ['nombre' => 'Kit malo', 'precio' => '1', 'tipo' => 'kit', 'kit_producto' => [$kit], 'kit_cantidad' => ['1']],
        'productos/crear.php');
    Pruebas::igual(0, (int) $srv->valor("SELECT COUNT(*) FROM productos WHERE nombre = 'Kit malo'"), 'un kit no puede contener otro kit');

    $disp = json_decode($nav->get('ventas/buscar.php?ids=' . $caja . ',' . $kit), true)['productos'] ?? [];
    $disp = array_column($disp, 'existencia', 'id');
    // Lápiz 120 → 10 cajas. Kit: cuaderno 40/2 = 20, lápiz 120/3 = 40, borrador 50 → 20.
    Pruebas::igual([10.0, 20.0], [$disp[$caja] ?? null, $disp[$kit] ?? null], 'disponibles: 10 cajas y 20 kits');

    $n = 0;
    $vender = function (array $items) use ($nav, &$n) {
        return $nav->json('ventas/cobrar.php', ['token' => sprintf('%032x', 9000 + ++$n), 'metodo_pago' => 'tarjeta', 'items' => $items]);
    };
    $r = $vender([['id' => $caja, 'cantidad' => '2'], ['id' => 2, 'cantidad' => '5'], ['id' => $kit, 'cantidad' => '1']]);
    Pruebas::verdadero(($r['ok'] ?? false) === true, 'venta con caja, piezas sueltas y kit');
    $venta = (int) ($r['venta']['id'] ?? 0);
    // Lápiz: 120 - 24 (2 cajas) - 5 sueltos - 3 (kit) = 88. Cuaderno: 40 - 2 = 38. Borrador: 50 - 1 = 49.
    Pruebas::igual([38.0, 88.0, 49.0], array_map('floatval', array_column($srv->sql('SELECT id, existencia FROM productos WHERE id IN (1, 2, 4) ORDER BY id'), 'existencia')),
        'existencias descontadas de los productos reales');
    Pruebas::igual(0.0, (float) $srv->valor('SELECT existencia FROM productos WHERE id = ?', [$caja]), 'la caja no tiene existencia propia');
    $r = $vender([['id' => $caja, 'cantidad' => '8']]);
    Pruebas::contiene('No hay suficiente «Lápiz', $r['mensaje'] ?? '', 'no vende más cajas de las que alcanzan los lápices');

    $nav->enviar('ventas/cancelar.php', ['id' => $venta, 'motivo' => 'Prueba de devolución']);
    Pruebas::igual([40.0, 120.0, 50.0], array_map('floatval', array_column($srv->sql('SELECT id, existencia FROM productos WHERE id IN (1, 2, 4) ORDER BY id'), 'existencia')),
        'cancelar devuelve exactamente lo que se descontó');

    Pruebas::grupo('Listas escolares');
    $nav->enviar('listas/editar.php', ['nombre' => '3° primaria', 'escuela' => 'Esc. Juárez', 'producto' => [1, 2, 4], 'cantidad' => ['3', '2', '']],
        'listas/editar.php');
    $lista = (int) $srv->valor("SELECT id FROM listas WHERE nombre = '3° primaria'");
    Pruebas::igual(3, (int) $srv->valor('SELECT COUNT(*) FROM lista_productos WHERE lista_id = ?', [$lista]), 'lista guardada con 3 artículos');
    Pruebas::contiene('$121.00', $nav->get('listas/imprimir.php?id=' . $lista), 'la lista impresa suma $121.00 (3×35 + 2×5 + 6)');
    Pruebas::contiene('3° primaria', $nav->get('ventas/index.php'), 'el punto de venta ofrece la lista');
    $nav->enviar('listas/editar.php', ['id' => $lista, 'accion' => 'copiar'], 'listas/editar.php?id=' . $lista);
    Pruebas::igual(2, (int) $srv->valor('SELECT COUNT(*) FROM listas'), 'copiar una lista');

    Pruebas::grupo('Etiquetas');
    $html = $nav->get('productos/etiquetas.php?imprimir=1&formato=carta30&precio=1&cant[1]=2&cant[2]=1');
    Pruebas::igual(3, substr_count($html, 'class="etiqueta"'), 'imprime la cantidad de etiquetas pedida');
    Pruebas::contiene('7501000000017', $html, 'la etiqueta lleva el código del producto');
    $sinCodigo = (int) $srv->valor('SELECT id FROM productos WHERE codigo_barras IS NULL AND controlar_stock = 1 LIMIT 1');
    $nav->enviar('productos/etiquetas.php', ['accion' => 'asignar', 'ids' => [$sinCodigo]], 'productos/etiquetas.php');
    Pruebas::igual(ean13_interno($sinCodigo), $srv->valor('SELECT codigo_barras FROM productos WHERE id = ?', [$sinCodigo]), 'asigna código interno a quien no tiene');
    $r = json_decode($nav->get('ventas/buscar.php?codigo=' . ean13_interno($sinCodigo)), true);
    Pruebas::igual($sinCodigo, (int) ($r['producto']['id'] ?? 0), 'el código asignado se puede escanear');

    foreach (['listas/index.php', 'listas/editar.php?id=' . $lista, 'productos/etiquetas.php', 'productos/editar.php?id=' . $caja,
              'productos/editar.php?id=' . $kit, 'productos/index.php'] as $pagina) {
        $nav->get($pagina);
        Pruebas::igual(200, $nav->estado, "carga $pagina");
    }
    Pruebas::igual(0, (int) $srv->valor('SELECT COUNT(*) FROM (SELECT 1 FROM pragma_foreign_key_check)'), 'llaves foráneas sanas');
    Pruebas::igual('', $srv->registroErrores(), 'sin errores de PHP');
} finally {
    $srv->detener();
}

/* ---------------------------------------------------------------------
 *  Compras a proveedores y devoluciones parciales.
 * --------------------------------------------------------------------- */
$srv = new ServidorPrueba($raiz);
$nav = new Navegador($srv->url);
try {
    Pruebas::grupo('Compras');
    $nav->get('index.php');
    $nav->enviar('bienvenida.php', ['negocio_nombre' => 'Papelería', 'giro' => 'papeleria', 'productos' => 'ejemplos'], 'bienvenida.php');
    $srv->sql("UPDATE productos SET tasa_iva = '0' WHERE nombre LIKE 'Azúcar%'");   // para probar facturas con dos tasas
    Pruebas::grupo('Modo sencillo');
    $menuHtml = $nav->get('index.php');
    Pruebas::verdadero(!str_contains($menuHtml, 'compras/index.php') && !str_contains($menuHtml, 'listas/index.php'), 'sin funciones avanzadas el menú no muestra compras ni listas');
    Pruebas::verdadero(!str_contains($menuHtml, '>Usuarios<'), 'usuarios, respaldos y bitácora van dentro de Configuración');
    Pruebas::contiene('auditoria.php', $nav->get('ajustes.php'), 'Configuración tiene sus pestañas');
    $form = $nav->get('productos/crear.php');
    Pruebas::verdadero(!str_contains($form, 'volumen_desde') && !str_contains($form, 'id="tipo_kit"'), 'el formulario sencillo no trae mayoreo ni kits');
    // Un producto que ya tiene mayoreo lo conserva aunque se edite en modo sencillo.
    $srv->sql('INSERT INTO precios_volumen (producto_id, desde, precio) VALUES (2, 12, 4.5)');
    Pruebas::contiene('volumen_desde', $nav->get('productos/editar.php?id=2'), 'si el producto ya tiene mayoreo, se muestra');
    $nav->enviar('ajustes.php', ['negocio_nombre' => 'Papelería', 'impresora_tipo' => 'ninguna', 'funciones_avanzadas' => '1',
        'ticket_factura' => '¿Necesitas factura? Pídela con este ticket.'], 'ajustes.php');
    Pruebas::contiene('compras/index.php', $nav->get('index.php'), 'al activar las funciones avanzadas aparecen en el menú');
    Pruebas::contiene('id="tipo_kit"', $nav->get('productos/crear.php'), 'y el formulario trae paquetes y kits');

    $nav->enviar('caja/acciones.php', ['accion' => 'abrir', 'fondo' => '500']);
    $srv->sql("INSERT INTO proveedores (nombre) VALUES ('Papelera del Centro')");
    $prov = (int) $srv->valor("SELECT id FROM proveedores WHERE nombre = 'Papelera del Centro'");
    $srv->sql('UPDATE productos SET proveedor_id = ?, existencia = 10 WHERE id = 3', [$prov]);   // pluma: 10, mínimo 15
    Pruebas::contiene('Pluma tinta azul', $nav->get('compras/nueva.php?sugerir=' . $prov), 'el sugerido trae lo que está por agotarse del proveedor');

    $nav->enviar('compras/nueva.php', ['proveedor_id' => $prov, 'folio_proveedor' => 'A-77', 'pagada_de_caja' => '1', 'actualizar_costo' => '1',
        'items' => [['id' => 3, 'cantidad' => '20', 'costo' => '4.50']]], 'compras/nueva.php');
    $compra = (int) $srv->valor('SELECT MAX(id) FROM compras');
    Pruebas::igual([90.0, 30.0, 4.5], [(float) $srv->valor('SELECT total FROM compras WHERE id = ?', [$compra]),
        (float) $srv->valor('SELECT existencia FROM productos WHERE id = 3'), (float) $srv->valor('SELECT costo FROM productos WHERE id = 3')],
        'compra de 20 plumas a $4.50: total, existencia y costo nuevo');
    Pruebas::igual(90.0, (float) $srv->valor("SELECT monto FROM movimientos_caja WHERE tipo = 'salida' AND concepto LIKE 'Compra #%'"), 'pagada de la caja: sale $90');
    Pruebas::igual('entrada', $srv->valor('SELECT tipo FROM movimientos_inventario WHERE producto_id = 3 ORDER BY id DESC LIMIT 1'), 'queda en el historial de inventario');
    $nav->get('compras/detalle.php?id=' . $compra);
    Pruebas::igual(200, $nav->estado, 'carga el detalle de la compra');

    $nav->enviar('compras/detalle.php', ['id' => $compra, 'motivo' => 'Llegó equivocado'], 'compras/detalle.php?id=' . $compra);
    Pruebas::igual(['cancelada', 10.0], [$srv->valor('SELECT estado FROM compras WHERE id = ?', [$compra]),
        (float) $srv->valor('SELECT existencia FROM productos WHERE id = 3')], 'cancelar la compra regresa la existencia');
    Pruebas::igual(90.0, (float) $srv->valor("SELECT monto FROM movimientos_caja WHERE tipo = 'entrada'"), 'y el dinero vuelve a la caja');

    Pruebas::grupo('Devoluciones parciales');
    $n = 0;
    $vender = function (array $datos) use ($nav, &$n): array {
        return $nav->json('ventas/cobrar.php', ['token' => sprintf('%032x', 7000 + ++$n)] + $datos);
    };
    // 3 cuadernos (105) con 10% = 94.50: cada uno le costó 31.50 al cliente.
    $r = $vender(['metodo_pago' => 'efectivo', 'recibido' => '100', 'descuento' => ['tipo' => 'porcentaje', 'valor' => '10'],
                  'items' => [['id' => 1, 'cantidad' => '3']]]);
    $v = (int) ($r['venta']['id'] ?? 0);
    $det = (int) $srv->valor('SELECT id FROM detalle_ventas WHERE venta_id = ?', [$v]);
    $devolver = function (array $campos) use ($nav, &$v) {
        return $nav->enviar('ventas/devolver.php', ['id' => $v] + $campos, 'ventas/devolver.php?id=' . $v);
    };
    $nav->get('ventas/devolver.php?id=' . $v);
    Pruebas::igual(200, $nav->estado, 'carga la pantalla de devolución');

    $devolver(['cantidad' => [$det => '1'], 'regresa' => [$det => '1'], 'reembolso' => 'efectivo', 'motivo' => 'Hojas rotas']);
    Pruebas::igual([31.5, 38.0], [(float) $srv->valor('SELECT total FROM devoluciones WHERE venta_id = ?', [$v]),
        (float) $srv->valor('SELECT existencia FROM productos WHERE id = 1')], 'devuelve 1 de 3: reembolso con su descuento y regresa al anaquel');
    Pruebas::igual(31.5, (float) $srv->valor("SELECT monto FROM movimientos_caja WHERE concepto LIKE 'Devolución #%'"), 'el efectivo sale de la caja');

    $devolver(['cantidad' => [$det => '1'], 'reembolso' => 'tarjeta', 'motivo' => 'Venía mojado']);
    Pruebas::igual(38.0, (float) $srv->valor('SELECT existencia FROM productos WHERE id = 1'), 'dañado: no regresa a la existencia');
    $devolver(['cantidad' => [$det => '2'], 'regresa' => [$det => '1'], 'reembolso' => 'tarjeta', 'motivo' => 'Ya no los quiere']);
    Pruebas::igual(2, (int) $srv->valor('SELECT COUNT(*) FROM devoluciones WHERE venta_id = ?', [$v]), 'no deja devolver más de lo que queda');
    $devolver(['cantidad' => [$det => '1'], 'regresa' => [$det => '1'], 'reembolso' => 'transferencia', 'motivo' => 'Ya no lo quiere']);
    Pruebas::igual(94.5, (float) $srv->valor('SELECT SUM(total) FROM devoluciones WHERE venta_id = ?', [$v]), 'lo devuelto suma exactamente lo cobrado');
    Pruebas::contiene('Todo devuelto', $nav->get('ventas/devolver.php?id=' . $v), 'el renglón queda como todo devuelto');

    $nav->enviar('ventas/cancelar.php', ['id' => $v, 'motivo' => 'Intento de cancelar']);
    Pruebas::igual('completada', $srv->valor('SELECT estado FROM ventas WHERE id = ?', [$v]), 'una venta con devoluciones no se cancela completa');
    Pruebas::igual(39.0, (float) $srv->valor('SELECT existencia FROM productos WHERE id = 1'), 'y la existencia no se repone dos veces');
    $html = $nav->get('ventas/detalle.php?id=' . $v);
    Pruebas::contiene('Devoluciones', $html, 'el detalle lista las devoluciones');
    Pruebas::contiene('Venía mojado', $html, 'con su motivo');

    // Kit: 2 cuadernos + 3 lápices + 1 borrador.
    $nav->enviar('productos/crear.php', ['nombre' => 'Kit básico', 'precio' => '90', 'tasa_iva' => '16', 'tipo' => 'kit',
        'kit_producto' => [1, 2, 4], 'kit_cantidad' => ['2', '3', '1']], 'productos/crear.php');
    $kit = (int) $srv->valor("SELECT id FROM productos WHERE nombre = 'Kit básico'");
    $r = $vender(['metodo_pago' => 'tarjeta', 'items' => [['id' => $kit, 'cantidad' => '2']]]);
    $v = (int) ($r['venta']['id'] ?? 0);
    $det = (int) $srv->valor('SELECT id FROM detalle_ventas WHERE venta_id = ?', [$v]);
    $existencias = fn() => array_map('floatval', array_column($srv->sql('SELECT existencia FROM productos WHERE id IN (1, 2, 4) ORDER BY id'), 'existencia'));
    $antes = $existencias();
    $devolver(['cantidad' => [$det => '1'], 'regresa' => [$det => '1'], 'reembolso' => 'tarjeta', 'motivo' => 'Le sobró uno']);
    Pruebas::igual([$antes[0] + 2, $antes[1] + 3, $antes[2] + 1], $existencias(), 'devolver un kit regresa sus componentes');

    // Crédito: 1 cuaderno + 2 lápices = 45 fiado; devuelve el cuaderno → debe 10.
    $nav->enviar('clientes/editar.php', ['nombre' => 'Don Pepe', 'limite_credito' => '500'], 'clientes/editar.php');
    $cliente = (int) $srv->valor("SELECT id FROM clientes WHERE nombre = 'Don Pepe'");
    $r = $vender(['metodo_pago' => 'credito', 'cliente_id' => $cliente, 'items' => [['id' => 1, 'cantidad' => '1'], ['id' => 2, 'cantidad' => '2']]]);
    $v = (int) ($r['venta']['id'] ?? 0);
    $det = (int) $srv->valor('SELECT id FROM detalle_ventas WHERE venta_id = ? AND producto_id = 1', [$v]);
    $devolver(['cantidad' => [$det => '1'], 'regresa' => [$det => '1'], 'reembolso' => 'credito', 'motivo' => 'Se equivocó de raya']);
    $html = $nav->get('clientes/detalle.php?id=' . $cliente);
    Pruebas::contiene('$10.00', $html, 'el crédito baja a $10');
    Pruebas::contiene('Devolución #', $html, 'la devolución aparece en su estado de cuenta');
    Pruebas::contiene('$10.00', $nav->get('clientes/index.php'), 'y en la lista de clientes');

    Pruebas::grupo('Facturas');
    $r = $vender(['metodo_pago' => 'tarjeta', 'items' => [['id' => 1, 'cantidad' => '2'], ['id' => 13, 'cantidad' => '1']]]);   // 70 (16%) + 32 (0%)
    $vf = (int) ($r['venta']['id'] ?? 0);
    $pedir = fn(array $c) => $nav->enviar('ventas/factura.php', ['id' => $vf, 'accion' => 'pedir'] + $c, 'ventas/factura.php?id=' . $vf);
    $html = $nav->get('ventas/detalle.php?id=' . $vf);
    Pruebas::contiene('Pedir factura', $html, 'la venta ofrece pedir factura');
    Pruebas::contiene('Pídela con este ticket', $html, 'y el ticket trae el aviso para pedir factura');
    $html = $pedir(['rfc' => 'ABC', 'razon_social' => 'Prueba', 'cp_fiscal' => '64000', 'regimen_fiscal' => '612', 'uso_cfdi' => 'G03']);
    Pruebas::igual(0, (int) $srv->valor('SELECT COUNT(*) FROM facturas'), 'un RFC mal escrito no se guarda');
    $pedir(['rfc' => 'pepe800101ab1', 'razon_social' => 'José Pérez', 'cp_fiscal' => '64000', 'regimen_fiscal' => '626', 'uso_cfdi' => 'G03',
            'correo' => 'pepe@ejemplo.com', 'cliente_id' => $cliente, 'guardar_en_cliente' => '1']);
    Pruebas::igual(['pendiente', 'PEPE800101AB1'], array_values($srv->sql('SELECT estado, rfc FROM facturas')[0] ?? []), 'factura pedida y pendiente');
    Pruebas::igual('PEPE800101AB1', $srv->valor('SELECT rfc FROM clientes WHERE id = ?', [$cliente]), 'los datos se guardan en el cliente');
    Pruebas::contiene('RFC: PEPE800101AB1', $nav->get('ventas/detalle.php?id=' . $vf), 'el ticket dice a quién se factura');
    $lista = $nav->get('facturas/index.php');
    Pruebas::contiene('JOSÉ PÉREZ', $lista, 'aparece en Facturas por hacer');
    Pruebas::contiene('1 por hacer', $nav->get('ventas/historial.php'), 'Ventas hechas avisa cuántas hay por hacer');
    $filas = renglones_excel($nav->get('facturas/index.php?estado=pendiente&descargar=facturas'));
    $cuaderno = array_values(array_filter($filas, fn($r) => ($r[10] ?? '') === 'Cuaderno profesional raya'))[0] ?? [];
    Pruebas::igual([folio($vf), 'PEPE800101AB1', 'JOSÉ PÉREZ', '64000', '626', 'G03', '04', 'PUE', '2', 'H87', '30.17', '60.34', '16%', '9.66', '70'],
        [$cuaderno[0] ?? null, $cuaderno[2] ?? null, $cuaderno[3] ?? null, $cuaderno[4] ?? null, $cuaderno[5] ?? null, $cuaderno[6] ?? null, $cuaderno[8] ?? null,
         $cuaderno[9] ?? null, $cuaderno[11] ?? null, $cuaderno[12] ?? null, $cuaderno[13] ?? null, $cuaderno[14] ?? null, $cuaderno[15] ?? null, $cuaderno[16] ?? null, $cuaderno[17] ?? null],
        'el Excel trae cada producto con folio y forma de pago como texto, precio sin IVA e IVA');
    $azucar = array_values(array_filter($filas, fn($r) => str_starts_with($r[10] ?? '', 'Azúcar')))[0] ?? [];
    Pruebas::igual(['0%', '0', '32'], [$azucar[15] ?? null, $azucar[16] ?? null, $azucar[17] ?? null], 'y el producto con tasa 0%');
    $global = renglones_excel($nav->get('facturas/index.php?descargar=global'));
    Pruebas::verdadero($global !== [] && !in_array(folio($vf), array_column($global, 0), true), 'la factura global no incluye la venta que ya tiene factura');
    $ticketGlobal = array_values(array_filter($global, fn($r) => preg_match('/^\d{6}$/', $r[0] ?? '')))[0] ?? [];
    Pruebas::igual(['01010101', 'ACT', '1', 'Venta'], array_slice($ticketGlobal, 2, 4), 'la global trae cada ticket como concepto con sus claves del SAT');
    Pruebas::verdadero(in_array('Base IVA 0%', $global[5] ?? [], true) && in_array('Exento', $global[5] ?? [], true), 'y separa la base de cada tasa');
    $nav->enviar('ventas/factura.php', ['id' => $vf, 'accion' => 'hecha', 'uuid' => 'abc'], 'ventas/factura.php?id=' . $vf);
    Pruebas::igual('pendiente', $srv->valor('SELECT estado FROM facturas'), 'un folio fiscal mal escrito no se acepta');
    $nav->enviar('ventas/factura.php', ['id' => $vf, 'accion' => 'hecha', 'uuid' => '6f1a2b3c-1234-4abc-9def-0123456789ab'], 'ventas/factura.php?id=' . $vf);
    Pruebas::igual(['hecha', '6F1A2B3C-1234-4ABC-9DEF-0123456789AB'], array_values($srv->sql('SELECT estado, uuid FROM facturas')[0] ?? []), 'marcada como hecha con su folio fiscal');
    $pedir(['rfc' => 'PEPE800101AB1', 'razon_social' => 'Otro', 'cp_fiscal' => '64000', 'regimen_fiscal' => '626', 'uso_cfdi' => 'G03']);
    Pruebas::igual(1, (int) $srv->valor('SELECT COUNT(*) FROM facturas'), 'no se pide dos veces la factura de una venta');
    $nav->enviar('clientes/editar.php', ['id' => $cliente, 'nombre' => 'Don Pepe', 'activo' => '1', 'rfc' => 'MAL'], 'clientes/editar.php?id=' . $cliente);
    Pruebas::igual('PEPE800101AB1', $srv->valor('SELECT rfc FROM clientes WHERE id = ?', [$cliente]), 'el cliente no guarda datos fiscales incompletos');

    Pruebas::grupo('Facturas: correo, XML y cancelación');
    $smtp = new SmtpFalso();
    try {
        $ajustesCorreo = fn(array $c) => $nav->enviar('ajustes.php', ['negocio_nombre' => 'Papelería', 'impresora_tipo' => 'ninguna', 'funciones_avanzadas' => '1',
            'ticket_factura' => '¿Necesitas factura? Pídela con este ticket.', 'correo_servidor' => '127.0.0.1', 'correo_puerto' => (string) $smtp->puerto,
            'correo_seguridad' => 'ninguna', 'correo_usuario' => 'tienda@prueba.mx'] + $c, 'ajustes.php');
        $ajustesCorreo(['correo_contrasena' => 'mala', 'accion' => 'correo_prueba']);
        Pruebas::contiene('rechazó el usuario o la contraseña', $nav->get('ajustes.php'), 'con contraseña mala avisa claro');
        $ajustesCorreo(['correo_contrasena' => 'clave-buena', 'accion' => 'correo_prueba']);
        Pruebas::igual(1, count($smtp->correos()), 'el correo de prueba sale');
        $ajustesCorreo(['correo_contrasena' => '', 'accion' => 'guardar']);
        Pruebas::igual('clave-buena', $srv->valor("SELECT valor FROM ajustes WHERE clave = 'correo_contrasena'"), 'guardar sin escribir la contraseña la conserva');
        Pruebas::verdadero(!str_contains($nav->get('ajustes.php'), 'clave-buena'), 'la contraseña nunca se muestra en pantalla');

        $uuid = '6F1A2B3C-1234-4ABC-9DEF-0123456789AB';
        $cfdi = fn(string $rfc, string $u) => '<?xml version="1.0" encoding="UTF-8"?><cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" '
            . 'xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="4.0" Total="102.00" Fecha="2026-10-28T10:00:00">'
            . '<cfdi:Emisor Rfc="EKU9003173C9"/><cfdi:Receptor Rfc="' . $rfc . '"/><cfdi:Complemento><tfd:TimbreFiscalDigital UUID="' . $u . '"/></cfdi:Complemento></cfdi:Comprobante>';
        $urlFactura = 'ventas/factura.php?id=' . $vf;
        $nav->subirArchivos($urlFactura, ['id' => $vf, 'accion' => 'archivos'], ['xml' => ['otra.xml', $cfdi('AAA010101AAA', $uuid)]]);
        Pruebas::igual(0, (int) $srv->valor('SELECT COUNT(*) FROM factura_archivos'), 'no acepta el XML de otro RFC');
        $nav->subirArchivos($urlFactura, ['id' => $vf, 'accion' => 'archivos'], ['xml' => ['factura.xml', $cfdi('PEPE800101AB1', $uuid)], 'pdf' => ['factura.pdf', "%PDF-1.4\nprueba"]]);
        Pruebas::igual(2, (int) $srv->valor('SELECT COUNT(*) FROM factura_archivos'), 'guarda el XML y el PDF');
        $fid = (int) $srv->valor("SELECT id FROM facturas WHERE estado = 'hecha'");
        Pruebas::contiene('TimbreFiscalDigital', $nav->get('facturas/archivo.php?id=' . $fid . '&tipo=xml'), 'el XML se puede descargar');

        $nav->enviar($urlFactura, ['id' => $vf, 'accion' => 'enviar', 'correo_envio' => 'pepe@ejemplo.com'], $urlFactura);
        $correo = $smtp->correos()[1] ?? '';
        Pruebas::contiene('RCPT TO:<pepe@ejemplo.com>', $correo, 'la factura se manda al correo del cliente');
        Pruebas::contiene('filename="factura.xml"', $correo, 'con el XML adjunto');
        Pruebas::contiene('filename="factura.pdf"', $correo, 'y el PDF');
        Pruebas::igual('pepe@ejemplo.com', $srv->valor('SELECT enviada_a FROM facturas WHERE id = ?', [$fid]), 'queda anotado a quién se mandó');

        $nav->enviar($urlFactura, ['id' => $vf, 'accion' => 'cancelar', 'motivo' => '07'], $urlFactura);
        Pruebas::igual('hecha', $srv->valor('SELECT estado FROM facturas WHERE id = ?', [$fid]), 'sin motivo del SAT no se cancela');
        $sustituta = '11111111-2222-4333-8444-555555555555';
        $corregidos = ['rfc' => 'PEPE800101AB1', 'razon_social' => 'José Pérez López', 'cp_fiscal' => '64000', 'regimen_fiscal' => '626', 'uso_cfdi' => 'G03'];
        $nav->enviar($urlFactura, ['id' => $vf, 'accion' => 'cancelar', 'motivo' => '01'] + $corregidos, $urlFactura);
        Pruebas::igual('hecha', $srv->valor('SELECT estado FROM facturas WHERE id = ?', [$fid]), 'motivo 01 pide el folio de la factura nueva');
        $nav->enviar($urlFactura, ['id' => $vf, 'accion' => 'cancelar', 'motivo' => '01', 'uuid_sustituto' => strtolower($sustituta)] + $corregidos, $urlFactura);
        Pruebas::igual(['cancelada', '01', $sustituta], array_values($srv->sql('SELECT estado, motivo_cancelacion, uuid_sustituto FROM facturas WHERE id = ?', [$fid])[0] ?? []),
            'la factura con errores queda cancelada con motivo 01');
        Pruebas::igual(['hecha', $sustituta, 'JOSÉ PÉREZ LÓPEZ', $fid], array_values($srv->sql("SELECT estado, uuid, razon_social, sustituye_id FROM facturas WHERE estado = 'hecha'")[0] ?? []),
            'y la nueva queda hecha con los datos corregidos');
        $html = $nav->get($urlFactura);
        Pruebas::contiene('Falta cancelarla en el SAT', $html, 'avisa que falta cancelarla en el SAT');
        Pruebas::contiene('por cancelar en el SAT', $nav->get('facturas/index.php'), 'y lo marca en la lista de facturas');
        $nav->enviar($urlFactura, ['id' => $vf, 'accion' => 'cancelada_sat', 'factura_id' => $fid], $urlFactura);
        Pruebas::igual(1, (int) $srv->valor('SELECT cancelada_sat FROM facturas WHERE id = ?', [$fid]), 'se marca como cancelada en el SAT');

        $nav->enviar($urlFactura, ['id' => $vf, 'accion' => 'cancelar', 'motivo' => '03'], $urlFactura);
        Pruebas::igual(0, (int) $srv->valor("SELECT COUNT(*) FROM facturas WHERE estado <> 'cancelada'"), 'cancelar con motivo 03 deja la venta sin factura');
        Pruebas::contiene('Pedir la factura otra vez', $nav->get($urlFactura), 'y se puede pedir otra vez');

        $pedir(['rfc' => 'PEPE800101AB1', 'razon_social' => 'Pepe', 'cp_fiscal' => '64000', 'regimen_fiscal' => '626', 'uso_cfdi' => 'G03']);
        $nav->enviar($urlFactura, ['id' => $vf, 'accion' => 'corregir'] + $corregidos, $urlFactura);
        Pruebas::igual('JOSÉ PÉREZ LÓPEZ', $srv->valor("SELECT razon_social FROM facturas WHERE estado = 'pendiente'"), 'una factura por hacer se puede corregir');
        $nav->enviar($urlFactura, ['id' => $vf, 'accion' => 'cancelar', 'nota' => 'Ya no la quiere'], $urlFactura);
        Pruebas::igual([null, 'Ya no la quiere'], array_values($srv->sql("SELECT motivo_cancelacion, notas FROM facturas ORDER BY id DESC LIMIT 1")[0] ?? []),
            'cancelar una solicitud no pide motivo del SAT');

        $r = $vender(['metodo_pago' => 'tarjeta', 'items' => [['id' => 2, 'cantidad' => '1']]]);
        $vf2 = (int) ($r['venta']['id'] ?? 0);
        $nav->enviar('ventas/factura.php', ['id' => $vf2, 'accion' => 'pedir'] + $corregidos, 'ventas/factura.php?id=' . $vf2);
        $nav->enviar('ventas/cancelar.php', ['id' => $vf2, 'motivo' => 'Se equivocó']);
        Pruebas::igual('cancelada', $srv->valor('SELECT estado FROM facturas WHERE venta_id = ?', [$vf2]), 'cancelar la venta cancela la factura que estaba por hacer');
    } finally {
        $smtp->detener();
    }

    Pruebas::grupo('Inicio y ayuda');
    $paginaInicio = $nav->get('index.php');
    Pruebas::contiene('¿Qué quieres hacer?', $paginaInicio, 'el inicio pregunta qué quieres hacer');
    Pruebas::contiene('Primeros pasos', $paginaInicio, 'y muestra los primeros pasos mientras falte alguno');
    Pruebas::contiene('Cerrar el día', $paginaInicio, 'con la caja abierta ofrece cerrar el día');
    Pruebas::contiene('Me surtieron mercancía', $nav->get('ayuda.php'), 'la ayuda explica cada tarea');

    Pruebas::grupo('Impresora desde el navegador');
    $srv->sql("INSERT INTO ajustes (clave, valor) VALUES ('impresora_tipo', 'windows'), ('impresora_nombre', 'POS-80')
                ON CONFLICT (clave) DO UPDATE SET valor = excluded.valor");
    Pruebas::contiene('"activa":false', $nav->get('ventas/index.php'), 'sin la app de escritorio no intenta usar la impresora USB (no tapa el mensaje de la venta)');
    Pruebas::contiene('desde aquí no se puede usar la impresora USB', $nav->get('ajustes.php'), 'y Configuración explica por qué');
    $srv->sql("UPDATE ajustes SET valor = 'ninguna' WHERE clave = 'impresora_tipo'");
    Pruebas::grupo('Devoluciones en corte y reportes');
    Pruebas::contiene('Devoluciones', $nav->get('caja/index.php'), 'la caja muestra las devoluciones');
    // 500 de fondo + 94.50 en efectivo − 31.50 devueltos (la compra salió y regresó) = 563.
    Pruebas::contiene('$563.00', $nav->get('caja/index.php'), 'efectivo esperado con la devolución');
    $turno = (int) $srv->valor('SELECT id FROM turnos WHERE cerrado_en IS NULL');
    Pruebas::contiene('devolución(es)', $nav->get('reportes/index.php?periodo=hoy'), 'los reportes restan las devoluciones');
    Pruebas::contiene('Devoluciones,', $nav->get('reportes/index.php?periodo=hoy&exportar=resumen'), 'el resumen exportado las incluye');
    Pruebas::contiene('venta_devolucion', implode(',', array_column($srv->sql('SELECT accion FROM auditoria'), 'accion')), 'queda en la bitácora');
    $nav->enviar('caja/acciones.php', ['accion' => 'cerrar', 'contado' => '563', 'notas' => '']);
    $corte = $nav->get('caja/corte.php?id=' . $turno);
    Pruebas::contiene('Devoluciones: efectivo', $corte, 'el corte desglosa las devoluciones');
    Pruebas::igual(563.0, (float) $srv->valor('SELECT efectivo_esperado FROM turnos WHERE id = ?', [$turno]), 'corte cuadrado');
    foreach (['compras/index.php', 'compras/nueva.php', 'inventario/index.php?tipo=cancelacion'] as $pagina) {
        $nav->get($pagina);
        Pruebas::igual(200, $nav->estado, "carga $pagina");
    }
    Pruebas::igual(0, (int) $srv->valor('SELECT COUNT(*) FROM (SELECT 1 FROM pragma_foreign_key_check)'), 'llaves foráneas sanas');
    Pruebas::igual('', $srv->registroErrores(), 'sin errores de PHP');
} finally {
    $srv->detener();
}
