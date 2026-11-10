<?php
/**
 * TiendaWeb - Impresora de tickets ESC/POS y cajón de dinero.
 *
 * Funciona con impresoras térmicas compatibles con ESC/POS (Epson, 3nStar,
 * EC Line, Xprinter y genéricas de 58 u 80 mm). El cajón se conecta al
 * puerto "DK" / "Cash drawer" de la impresora y se abre con un pulso.
 *
 * Conexiones:
 *   - windows: impresora instalada en Windows (USB). Los bytes se mandan
 *              en modo RAW con la API de impresión (winspool) vía FFI.
 *   - red:     impresora con cable de red, puerto 9100.
 */

declare(strict_types=1);

/* =====================================================================
 *  Comandos ESC/POS
 * ===================================================================== */

const ESC = "\x1B";
const GS  = "\x1D";

const ESCPOS_INICIAR         = ESC . '@';
const ESCPOS_IZQUIERDA       = ESC . "a\x00";
const ESCPOS_CENTRO          = ESC . "a\x01";
const ESCPOS_NEGRITA_SI      = ESC . "E\x01";
const ESCPOS_NEGRITA_NO      = ESC . "E\x00";
const ESCPOS_TAMANO_NORMAL   = GS . "!\x00";
const ESCPOS_DOBLE_ALTO      = GS . "!\x01";
const ESCPOS_DOBLE           = GS . "!\x11";     // doble alto y doble ancho
const ESCPOS_CORTAR          = GS . "VA\x03";    // avanza y corta (parcial)

/** Pulso para abrir el cajón: ESC p m t1 t2 (m = 0 conector 2, m = 1 conector 5). */
function escpos_cajon(string $pin): string
{
    return ESC . 'p' . ($pin === '5' ? "\x01" : "\x00") . "\x19\xFA";
}

/** Selecciona la tabla de caracteres de la impresora (ESC t n). */
function escpos_tabla(string $caracteres): string
{
    return match ($caracteres) {
        'pc850' => ESC . "t\x02",
        'pc437' => ESC . "t\x00",
        default => '',
    };
}

/**
 * Convierte texto UTF-8 a la tabla de la impresora. Lo que la tabla no
 * tiene se cambia por la letra sin acento, nunca por signos raros.
 */
function texto_impresora(string $texto, string $caracteres): string
{
    $sinAcento = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        '¡' => '!', '¿' => '?', '°' => 'o', '«' => '"', '»' => '"', '“' => '"', '”' => '"',
        '‘' => "'", '’' => "'", '–' => '-', '—' => '-', '…' => '...', '·' => '-',
    ];
    // Caracteres comunes en español en las tablas PC437 y PC850.
    $comunes = [
        'á' => "\xA0", 'é' => "\x82", 'í' => "\xA1", 'ó' => "\xA2", 'ú' => "\xA3",
        'ü' => "\x81", 'Ü' => "\x9A", 'ñ' => "\xA4", 'Ñ' => "\xA5", 'É' => "\x90",
        '¡' => "\xAD", '¿' => "\xA8", '«' => "\xAE", '»' => "\xAF",
    ];
    $soloPc850 = ['Á' => "\xB5", 'Í' => "\xD6", 'Ó' => "\xE0", 'Ú' => "\xE9", '°' => "\xF8", '·' => "\xFA"];

    $mapa = match ($caracteres) {
        'pc850' => $soloPc850 + $comunes + $sinAcento,
        'pc437' => $comunes + ['°' => "\xF8"] + $sinAcento,
        default => $sinAcento,
    };
    // Lo que no está en el mapa (emojis, otros alfabetos…) se cambia por "?" antes de convertir.
    $texto = preg_replace_callback('/[^\x00-\x7F]/u', fn($m) => isset($mapa[$m[0]]) ? $m[0] : '?', $texto) ?? '';
    return strtr($texto, $mapa);
}

/* =====================================================================
 *  Armado del ticket (en texto UTF-8; se convierte al final)
 * ===================================================================== */

/** Renglones de ancho fijo para el papel elegido. */
final class TicketTexto
{
    private array $partes = [];

    public function __construct(public readonly int $columnas, private readonly string $caracteres)
    {
    }

