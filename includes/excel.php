<?php
/**
 * TiendaWeb - Archivos de Excel (.xlsx) sin librerías.
 *
 * A diferencia de un CSV, Excel respeta lo que es texto: el folio "000001"
 * y la forma de pago "01" no pierden sus ceros, y los importes salen con
 * dos decimales.
 *
 * Una hoja se describe con renglones; cada celda es:
 *   - un número (int|float)     → número con dos decimales
 *   - un texto                  → texto tal cual
 *   - ['t' => 'texto', 'negrita' => true] o ['n' => 12.5, 'negrita' => true]
 *   - null                      → vacía
 */

declare(strict_types=1);

/** Manda al navegador un libro de Excel. $hojas: [nombre => ['anchos' => [..], 'renglones' => [[...], ...]]]. */
function exportar_excel(string $nombreArchivo, array $hojas): never
{
    $bytes = libro_excel($hojas);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store');
    echo $bytes;
    exit;
}

/** Arma el archivo .xlsx (un ZIP con XML adentro). */
function libro_excel(array $hojas): string
{
    $x = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $archivos = [];
    $lista = $relaciones = $tipos = '';
    $i = 0;
    foreach ($hojas as $nombre => $hoja) {
        $i++;
        $cols = '';
        foreach ($hoja['anchos'] ?? [] as $c => $ancho) {
            $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . (float) $ancho . '" customWidth="1"/>';
        }
        $filas = '';
        foreach (array_values($hoja['renglones']) as $r => $renglon) {
            $celdas = '';
            foreach (array_values($renglon) as $c => $valor) {
                if ($valor === null || $valor === '') {
                    continue;
                }
                $negrita = is_array($valor) && !empty($valor['negrita']);
                if (is_array($valor)) {
                    $valor = $valor['t'] ?? $valor['n'] ?? '';
                }
                $ref = columna_excel($c) . ($r + 1);
                if (is_int($valor) || is_float($valor)) {
                    // Enteros (cantidades, conteos) sin decimales; importes con dos.
                    $estilo = is_int($valor) ? ($negrita ? 3 : 0) : ($negrita ? 3 : 1);
                    $celdas .= '<c r="' . $ref . '" s="' . $estilo . '"><v>' . round((float) $valor, 6) . '</v></c>';
                } else {
                    $celdas .= '<c r="' . $ref . '" t="inlineStr" s="' . ($negrita ? 4 : 2) . '"><is><t xml:space="preserve">' . $x((string) $valor) . '</t></is></c>';
                }
            }
            $filas .= '<row r="' . ($r + 1) . '">' . $celdas . '</row>';
        }
        $archivos["xl/worksheets/hoja$i.xml"] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . ($cols ? "<cols>$cols</cols>" : '') . "<sheetData>$filas</sheetData></worksheet>";
        $lista .= '<sheet name="' . $x(mb_substr((string) $nombre, 0, 31)) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $relaciones .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/hoja' . $i . '.xml"/>';
        $tipos .= '<Override PartName="/xl/worksheets/hoja' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $relaciones .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="estilos.xml"/>';
    $archivos['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/libro.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/estilos.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . $tipos . '</Types>';
    $archivos['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/libro.xml"/></Relationships>';
    $archivos['xl/libro.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . "<sheets>$lista</sheets></workbook>";
    $archivos['xl/_rels/libro.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relaciones . '</Relationships>';
    // Estilos: 0 normal · 1 número 0.00 · 2 texto · 3 número en negritas · 4 texto en negritas.
    $archivos['xl/estilos.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
        . '<xf numFmtId="49" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/></cellXfs>'
        . '</styleSheet>';
    return zip_sin_compresion($archivos);
}

/** 0 → A, 25 → Z, 26 → AA. */
function columna_excel(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

/** ZIP mínimo (sin compresión), suficiente para que Excel lo abra. */
function zip_sin_compresion(array $archivos): string
{
    $datos = $central = '';
    foreach ($archivos as $nombre => $contenido) {
        $crc = crc32($contenido);
        $largo = strlen($contenido);
        $encabezado = pack('vvvvvVVVvv', 20, 0x0800, 0, 0, 0x21, $crc, $largo, $largo, strlen($nombre), 0);
        $central .= pack('V', 0x02014b50) . pack('v', 20) . $encabezado . pack('vvvVV', 0, 0, 0, 0, strlen($datos)) . $nombre;
        $datos .= pack('V', 0x04034b50) . $encabezado . $nombre . $contenido;
    }
    return $datos . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($archivos), count($archivos), strlen($central), strlen($datos), 0);
}
