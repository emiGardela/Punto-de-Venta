<?php
/**
 * Pruebas de reglas de negocio puras (sin base de datos ni servidor).
 */

declare(strict_types=1);

Pruebas::grupo('Unitarias: números exactos');
Pruebas::igual('35.50', normalizar_decimal('35,5', 2), 'coma decimal "35,5" → 35.50');
Pruebas::igual('1234.50', normalizar_decimal('$1,234.5', 2), 'miles y signo de pesos');
Pruebas::igual(null, normalizar_decimal('12.345', 2), 'rechaza más decimales de los permitidos');
Pruebas::igual(null, normalizar_decimal('-5', 2), 'rechaza negativos');
Pruebas::igual(4000, importe_centavos(3200, 1250), '1.25 kg × $32.00 = $40.00');
Pruebas::igual(10, importe_centavos(1, 10000), '10 × $0.01 = $0.10 (sin errores de punto flotante)');
Pruebas::igual(30, a_centavos(0.1 + 0.2), '0.1 + 0.2 se redondea a 30 centavos');

Pruebas::grupo('Unitarias: IVA incluido en el precio');
Pruebas::igual(1600, iva_contenido(11600, '16'), '$116.00 con IVA 16% incluye $16.00');
Pruebas::igual(800, iva_contenido(10800, '8'), '$108.00 con IVA 8% incluye $8.00');
Pruebas::igual(0, iva_contenido(18000, '0'), 'tasa 0% no incluye IVA');
Pruebas::igual(0, iva_contenido(18000, 'exento'), 'exento no incluye IVA');
Pruebas::igual(869, iva_contenido(6300, '16'), '$63.00 al 16% incluye $8.69 (redondeo al centavo)');
$desglose = desglose_iva([
    ['tasa_iva' => '16', 'subtotal' => 70, 'descuento' => 7, 'iva' => 8.69],
    ['tasa_iva' => '0', 'subtotal' => 32, 'descuento' => 0, 'iva' => 0],
    ['tasa_iva' => null, 'subtotal' => 10, 'descuento' => 0, 'iva' => 0],
]);
Pruebas::igual(['0' => ['base' => 3200, 'iva' => 0], '16' => ['base' => 5431, 'iva' => 869]], $desglose,
    'desglose por tasa (ignora renglones antiguos sin tasa)');

Pruebas::grupo('Unitarias: ticket ESC/POS');
Pruebas::igual("\xA4", texto_impresora('ñ', 'pc850'), 'ñ en PC850');
Pruebas::igual("\xB5", texto_impresora('Á', 'pc850'), 'Á en PC850');
Pruebas::igual('A', texto_impresora('Á', 'pc437'), 'Á sin equivalente en PC437 → A');
Pruebas::igual('Papeleria', texto_impresora('Papelería', 'sin_acentos'), 'modo sin acentos');
Pruebas::igual('?', texto_impresora('😀', 'pc850'), 'emoji → ?');
Pruebas::igual("\x1Bp\x00\x19\xFA", escpos_cajon('2'), 'pulso del cajón (pin 2)');
Pruebas::igual("\x1Bp\x01\x19\xFA", escpos_cajon('5'), 'pulso del cajón (pin 5)');
$t = new TicketTexto(32, 'pc850');
$t->columnas('TOTAL:', '$120.00');
Pruebas::igual("TOTAL:                   \$120.00\n", $t->bytes(), 'alineación a la derecha en 32 columnas');