    /** Comando ESC/POS sin convertir. */
    public function comando(string $bytes): self
    {
        $this->partes[] = $bytes;
        return $this;
    }

    /** Uno o varios renglones; corta las palabras que no caben. */
    public function linea(string $texto = '', ?int $columnas = null): self
    {
        $ancho = $columnas ?? $this->columnas;
        foreach ($this->partir($texto, $ancho) as $renglon) {
            $this->partes[] = texto_impresora($renglon, $this->caracteres) . "\n";
        }
        return $this;
    }

    /** Texto a la izquierda y a la derecha en el mismo renglón. */
    public function columnas(string $izquierda, string $derecha, ?int $columnas = null): self
    {
        $ancho = $columnas ?? $this->columnas;
        $espacio = $ancho - mb_strlen($izquierda) - mb_strlen($derecha);
        if ($espacio < 1) {
            return $this->linea($izquierda, $ancho)->linea(str_pad_izquierda($derecha, $ancho), $ancho);
        }
        return $this->linea($izquierda . str_repeat(' ', $espacio) . $derecha, $ancho);
    }

    public function separador(): self
    {
        return $this->linea(str_repeat('-', $this->columnas));
    }

    public function bytes(): string
    {
        return implode('', $this->partes);
    }

    private function partir(string $texto, int $ancho): array
    {
        $renglones = [];
        foreach (preg_split('/\R/u', $texto) as $parrafo) {
            if (mb_strlen($parrafo) <= $ancho) {      // cabe: se respetan los espacios (alineación)
                $renglones[] = $parrafo;
                continue;
            }
            $actual = '';
            foreach (preg_split('/ +/u', trim($parrafo)) as $palabra) {
                while (mb_strlen($palabra) > $ancho) {          // palabra más larga que el renglón
                    if ($actual !== '') {
                        $renglones[] = $actual;
                        $actual = '';
                    }
                    $renglones[] = mb_substr($palabra, 0, $ancho);
                    $palabra = mb_substr($palabra, $ancho);
                }
                if ($actual === '') {
                    $actual = $palabra;
                } elseif (mb_strlen($actual) + 1 + mb_strlen($palabra) <= $ancho) {
                    $actual .= ' ' . $palabra;
                } else {
                    $renglones[] = $actual;
                    $actual = $palabra;
                }
            }
            $renglones[] = $actual;
        }
        return $renglones;
    }
}

function str_pad_izquierda(string $texto, int $ancho): string
{
    return str_repeat(' ', max(0, $ancho - mb_strlen($texto))) . $texto;
}

/** Caracteres por renglón con la letra normal (Font A). */
function columnas_papel(string $papel): int
{
    return $papel === '58' ? 32 : 48;
}

/** Encabezado común: inicializa, tabla de caracteres, nombre y datos del negocio. */
function ticket_nuevo(array $aj): TicketTexto
{
    $t = new TicketTexto(columnas_papel($aj['papel']), $aj['caracteres']);
    $t->comando(ESCPOS_INICIAR . escpos_tabla($aj['caracteres']) . ESCPOS_CENTRO);
    if (trim($aj['negocio_nombre']) !== '') {
        $t->comando(ESCPOS_DOBLE . ESCPOS_NEGRITA_SI)
          ->linea($aj['negocio_nombre'], intdiv($t->columnas, 2))
          ->comando(ESCPOS_TAMANO_NORMAL . ESCPOS_NEGRITA_NO);
    }
    if (trim($aj['negocio_datos']) !== '') {
        $t->linea($aj['negocio_datos']);
    }
    return $t->comando(ESCPOS_IZQUIERDA);
}

/** Pie común: avanza el papel y corta si la impresora tiene cortador. */
function ticket_cerrar(TicketTexto $t, array $aj): string
{
    $t->linea()->linea()->linea();
    if ($aj['cortar_papel'] === '1') {
        $t->comando(ESCPOS_CORTAR);
    }
    return $t->bytes();
}

