<?php
/**
 * TiendaWeb - Facturas pedidas por los clientes.
 *
 * El sistema NO timbra (eso lo hace un PAC o el portal gratuito del SAT):
 * guarda los datos fiscales del cliente, la lista de facturas por hacer con
 * su desglose para el contador, y el folio fiscal cuando ya se hizo.
 */

declare(strict_types=1);

/** Regímenes fiscales del SAT (c_RegimenFiscal) que más se usan. */
const REGIMENES_FISCALES = [
    '605' => 'Sueldos y salarios (empleados)',
    '612' => 'Personas físicas con actividad empresarial y profesional',
    '626' => 'Régimen Simplificado de Confianza (RESICO)',
    '621' => 'Incorporación fiscal',
    '606' => 'Arrendamiento',
    '625' => 'Plataformas tecnológicas',
    '616' => 'Sin obligaciones fiscales',
    '601' => 'Personas morales: general de ley',
    '603' => 'Personas morales sin fines de lucro',
    '622' => 'Actividades agrícolas, ganaderas, silvícolas y pesqueras',
    '608' => 'Demás ingresos',
];

/** Uso del CFDI (c_UsoCFDI): para qué usará el cliente la factura. */
const USOS_CFDI = [
    'G03' => 'Gastos en general',
    'G01' => 'Compra de mercancía para revender',
    'I04' => 'Equipo de cómputo y accesorios',
    'I02' => 'Mobiliario y equipo de oficina',
    'D10' => 'Colegiaturas (servicios educativos)',
    'S01' => 'Sin efectos fiscales',
];

/** Forma de pago del SAT según cómo se cobró. */
const FORMA_PAGO_SAT = [
    'efectivo'      => '01',
    'transferencia' => '03',
    'tarjeta'       => '04',   // tarjeta de crédito; si fue de débito es 28
    'credito'       => '99',   // por definir: se paga después
];

const ESTADOS_FACTURA = [
    'pendiente' => ['Por hacer', 'warning'],
    'hecha'     => ['Hecha', 'success'],
    'cancelada' => ['Cancelada', 'secondary'],
];

/** Factura vigente (pendiente o hecha) de una venta, o la última cancelada; null si nunca se pidió. */
function factura_de_venta(int $ventaId): ?array
{
    $st = db()->prepare("SELECT * FROM facturas WHERE venta_id = :id ORDER BY (estado = 'cancelada'), id DESC LIMIT 1");
    $st->execute([':id' => $ventaId]);
    return $st->fetch() ?: null;
}

function facturas_pendientes(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM facturas WHERE estado = 'pendiente'")->fetchColumn();
}

/**
 * Limpia y valida datos fiscales. Devuelve [datos, errores].
 * Campos: rfc, razon_social, cp_fiscal, regimen_fiscal, uso_cfdi, correo.
 */
function validar_datos_fiscales(array $entrada): array
{
    $d = [
        'rfc'            => mb_strtoupper(preg_replace('/[\s\-]+/u', '', (string) ($entrada['rfc'] ?? '')) ?? ''),
        'razon_social'   => mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string) ($entrada['razon_social'] ?? '')) ?? '')),
        'cp_fiscal'      => trim((string) ($entrada['cp_fiscal'] ?? '')),
        'regimen_fiscal' => (string) ($entrada['regimen_fiscal'] ?? ''),
        'uso_cfdi'       => (string) ($entrada['uso_cfdi'] ?? ''),
        'correo'         => mb_strtolower(trim((string) ($entrada['correo'] ?? ''))),
    ];
    $errores = [];
    if (!preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', $d['rfc'])) {
        $errores['rfc'] = 'El RFC tiene 12 o 13 letras y números, por ejemplo GOMA800101AB1. Cópialo de su Constancia de Situación Fiscal.';
    } elseif (in_array($d['rfc'], ['XAXX010101000', 'XEXX010101000'], true)) {
        $errores['rfc'] = 'Ese es el RFC genérico de "público en general": esas ventas van en la factura global, no se pide factura.';
    }
    if ($d['razon_social'] === '' || mb_strlen($d['razon_social']) > 254) {
        $errores['razon_social'] = 'Escribe el nombre o razón social tal como viene en su constancia.';
    }
    if (!preg_match('/^\d{5}$/', $d['cp_fiscal'])) {
        $errores['cp_fiscal'] = 'El código postal son 5 números.';
    }
    if (!isset(REGIMENES_FISCALES[$d['regimen_fiscal']])) {
        $errores['regimen_fiscal'] = 'Elige su régimen fiscal (viene en su constancia).';
    }
    if (!isset(USOS_CFDI[$d['uso_cfdi']])) {
        $errores['uso_cfdi'] = 'Elige para qué usará la factura.';
    }
    if ($d['correo'] !== '' && !filter_var($d['correo'], FILTER_VALIDATE_EMAIL)) {
        $errores['correo'] = 'El correo no es válido.';
    }
    return [$d, $errores];
}