Pruebas::grupo('Unitarias: códigos de barras');
require_once __DIR__ . '/../includes/codigo_barras.php';
$sumasOk = true;
foreach (CODE128_PATRONES as $i => $patron) {
    $sumasOk = $sumasOk && array_sum(str_split($patron)) === ($i === 106 ? 13 : 11);
}
Pruebas::verdadero($sumasOk && count(array_unique(CODE128_PATRONES)) === 107, 'tabla Code 128 completa y consistente');
Pruebas::igual(55, array_slice(code128_valores('PJJ123C'), -2, 1)[0], 'dígito de control Code 128 (B)');
Pruebas::igual(104, code128_valores('7501031311309')[0], 'número de longitud impar usa el subconjunto B');
Pruebas::igual(105, code128_valores('200000000005')[0], 'número de longitud par usa el subconjunto C');
Pruebas::igual(2, ean13_digito('750100000001'), 'dígito verificador EAN-13');
Pruebas::igual('2000000000053', ean13_interno(5), 'código interno EAN-13 con prefijo 200');
Pruebas::contiene('<svg', code128_svg('ABC-123'), 'genera el dibujo SVG');

Pruebas::grupo('Unitarias: total con letra y datos fiscales');
require_once __DIR__ . '/../includes/devoluciones.php';
require_once __DIR__ . '/../includes/facturas.php';
Pruebas::igual('CIENTO VEINTICINCO PESOS 50/100 M.N.', importe_en_letra(12550), '125.50 con letra');
Pruebas::igual('UN PESO 00/100 M.N.', importe_en_letra(100), 'un peso');
Pruebas::igual('CIEN PESOS 00/100 M.N.', importe_en_letra(10000), 'cien pesos');
Pruebas::igual('VEINTIÚN PESOS 00/100 M.N.', importe_en_letra(2100), 'veintiún pesos');
Pruebas::igual('MIL DOSCIENTOS TREINTA Y CUATRO PESOS 05/100 M.N.', importe_en_letra(123405), 'mil doscientos treinta y cuatro');
Pruebas::igual('UN MILLÓN DE PESOS 00/100 M.N.', importe_en_letra(100000000), 'un millón de pesos');
[$d, $err] = validar_datos_fiscales(['rfc' => 'goma-800101-ab1', 'razon_social' => ' maría  gómez ', 'cp_fiscal' => '64000', 'regimen_fiscal' => '612', 'uso_cfdi' => 'G03']);
Pruebas::igual([[], 'GOMA800101AB1', 'MARÍA GÓMEZ'], [$err, $d['rfc'], $d['razon_social']], 'limpia RFC y razón social');
[, $err] = validar_datos_fiscales(['rfc' => 'XAXX010101000', 'razon_social' => 'X', 'cp_fiscal' => '123', 'regimen_fiscal' => '999', 'uso_cfdi' => 'G03']);
Pruebas::igual(['rfc', 'cp_fiscal', 'regimen_fiscal'], array_keys($err), 'rechaza RFC genérico, CP y régimen inválidos');
$x = leer_xml_cfdi('<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Total="5.00"><cfdi:Receptor Rfc="pepe800101ab1"/>'
    . '<cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="6f1a2b3c-1234-4abc-9def-0123456789ab"/></cfdi:Complemento></cfdi:Comprobante>');
Pruebas::igual(['6F1A2B3C-1234-4ABC-9DEF-0123456789AB', 'PEPE800101AB1', '5.00'], [$x['uuid'], $x['rfc_receptor'], $x['total']], 'lee folio fiscal, RFC y total del XML');
try {
    leer_xml_cfdi('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///c:/windows/win.ini">]><cfdi:Comprobante xmlns:cfdi="x">&e;</cfdi:Comprobante>');
    Pruebas::verdadero(false, 'rechaza un XML sin timbre');
} catch (RuntimeException) {
    Pruebas::verdadero(true, 'rechaza un XML sin timbre (y no lee archivos de la computadora)');
}
require_once __DIR__ . '/../includes/correo.php';
$m = mensaje_mime('tienda@prueba.mx', 'Papelería Ñ', 'a@b.mx', 'Tu factura', "Hola\n.línea con punto", [['nombre' => 'f.xml', 'contenido' => '<x/>', 'tipo' => 'application/xml']]);
Pruebas::contiene('From: =?UTF-8?B?' . base64_encode('Papelería Ñ') . '?= <tienda@prueba.mx>', $m, 'el remitente con acentos va codificado');
Pruebas::contiene('filename="f.xml"', $m, 'el adjunto va en el mensaje');