/** Ticket de una venta, igual al de la pantalla de detalle. */
function escpos_ticket_venta(array $venta, array $detalle, array $aj): string
{
    $t = ticket_nuevo($aj);
    $t->comando(ESCPOS_CENTRO)->linea('Ticket de venta')->comando(ESCPOS_IZQUIERDA)
      ->separador()
      ->columnas('Folio:', folio($venta['id']))
      ->columnas('Fecha:', fecha_fmt($venta['fecha']));
    if ($venta['estado'] === 'cancelada') {
        $t->comando(ESCPOS_CENTRO . ESCPOS_NEGRITA_SI)->linea('*** CANCELADA ***')
          ->comando(ESCPOS_NEGRITA_NO . ESCPOS_IZQUIERDA);
    }
    $t->separador();

    $articulos = 0.0;
    foreach ($detalle as $d) {
        $articulos += (float) $d['cantidad'];
        $t->linea($d['nombre_producto'])
          ->columnas('  ' . cantidad_fmt($d['cantidad']) . ' x ' . dinero($d['precio_unitario']), dinero($d['subtotal']));
        if ((float) ($d['descuento'] ?? 0) > 0) {
            $t->columnas('    Descuento', '-' . dinero($d['descuento']));
        }
    }

    $t->separador()
      ->columnas('Artículos:', cantidad_fmt($articulos));
    if ((float) ($venta['descuento'] ?? 0) > 0) {
        $t->columnas('Subtotal:', dinero($venta['subtotal']))
          ->columnas('Descuento:', '-' . dinero($venta['descuento']));
    }
    if (($aj['iva_ticket'] ?? '1') === '1') {
        foreach (renglones_desglose_iva($detalle) as [$etiqueta, $centavos]) {
            $t->columnas($etiqueta . ':', dinero($centavos / 100));
        }
    }
    $t->comando(ESCPOS_DOBLE_ALTO . ESCPOS_NEGRITA_SI)
      ->columnas('TOTAL:', dinero($venta['total']))
      ->comando(ESCPOS_TAMANO_NORMAL . ESCPOS_NEGRITA_NO)
      ->linea('(' . importe_en_letra(a_centavos($venta['total'])) . ')');

    $pagos = pagos_venta((int) $venta['id']);
    if (count($pagos) > 1) {
        $t->linea('Pago dividido:');
        foreach ($pagos as $m => $monto) {
            $t->columnas('  ' . metodo_pago_etiqueta($m), dinero($monto));
        }
    } else {
        $t->columnas('Pago:', metodo_pago_etiqueta($venta['metodo_pago']));
    }
    if (isset($pagos['efectivo']) || $venta['metodo_pago'] === 'efectivo') {
        $t->columnas('Efectivo recibido:', dinero($venta['recibido']))
          ->columnas('Cambio:', dinero($venta['cambio']));
    }
    if (!empty($venta['cliente_id']) && ($cliente = cliente_por_id((int) $venta['cliente_id']))) {
        $t->linea('Cliente: ' . $cliente['nombre']);
        if (isset($pagos['credito'])) {
            if (count($pagos) > 1) {
                $t->columnas('A crédito en esta venta:', dinero($pagos['credito']));
            }
            $t->comando(ESCPOS_NEGRITA_SI)->columnas('Saldo pendiente:', dinero(saldo_cliente((int) $cliente['id']) / 100))
              ->comando(ESCPOS_NEGRITA_NO)
              ->linea()->linea()->comando(ESCPOS_CENTRO)->linea('______________________')->linea('Firma de conformidad')
              ->comando(ESCPOS_IZQUIERDA);
        }
    }
    if (!empty($venta['usuario_id'])) {
        $t->columnas('Atendió:', nombre_usuario((int) $venta['usuario_id']));
    }
    $factura = factura_de_venta((int) $venta['id']);
    if ($factura && $factura['estado'] !== 'cancelada') {
        $t->separador()->linea('Factura ' . ($factura['estado'] === 'hecha' ? 'emitida' : 'solicitada') . ' a:')
          ->linea($factura['razon_social'])->columnas('RFC:', $factura['rfc']);
        if ($factura['uuid']) {
            $t->linea('Folio fiscal: ' . $factura['uuid']);
        }
    } elseif (trim($aj['ticket_factura'] ?? '') !== '' && $venta['estado'] === 'completada') {
        $t->separador()->comando(ESCPOS_CENTRO)->linea($aj['ticket_factura'])->comando(ESCPOS_IZQUIERDA);
    }
    if (trim($aj['ticket_pie']) !== '') {
        $t->separador()->comando(ESCPOS_CENTRO)->linea($aj['ticket_pie'])->comando(ESCPOS_IZQUIERDA);
    }
    return ticket_cerrar($t, $aj);
}