/** Forma de pago del SAT de una venta: la del método con que se pagó más. */
function forma_pago_sat(int $ventaId): string
{
    $pagos = pagos_venta($ventaId);
    arsort($pagos);
    return FORMA_PAGO_SAT[array_key_first($pagos) ?? 'efectivo'] ?? '99';
}

/**
 * Renglones de una venta para facturar, ya sin lo devuelto.
 * Cada uno: nombre, cantidad, unidad, tasa, base (sin IVA, centavos), iva (centavos), total (centavos).
 */
function conceptos_factura(int $ventaId): array
{
    $st = db()->prepare('SELECT d.*, p.unidad FROM detalle_ventas d LEFT JOIN productos p ON p.id = d.producto_id WHERE d.venta_id = :id ORDER BY d.id');
    $st->execute([':id' => $ventaId]);
    $devuelto = devuelto_por_renglon($ventaId);
    $conceptos = [];
    foreach ($st->fetchAll() as $d) {
        $vendido = a_milesimas($d['cantidad']);
        $queda = $vendido - ($devuelto[(int) $d['id']]['milesimas'] ?? 0);
        if ($queda <= 0) {
            continue;
        }
        $neto = a_centavos($d['subtotal']) - a_centavos($d['descuento']) - ($devuelto[(int) $d['id']]['centavos'] ?? 0);
        $iva  = (int) round(a_centavos($d['iva']) * $queda / $vendido);
        $tasa = (string) ($d['tasa_iva'] ?? '16');
        $conceptos[] = ['nombre' => $d['nombre_producto'], 'cantidad' => $queda / 1000, 'unidad' => $d['unidad'] ?? 'pieza',
                        'tasa' => $tasa, 'base' => $neto - $iva, 'iva' => $iva, 'total' => $neto];
    }
    return $conceptos;
}

/**
 * Totales de una lista de conceptos, en centavos: base (todo lo que va sin IVA), la base de cada tasa
 * (base16, base8, base0, exento), iva16, iva8 y total. El SAT pide cada tasa por separado.
 */
function totales_factura(array $conceptos): array
{
    $t = ['base' => 0, 'base16' => 0, 'base8' => 0, 'base0' => 0, 'exento' => 0, 'iva16' => 0, 'iva8' => 0, 'total' => 0];
    foreach ($conceptos as $c) {
        $t['base'] += $c['base'];
        $t['total'] += $c['total'];
        $t[$c['tasa'] === 'exento' ? 'exento' : 'base' . $c['tasa']] += $c['base'];
        if ($c['tasa'] === '16' || $c['tasa'] === '8') {
            $t['iva' . $c['tasa']] += $c['iva'];
        }
    }
    return $t;
}

/** Clave de unidad del SAT sugerida según cómo se vende el producto. */
const CLAVES_UNIDAD_SAT = [
    'pieza' => 'H87', 'paquete' => 'XPK', 'caja' => 'XBX', 'juego' => 'SET', 'kit' => 'KT', 'hoja' => 'H87', 'pliego' => 'H87',
    'rollo' => 'XRO', 'metro' => 'MTR', 'kilo' => 'KGM', 'gramo' => 'GRM', 'litro' => 'LTR', 'servicio' => 'E48',
];

/** ¿La venta tiene una parte a crédito (fiado)? */
function venta_con_credito(int $ventaId): bool
{
    return isset(pagos_venta($ventaId)['credito']);
}

/** Motivos de cancelación del SAT. */
const MOTIVOS_CANCELACION = [
    '02' => 'Tenía errores (no se va a reponer)',
    '01' => 'Tenía errores y ya hice otra que la reemplaza',
    '03' => 'La venta no se llevó a cabo (se canceló o devolvió todo)',
    '04' => 'La venta ya entró en la factura global',
];

/** ¿Quien entró puede cancelar facturas, marcarlas como hechas y mandarlas? */
function puede_administrar_facturas(): bool
{
    return permiso('reportes') || permiso('cancelar');
}

/** Facturas hechas que se cancelaron aquí pero falta cancelarlas en el SAT. */
function facturas_por_cancelar_en_sat(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM facturas WHERE estado = 'cancelada' AND motivo_cancelacion IS NOT NULL AND cancelada_sat = 0")->fetchColumn();
}

function factura_por_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM facturas WHERE id = :id');
    $st->execute([':id' => $id]);
    return $st->fetch() ?: null;
}

/** Archivos guardados de una factura: [tipo => ['id', 'nombre', 'tamano']]. */
function archivos_factura(int $facturaId): array
{
    $st = db()->prepare('SELECT id, tipo, nombre, LENGTH(contenido) AS tamano FROM factura_archivos WHERE factura_id = :id');
    $st->execute([':id' => $facturaId]);
    return array_column($st->fetchAll(), null, 'tipo');
}

