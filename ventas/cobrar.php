<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Registra una venta (recibe JSON por POST, responde JSON).
 *
 * El navegador solo envía QUÉ productos y CUÁNTOS. Los precios, existencias,
 * subtotales, total y cambio se calculan aquí con los datos de la base de datos.
 *
 * Cuerpo esperado:
 * {
 *   "token": "32 caracteres hexadecimales (uno por intento de cobro)",
 *   "metodo_pago": "efectivo" | "transferencia" | "tarjeta" | "credito",
 *   "recibido": "200.00",              // solo en efectivo
 *   "cliente_id": 5,                   // obligatorio en crédito, opcional en los demás
 *   "descuento": { "tipo": "monto" | "porcentaje", "valor": "10" },   // opcional, requiere permiso
 *   "items": [ { "id": 1, "cantidad": "2" }, ... ]
 * }
 *
 * Requiere la caja abierta: la venta queda en el turno y con el usuario que cobró.
 */
require_once __DIR__ . '/../includes/app.php';

requerir_post_csrf();

$entrada = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($entrada)) {
    responder_json(['ok' => false, 'mensaje' => 'Solicitud no válida.'], 400);
}

/* ---------------------------------------------------------------
 * 1. Validación de los datos recibidos
 * --------------------------------------------------------------- */