/** Hoja de prueba: confirma papel, acentos y corte. */
function escpos_prueba(array $aj): string
{
    $t = ticket_nuevo($aj);
    $t->comando(ESCPOS_CENTRO)->linea('Prueba de impresión')->comando(ESCPOS_IZQUIERDA)
      ->separador()
      ->linea('Si lees bien esto, la impresora está lista:')
      ->linea('áéíóú ÁÉÍÓÚ ñÑ ü ¡Hola! ¿Qué tal?')
      ->separador()
      ->columnas('Papel:', $aj['papel'] . ' mm (' . $t->columnas . ' caracteres)')
      ->columnas('Caracteres:', strtoupper($aj['caracteres']))
      ->columnas('Fecha:', fecha_fmt(date('Y-m-d H:i:s')))
      ->linea(str_repeat('1234567890', (int) ceil($t->columnas / 10)), $t->columnas);
    return ticket_cerrar($t, $aj);
}

/* =====================================================================
 *  Envío a la impresora
 * ===================================================================== */

/** Manda bytes a la impresora configurada. Lanza RuntimeException con un mensaje para el usuario. */
function enviar_a_impresora(string $bytes, ?array $aj = null): void
{
    $aj ??= ajustes();
    if ($aj['impresora_tipo'] === 'red') {
        enviar_por_red($bytes, $aj['impresora_ip'], (int) $aj['impresora_puerto']);
    } elseif ($aj['impresora_tipo'] === 'windows') {
        enviar_a_windows($bytes, $aj['impresora_nombre']);
    } else {
        throw new RuntimeException('No hay impresora de tickets configurada. Ve a Configuración.');
    }
}

function enviar_por_red(string $bytes, string $ip, int $puerto): void
{
    if (!filter_var($ip, FILTER_VALIDATE_IP) || $puerto < 1 || $puerto > 65535) {
        throw new RuntimeException('La dirección IP o el puerto de la impresora no son válidos.');
    }
    $sock = @fsockopen($ip, $puerto, $errno, $errstr, 3);
    if ($sock === false) {
        throw new RuntimeException("No se pudo conectar con la impresora en $ip:$puerto. Revisa que esté encendida y conectada a la red.");
    }
    stream_set_timeout($sock, 5);
    $escritos = fwrite($sock, $bytes);
    fclose($sock);
    if ($escritos !== strlen($bytes)) {
        throw new RuntimeException('La impresora de red no recibió todo el ticket.');
    }
}

/* ---------------------------------------------------------------------
 *  Impresoras de Windows (API winspool vía FFI)
 * --------------------------------------------------------------------- */

