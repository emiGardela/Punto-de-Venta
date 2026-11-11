<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * TiendaWeb - Códigos de barras para etiquetas (sin librerías externas).
 *
 * - Code 128 (lo leen todos los lectores USB): subconjunto C para números de
 *   longitud par (más compacto) y B para todo lo demás.
 * - EAN-13 interno: los productos sin código de fábrica pueden recibir uno
 *   con prefijo 200 (rango 200-299 reservado para uso dentro de la tienda,
 *   nunca choca con productos comerciales).
 */

declare(strict_types=1);

/** Anchos de barra/espacio de cada símbolo Code 128 (0-105) y el de paro (106). Cada uno suma 11 módulos (paro: 13). */
const CODE128_PATRONES = [
    '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
    '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
    '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
    '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
    '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
    '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
    '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
    '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
    '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
    '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
    '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
];

/** Valores Code 128 (con inicio, dígito de control y paro) de un texto ASCII imprimible. */
function code128_valores(string $texto): array
{
    if ($texto === '' || !preg_match('/^[\x20-\x7E]+$/', $texto)) {
        throw new InvalidArgumentException('El código solo puede tener letras, números y signos comunes.');
    }
    if (preg_match('/^\d+$/', $texto) && strlen($texto) % 2 === 0) {
        $valores = [105];                                  // inicio C: pares de dígitos
        foreach (str_split($texto, 2) as $par) {
            $valores[] = (int) $par;
        }
    } else {
        $valores = [104];                                  // inicio B
        foreach (str_split($texto) as $c) {
            $valores[] = ord($c) - 32;
        }
    }
    $suma = $valores[0];
    foreach ($valores as $i => $v) {
        $suma += $i * $v;
    }
    $valores[] = $suma % 103;                              // dígito de control
    $valores[] = 106;                                      // paro
    return $valores;
}

/** SVG del código (ancho en módulos; se escala con CSS). */
function code128_svg(string $texto, int $alto = 40): string
{
    $x = 10;                                               // zona de silencio de 10 módulos
    $barras = '';
    foreach (code128_valores($texto) as $v) {
        foreach (str_split(CODE128_PATRONES[$v]) as $i => $ancho) {
            if ($i % 2 === 0) {                            // posiciones pares = barra; impares = espacio
                $barras .= '<rect x="' . $x . '" y="0" width="' . $ancho . '" height="' . $alto . '"/>';
            }
            $x += (int) $ancho;
        }
    }
    $total = $x + 10;
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $alto . '" preserveAspectRatio="none" '
        . 'shape-rendering="crispEdges" role="img" aria-label="Código ' . htmlspecialchars($texto, ENT_QUOTES) . '">'
        . '<rect width="100%" height="100%" fill="#fff"/><g fill="#000">' . $barras . '</g></svg>';
}

/** Dígito verificador EAN-13 de los primeros 12 dígitos. */
function ean13_digito(string $doce): int
{
    $suma = 0;
    foreach (str_split($doce) as $i => $d) {
        $suma += (int) $d * ($i % 2 === 0 ? 1 : 3);
    }
    return (10 - $suma % 10) % 10;
}

/** Código EAN-13 interno para un producto: 200 + id con ceros + dígito verificador. */
function ean13_interno(int $productoId): string
{
    $doce = '200' . str_pad((string) $productoId, 9, '0', STR_PAD_LEFT);
    return $doce . ean13_digito($doce);
}