$token = (string) ($entrada['token'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    responder_json(['ok' => false, 'mensaje' => 'Identificador de operación no válido. Recarga la página.'], 422);
}

/*
 * Pagos. Un solo método ("metodo_pago": "tarjeta") o pago dividido
 * ("metodo_pago": "mixto", "pagos": [{"metodo":"efectivo","monto":"100"}, {"metodo":"tarjeta","monto":"50"}]).
 * En efectivo, el monto es lo que entrega el cliente (de ahí sale el cambio);
 * en los demás, lo que se cobra por ese medio. $pagosPedidos: [metodo => centavos|null] (null = "lo que falte").
 */
$metodo = (string) ($entrada['metodo_pago'] ?? '');
if (!array_key_exists($metodo, METODOS_PAGO) && $metodo !== 'mixto') {
    responder_json(['ok' => false, 'mensaje' => 'Selecciona un método de pago válido.'], 422);
}
$pagosPedidos = [];
if ($metodo === 'mixto') {
    foreach ((array) ($entrada['pagos'] ?? []) as $p) {
        $m = (string) ($p['metodo'] ?? '');
        $monto = normalizar_decimal($p['monto'] ?? null, 2, 10);
        if (!array_key_exists($m, METODOS_PAGO) || $monto === null || isset($pagosPedidos[$m])) {
            responder_json(['ok' => false, 'mensaje' => 'Hay un pago no válido o repetido.'], 422);
        }
        if (a_centavos($monto) > 0) {
            $pagosPedidos[$m] = a_centavos($monto);
        }
    }
    if (count($pagosPedidos) < 2) {
        responder_json(['ok' => false, 'mensaje' => 'Para dividir el pago escribe al menos dos formas de pago.'], 422);
    }
} elseif ($metodo === 'efectivo') {
    $pagosPedidos['efectivo'] = null;   // el importe recibido se lee abajo
} else {
    $pagosPedidos[$metodo] = null;
}

$items = $entrada['items'] ?? null;
if (!is_array($items) || count($items) === 0) {
    responder_json(['ok' => false, 'mensaje' => 'El carrito está vacío.'], 422);
}
if (count($items) > 200) {
    responder_json(['ok' => false, 'mensaje' => 'La venta tiene demasiados renglones (máximo 200).'], 422);
}

// Agrupa por producto (si el mismo producto viene dos veces se suman las cantidades).
$pedidos = [];   // producto_id => cantidad en milésimas
foreach ($items as $item) {
    $id = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $cantidad = normalizar_decimal($item['cantidad'] ?? null, 3, 9);
    if (!$id || $cantidad === null || a_milesimas($cantidad) <= 0) {
        responder_json(['ok' => false, 'mensaje' => 'Hay una cantidad no válida en el carrito.'], 422);
    }
    $pedidos[$id] = ($pedidos[$id] ?? 0) + a_milesimas($cantidad);
}

$recibidoCentavos = null;
if ($metodo === 'efectivo') {
    $recibido = normalizar_decimal($entrada['recibido'] ?? null, 2, 10);
    if ($recibido === null) {
        responder_json(['ok' => false, 'mensaje' => 'Escribe el importe recibido en efectivo.'], 422);
    }
    $recibidoCentavos = a_centavos($recibido);
} elseif (isset($pagosPedidos['efectivo'])) {
    $recibidoCentavos = $pagosPedidos['efectivo'];
}

// Descuento: un monto fijo o un porcentaje del subtotal (se calcula ya con los precios de la base).
$descuentoTipo  = null;
$descuentoValor = 0;    // centavos (monto) o centésimas de porcentaje
$desc = $entrada['descuento'] ?? null;
if (is_array($desc) && ($desc['valor'] ?? '') !== '' && ($desc['valor'] ?? null) !== null) {
    $descuentoTipo = ($desc['tipo'] ?? '') === 'porcentaje' ? 'porcentaje' : 'monto';
    $valor = normalizar_decimal($desc['valor'], 2, 8);
    if ($valor === null || ($descuentoTipo === 'porcentaje' && a_centavos($valor) > 10000)) {
        responder_json(['ok' => false, 'mensaje' => 'El descuento no es válido.'], 422);
    }
    $descuentoValor = a_centavos($valor);
    if ($descuentoValor > 0 && !permiso('descuentos')) {
        responder_json(['ok' => false, 'mensaje' => 'Tu usuario no tiene permiso para hacer descuentos.'], 403);
    }
}

$clienteId = filter_var($entrada['cliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
if (array_key_exists('credito', $pagosPedidos)) {
    if (!permiso('credito')) {
        responder_json(['ok' => false, 'mensaje' => 'Tu usuario no tiene permiso para vender a crédito.'], 403);
    }
    if (!$clienteId) {
        responder_json(['ok' => false, 'mensaje' => 'Para vender a crédito elige al cliente.'], 422);
    }
}

$pdo = db();

/** Respuesta con los datos de una venta ya guardada. */
function respuesta_venta(PDO $pdo, int $ventaId, bool $repetida): void
{
    $st = $pdo->prepare('SELECT v.id, v.fecha, v.subtotal, v.descuento, v.total, v.iva, v.metodo_pago, v.recibido, v.cambio,
                                v.cliente_id, c.nombre AS cliente
                         FROM ventas v LEFT JOIN clientes c ON c.id = v.cliente_id WHERE v.id = :id');
    $st->execute([':id' => $ventaId]);
    $v = $st->fetch();
    $st = $pdo->prepare('SELECT metodo, monto FROM pagos WHERE venta_id = :id ORDER BY id');
    $st->execute([':id' => $ventaId]);
    $pagos = array_map(fn($p) => ['metodo' => $p['metodo'], 'monto' => (float) $p['monto']], $st->fetchAll());
    $conCredito = in_array('credito', array_column($pagos, 'metodo'), true);
    responder_json([
        'ok'       => true,
        'repetida' => $repetida,
        'venta'    => [
            'id'          => (int) $v['id'],
            'folio'       => folio($v['id']),
            'fecha'       => fecha_fmt($v['fecha']),
            'subtotal'    => (float) $v['subtotal'],
            'descuento'   => (float) $v['descuento'],
            'total'       => (float) $v['total'],
            'iva'         => (float) $v['iva'],
            'metodo_pago' => $v['metodo_pago'],
            'pagos'       => $pagos,
            'recibido'    => $v['recibido'] !== null ? (float) $v['recibido'] : null,
            'cambio'      => (float) $v['cambio'],
            'cliente'     => $v['cliente'],
            'saldo'       => $conCredito ? saldo_cliente((int) $v['cliente_id']) / 100 : null,
        ],
    ]);
}

/** Si este token ya generó una venta (doble clic, reenvío), devuelve esa venta. */
function venta_por_token(PDO $pdo, string $token): ?int
{
    $st = $pdo->prepare('SELECT id FROM ventas WHERE token_operacion = :token');
    $st->execute([':token' => $token]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int) $id;
}

$existente = venta_por_token($pdo, $token);
if ($existente !== null) {
    respuesta_venta($pdo, $existente, true);
}

/* ---------------------------------------------------------------
 * 2. Transacción: bloquea los productos, valida, guarda y descuenta
 * --------------------------------------------------------------- */
try {
    transaccion_iniciar();

    $turno = turno_abierto();
    if (!$turno) {
        transaccion_revertir();
        responder_json(['ok' => false, 'mensaje' => 'La caja está cerrada. Ábrela para poder cobrar.', 'caja_cerrada' => true], 409);
    }
    $cliente = $clienteId ? cliente_por_id($clienteId) : null;
    if ($clienteId && (!$cliente || !(int) $cliente['activo'])) {
        transaccion_revertir();
        responder_json(['ok' => false, 'mensaje' => 'El cliente elegido no existe o está desactivado.'], 422);
    }

    $ids    = array_keys($pedidos);
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    // La transacción (BEGIN IMMEDIATE) ya reservó la base: nadie más puede vender la misma última pieza.
    // Productos pedidos más los que hacen falta para resolver paquetes y kits (productos base y componentes).
    $comp = cargar_composicion($ids);
    $productos = $comp['productos'];

    $errores = [];
    $lineas  = [];
    $totalCentavos = 0;
    $escalones = escalones_de($ids);   // precios por cantidad (mayoreo)
    $consumoTotal = [];                // producto real => milésimas que se van a descontar

    foreach ($pedidos as $id => $milesimas) {
        $p = $productos[$id] ?? null;
        if ($p === null) {
            $errores[] = 'Un producto del carrito ya no existe.';
            continue;
        }
        if (!(int) $p['activo']) {
            $errores[] = "«{$p['nombre']}» está desactivado y no se puede vender.";
            continue;
        }
        if (!(int) $p['permite_fraccion'] && $milesimas % 1000 !== 0) {
            $errores[] = "«{$p['nombre']}» solo se vende en cantidades enteras.";
            continue;
        }
        $consumo = consumo_producto($id, $milesimas, $comp);
        foreach ($consumo as $real => $m) {
            $consumoTotal[$real] = ($consumoTotal[$real] ?? 0) + $m;
        }

        // Costo: el propio, o el de lo que contiene (paquete o kit).
        $costo = $p['costo'];
        if ($costo === null && $consumo && $p['tipo'] !== 'normal') {
            $costoCentavos = 0;
            foreach ($consumo as $real => $m) {
                if ($productos[$real]['costo'] === null) {
                    $costoCentavos = null;
                    break;
                }
                $costoCentavos += importe_centavos(a_centavos($productos[$real]['costo']), (int) round($m * 1000 / $milesimas));
            }
            $costo = $costoCentavos === null ? null : centavos_a_decimal($costoCentavos);
        }

        $precioCentavos   = precio_por_cantidad(a_centavos($p['precio']), $escalones[$id] ?? [], $milesimas);
        $subtotalCentavos = importe_centavos($precioCentavos, $milesimas);
        $totalCentavos   += $subtotalCentavos;

        $lineas[] = [
            'producto_id'     => $id,
            'nombre'          => $p['nombre'],
            'cantidad'        => number_format($milesimas / 1000, 3, '.', ''),
            'precio'          => centavos_a_decimal($precioCentavos),
            'costo'           => $costo,
            'subtotal'        => centavos_a_decimal($subtotalCentavos),
            'descuenta_stock' => $consumo ? 1 : 0,
            'consumo'         => $consumo,
            'milesimas'       => $milesimas,
            'tasa_iva'        => $p['tasa_iva'],
        ];
    }

    // Existencias: se revisa el total de cada producto real (el mismo lápiz puede venir suelto, en caja y en un kit).
    foreach ($consumoTotal as $real => $m) {
        $hay = a_milesimas($productos[$real]['existencia']);
        if ($m > $hay) {
            $errores[] = "No hay suficiente «{$productos[$real]['nombre']}»: quedan " . cantidad_fmt($hay / 1000)
                . ', se necesitan ' . cantidad_fmt($m / 1000) . '.';
        }
    }

    if ($errores) {
        transaccion_revertir();
        responder_json([
            'ok'       => false,
            'mensaje'  => implode("\n", $errores),
            'refrescar'=> true,
        ], 409);
    }

    // Descuento sobre el subtotal; se reparte entre los renglones para que la utilidad sea exacta.
    $subtotalCentavos = $totalCentavos;
    $descuentoCentavos = 0;
    if ($descuentoTipo === 'porcentaje') {
        $descuentoCentavos = (int) round($subtotalCentavos * $descuentoValor / 10000);
    } elseif ($descuentoTipo === 'monto') {
        $descuentoCentavos = $descuentoValor;
    }
    if ($descuentoCentavos > $subtotalCentavos) {
        transaccion_revertir();
        responder_json(['ok' => false, 'mensaje' => 'El descuento (' . dinero($descuentoCentavos / 100)
            . ') no puede ser mayor que la venta (' . dinero($subtotalCentavos / 100) . ').'], 422);
    }
    $totalCentavos = $subtotalCentavos - $descuentoCentavos;
    $porRepartir = $descuentoCentavos;
    $ivaCentavos = 0;
    foreach ($lineas as $i => $l) {
        $parte = $i === array_key_last($lineas)
            ? $porRepartir
            : (int) floor($descuentoCentavos * a_centavos($l['subtotal']) / max(1, $subtotalCentavos));
        $lineas[$i]['descuento'] = centavos_a_decimal($parte);
        $porRepartir -= $parte;
        // IVA contenido en lo que realmente se cobra del renglón (precio con IVA menos su parte del descuento).
        $ivaLinea = iva_contenido(a_centavos($l['subtotal']) - $parte, $l['tasa_iva']);
        $lineas[$i]['iva'] = centavos_a_decimal($ivaLinea);
        $ivaCentavos += $ivaLinea;
    }

    // Reparto del pago: tarjeta, transferencia y crédito se cobran tal cual; el efectivo cubre lo que falte.
    $falla = fn(string $mensaje) => [transaccion_revertir(), responder_json(['ok' => false, 'mensaje' => $mensaje], 422)];
    $pagos = [];
    $noEfectivo = 0;
    foreach ($pagosPedidos as $m => $monto) {
        if ($m !== 'efectivo') {
            $pagos[$m] = $monto ?? $totalCentavos;
            $noEfectivo += $pagos[$m];
        }
    }
    if ($noEfectivo > $totalCentavos) {
        $falla('Los pagos con tarjeta, transferencia o crédito (' . dinero($noEfectivo / 100)
            . ') suman más que el total (' . dinero($totalCentavos / 100) . ').');
    }
    $faltaEfectivo = $totalCentavos - $noEfectivo;
    $cambioCentavos = 0;
    if (array_key_exists('efectivo', $pagosPedidos)) {
        if ($faltaEfectivo === 0 && $metodo === 'mixto') {
            $falla('Los otros pagos ya cubren el total: quita el efectivo.');
        }
        if ($recibidoCentavos < $faltaEfectivo) {
            $falla('El efectivo recibido (' . dinero($recibidoCentavos / 100) . ') no alcanza: faltan '
                . dinero(($faltaEfectivo - $recibidoCentavos) / 100) . ' para el total de ' . dinero($totalCentavos / 100) . '.');
        }
        $pagos['efectivo'] = $faltaEfectivo;
        $cambioCentavos = $recibidoCentavos - $faltaEfectivo;
    } elseif ($faltaEfectivo !== 0) {
        $falla('Los pagos (' . dinero($noEfectivo / 100) . ') no cubren el total (' . dinero($totalCentavos / 100) . ').');
    }

    // Crédito: no puede pasar del límite del cliente.
    if (isset($pagos['credito']) && $cliente['limite_credito'] !== null) {
        $saldo = saldo_cliente((int) $cliente['id']);
        $limite = a_centavos($cliente['limite_credito']);
        if ($saldo + $pagos['credito'] > $limite) {
            $falla("«{$cliente['nombre']}» debe " . dinero($saldo / 100) . ' y su límite es ' . dinero($limite / 100)
                . '. Con ' . dinero($pagos['credito'] / 100) . ' más a crédito se pasaría. Puede abonar primero o pagar de otra forma.');
        }
    }

    // Encabezado de la venta (la fecha la pone PHP con la hora de México).
    $st = $pdo->prepare('INSERT INTO ventas (fecha, subtotal, descuento, total, iva, metodo_pago, recibido, cambio, estado,
                                             token_operacion, usuario_id, turno_id, cliente_id)
                         VALUES (:fecha, :subtotal, :descuento, :total, :iva, :metodo, :recibido, :cambio, \'completada\',
                                 :token, :usuario, :turno, :cliente)');
    $st->execute([
        ':fecha'     => date('Y-m-d H:i:s'),
        ':subtotal'  => centavos_a_decimal($subtotalCentavos),
        ':descuento' => centavos_a_decimal($descuentoCentavos),
        ':total'     => centavos_a_decimal($totalCentavos),
        ':iva'       => centavos_a_decimal($ivaCentavos),
        ':metodo'    => count($pagos) > 1 ? 'mixto' : array_key_first($pagos),
        ':recibido'  => $recibidoCentavos !== null ? centavos_a_decimal($recibidoCentavos) : null,
        ':cambio'    => centavos_a_decimal($cambioCentavos),
        ':token'     => $token,
        ':usuario'   => usuario_id(),
        ':turno'     => $turno['id'],
        ':cliente'   => $cliente['id'] ?? null,
    ]);
    $ventaId = (int) $pdo->lastInsertId();

    $insPago = $pdo->prepare('INSERT INTO pagos (venta_id, metodo, monto) VALUES (:venta, :metodo, :monto)');
    foreach ($pagos as $m => $monto) {
        if ($monto > 0) {
            $insPago->execute([':venta' => $ventaId, ':metodo' => $m, ':monto' => centavos_a_decimal($monto)]);
        }
    }
    if ($descuentoCentavos > 0) {
        auditar('venta_descuento', 'Venta ' . folio($ventaId) . ': descuento de ' . dinero($descuentoCentavos / 100)
            . ' sobre ' . dinero($subtotalCentavos / 100), 'venta', $ventaId);
    }

    $insDetalle = $pdo->prepare('INSERT INTO detalle_ventas
            (venta_id, producto_id, nombre_producto, cantidad, precio_unitario, costo_unitario, subtotal, descuenta_stock, descuento, tasa_iva, iva)
        VALUES (:venta, :producto, :nombre, :cantidad, :precio, :costo, :subtotal, :descuenta, :descuento, :tasa, :iva)');
    $descontar = $pdo->prepare('UPDATE productos SET existencia = ROUND(existencia - :cantidad, 3)
                                WHERE id = :id AND controlar_stock = 1 AND existencia >= :minimo');

    foreach ($lineas as $l) {
        $insDetalle->execute([
            ':venta'     => $ventaId,
            ':producto'  => $l['producto_id'],
            ':nombre'    => $l['nombre'],
            ':cantidad'  => $l['cantidad'],
            ':precio'    => $l['precio'],
            ':costo'     => $l['costo'],
            ':subtotal'  => $l['subtotal'],
            ':descuenta' => $l['descuenta_stock'],
            ':descuento' => $l['descuento'],
            ':tasa'      => $l['tasa_iva'],
            ':iva'       => $l['iva'],
        ]);
        // Se descuenta de los productos reales (en un paquete o kit, de lo que contiene).
        foreach ($l['consumo'] as $real => $m) {
            $cantidad = number_format($m / 1000, 3, '.', '');
            $descontar->execute([':cantidad' => $cantidad, ':id' => $real, ':minimo' => $cantidad]);
            if ($descontar->rowCount() !== 1) {
                throw new RuntimeException("No se pudo descontar la existencia de «{$productos[$real]['nombre']}». La venta no se guardó.");
            }
            anotar_movimiento_inventario($real, 'venta', -$m, null,
                $real !== $l['producto_id'] ? "Venta de «{$l['nombre']}»" : null, $ventaId);
        }
    }

    transaccion_confirmar();
} catch (Throwable $ex) {
    if (en_transaccion()) {
        transaccion_revertir();
    }
    // Dos envíos simultáneos con el mismo token: el segundo choca con el índice único.
    if ($ex instanceof PDOException && $ex->getCode() === '23000') {
        $existente = venta_por_token($pdo, $token);
        if ($existente !== null) {
            respuesta_venta($pdo, $existente, true);
        }
    }
    error_log('TiendaWeb: error al registrar venta: ' . $ex->getMessage());
    $mensaje = $ex instanceof RuntimeException && !($ex instanceof PDOException)
        ? $ex->getMessage()
        : 'No se pudo registrar la venta. No se cobró nada; intenta de nuevo.';
    responder_json(['ok' => false, 'mensaje' => $mensaje], 500);
}

respuesta_venta($pdo, $ventaId, false);