function winspool(): FFI
{
    static $ffi = null;
    if ($ffi instanceof FFI) {
        return $ffi;
    }
    if (!extension_loaded('ffi') || !in_array(strtolower((string) ini_get('ffi.enable')), ['1', 'on', 'true'], true)) {
        throw new RuntimeException('Desde el navegador no se puede usar la impresora USB. Abre el programa con el ícono "Punto de venta" del escritorio para imprimir.');
    }
    $ffi = FFI::cdef('
        typedef struct { uint16_t* pPrinterName; uint16_t* pServerName; uint32_t Attributes; } PRINTER_INFO_4W;
        typedef struct { uint16_t* pDocName; uint16_t* pOutputFile; uint16_t* pDatatype; } DOC_INFO_1W;
        int EnumPrintersW(uint32_t Flags, uint16_t* Name, uint32_t Level, void* pPrinterEnum, uint32_t cbBuf, uint32_t* pcbNeeded, uint32_t* pcReturned);
        int OpenPrinterW(uint16_t* pPrinterName, void** phPrinter, void* pDefault);
        int ClosePrinter(void* hPrinter);
        uint32_t StartDocPrinterW(void* hPrinter, uint32_t Level, DOC_INFO_1W* pDocInfo);
        int EndDocPrinter(void* hPrinter);
        int StartPagePrinter(void* hPrinter);
        int EndPagePrinter(void* hPrinter);
        int WritePrinter(void* hPrinter, void* pBuf, uint32_t cbBuf, uint32_t* pcWritten);
    ', 'winspool.drv');
    return $ffi;
}

/** Texto UTF-8 → arreglo UTF-16 terminado en cero (hay que conservarlo vivo mientras se use). */
function cadena_ancha(FFI $ffi, string $texto): FFI\CData
{
    $utf16 = mb_convert_encoding($texto, 'UTF-16LE', 'UTF-8') . "\0\0";
    $arreglo = $ffi->new('uint16_t[' . (strlen($utf16) / 2) . ']');
    FFI::memcpy($arreglo, $utf16, strlen($utf16));
    return $arreglo;
}

function leer_cadena_ancha(FFI\CData $puntero): string
{
    $bytes = '';
    for ($i = 0; ($c = $puntero[$i]) !== 0; $i++) {
        $bytes .= pack('v', $c);
    }
    return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
}

/** Nombres de las impresoras instaladas en Windows (locales y compartidas). */
function impresoras_windows(): array
{
    $ffi = winspool();
    $flags = 0x2 | 0x4;   // PRINTER_ENUM_LOCAL | PRINTER_ENUM_CONNECTIONS
    $necesario = $ffi->new('uint32_t');
    $cuantas   = $ffi->new('uint32_t');
    $ffi->EnumPrintersW($flags, null, 4, null, 0, FFI::addr($necesario), FFI::addr($cuantas));
    if ($necesario->cdata === 0) {
        return [];
    }
    $buffer = $ffi->new('uint8_t[' . $necesario->cdata . ']');
    if (!$ffi->EnumPrintersW($flags, null, 4, FFI::addr($buffer), $necesario->cdata, FFI::addr($necesario), FFI::addr($cuantas))) {
        return [];
    }
    $lista = $ffi->cast('PRINTER_INFO_4W*', FFI::addr($buffer));
    $nombres = [];
    for ($i = 0; $i < $cuantas->cdata; $i++) {
        $nombres[] = leer_cadena_ancha($lista[$i]->pPrinterName);
    }
    sort($nombres, SORT_NATURAL | SORT_FLAG_CASE);
    return $nombres;
}

/** Manda los bytes tal cual (RAW) a una impresora de Windows. */
function enviar_a_windows(string $bytes, string $impresora): void
{
    $ffi = winspool();
    if (!in_array($impresora, impresoras_windows(), true)) {
        throw new RuntimeException("No se encontró la impresora «{$impresora}» en Windows. Revisa que esté conectada o elige otra en Configuración.");
    }
    $nombre = cadena_ancha($ffi, $impresora);
    $manija = $ffi->new('void*');
    if (!$ffi->OpenPrinterW($ffi->cast('uint16_t*', FFI::addr($nombre)), FFI::addr($manija), null)) {
        throw new RuntimeException("Windows no permitió abrir la impresora «{$impresora}».");
    }
    try {
        $documento = cadena_ancha($ffi, 'Punto de venta');
        $tipoDatos = cadena_ancha($ffi, 'RAW');
        $info = $ffi->new('DOC_INFO_1W');
        $info->pDocName    = $ffi->cast('uint16_t*', FFI::addr($documento));
        $info->pOutputFile = null;
        $info->pDatatype   = $ffi->cast('uint16_t*', FFI::addr($tipoDatos));
        if ($ffi->StartDocPrinterW($manija, 1, FFI::addr($info)) === 0) {
            throw new RuntimeException("La impresora «{$impresora}» no aceptó el trabajo.");
        }
        $ffi->StartPagePrinter($manija);
        $datos = $ffi->new('uint8_t[' . strlen($bytes) . ']');
        FFI::memcpy($datos, $bytes, strlen($bytes));
        $escritos = $ffi->new('uint32_t');
        $ok = $ffi->WritePrinter($manija, FFI::addr($datos), strlen($bytes), FFI::addr($escritos));
        $ffi->EndPagePrinter($manija);
        $ffi->EndDocPrinter($manija);
        if (!$ok || $escritos->cdata !== strlen($bytes)) {
            throw new RuntimeException("No se pudo enviar el ticket completo a «{$impresora}».");
        }
    } finally {
        $ffi->ClosePrinter($manija);
    }
}