/** Guarda (o reemplaza) el XML o el PDF de una factura. */
function guardar_archivo_factura(int $facturaId, string $tipo, string $nombre, string $contenido): void
{
    db()->prepare('DELETE FROM factura_archivos WHERE factura_id = :id AND tipo = :tipo')->execute([':id' => $facturaId, ':tipo' => $tipo]);
    $st = db()->prepare('INSERT INTO factura_archivos (factura_id, tipo, nombre, contenido, subido_en) VALUES (:id, :tipo, :nombre, :contenido, :fecha)');
    $st->bindValue(':id', $facturaId, PDO::PARAM_INT);
    $st->bindValue(':tipo', $tipo);
    $st->bindValue(':nombre', mb_substr(basename($nombre), 0, 120));
    $st->bindValue(':contenido', $contenido, PDO::PARAM_LOB);
    $st->bindValue(':fecha', date('Y-m-d H:i:s'));
    $st->execute();
}

/**
 * Lee lo importante del XML de una factura (CFDI 3.3 o 4.0) ya timbrada:
 * uuid (folio fiscal), rfc_emisor, rfc_receptor, total, fecha. Lanza RuntimeException si no es un CFDI timbrado.
 */
function leer_xml_cfdi(string $xml): array
{
    $doc = new DOMDocument();
    $anterior = libxml_use_internal_errors(true);
    $ok = $xml !== '' && $doc->loadXML($xml, LIBXML_NONET);   // sin entidades externas ni red
    libxml_clear_errors();
    libxml_use_internal_errors($anterior);
    if (!$ok || $doc->documentElement?->localName !== 'Comprobante') {
        throw new RuntimeException('El archivo XML no es una factura (CFDI). Sube el .xml que te dio el SAT o tu contador.');
    }
    $xp = new DOMXPath($doc);
    $attr = function (string $ruta, string $nombre) use ($xp): string {
        $n = $xp->query($ruta)->item(0);
        return $n instanceof DOMElement ? trim($n->getAttribute($nombre)) : '';
    };
    $uuid = mb_strtoupper($attr("//*[local-name()='TimbreFiscalDigital']", 'UUID'));
    if (!preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/', $uuid)) {
        throw new RuntimeException('El XML no está timbrado (no trae folio fiscal). Sube el XML ya timbrado.');
    }
    return [
        'uuid'         => $uuid,
        'rfc_emisor'   => mb_strtoupper($attr("/*/*[local-name()='Emisor']", 'Rfc')),
        'rfc_receptor' => mb_strtoupper($attr("/*/*[local-name()='Receptor']", 'Rfc')),
        'total'        => $doc->documentElement->getAttribute('Total'),
        'fecha'        => $doc->documentElement->getAttribute('Fecha'),
    ];
}

/** Manda la factura (XML y PDF) al correo indicado y lo anota. */
function enviar_factura_por_correo(array $factura, string $para): void
{
    $st = db()->prepare('SELECT tipo, nombre, contenido FROM factura_archivos WHERE factura_id = :id ORDER BY tipo DESC');
    $st->execute([':id' => $factura['id']]);
    $adjuntos = [];
    foreach ($st->fetchAll() as $a) {
        $adjuntos[] = ['nombre' => $a['nombre'], 'contenido' => $a['contenido'], 'tipo' => $a['tipo'] === 'pdf' ? 'application/pdf' : 'application/xml'];
    }
    if (!$adjuntos) {
        throw new RuntimeException('Primero sube el XML (y si tienes, el PDF) de la factura.');
    }
    $negocio = nombre_negocio();
    $texto = "Hola, {$factura['razon_social']}:\n\n"
           . "Te mandamos la factura de tu compra con ticket " . folio($factura['venta_id']) . ".\n"
           . ($factura['uuid'] ? "Folio fiscal: {$factura['uuid']}\n" : '')
           . "\nVienen adjuntos el XML" . (count($adjuntos) > 1 ? ' y el PDF' : '') . ".\n\n"
           . "¡Gracias por tu compra!\n$negocio\n" . (ajuste('negocio_datos') !== '' ? ajuste('negocio_datos') . "\n" : '');
    enviar_correo($para, "Tu factura de $negocio (ticket " . folio($factura['venta_id']) . ')', $texto, $adjuntos);
    db()->prepare('UPDATE facturas SET enviada_en = :fecha, enviada_a = :para WHERE id = :id')
        ->execute([':fecha' => date('Y-m-d H:i:s'), ':para' => $para, ':id' => $factura['id']]);
    auditar('factura', 'Mandó por correo la factura de la venta ' . folio($factura['venta_id']) . " a $para", 'venta', (int) $factura['venta_id']);
}
