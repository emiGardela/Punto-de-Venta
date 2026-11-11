<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Genera empaquetar/tiendaweb.ico (el mismo logo que el favicon del sistema:
 * cuadro azul redondeado con una canasta blanca). No necesita GD.
 *
 *     C:\xampp\php\php.exe empaquetar\generar_icono.php
 */

declare(strict_types=1);

const AZUL   = [0x1d, 0x5f, 0xd1];
const BLANCO = [0xff, 0xff, 0xff];

/** Color del punto (x, y) en coordenadas de 0 a 16, o null si es transparente. */
function color_en(float $x, float $y): ?array
{
    // Cuadro con esquinas redondeadas (radio 3.5).
    $r = 3.5;
    $cx = min(max($x, $r), 16 - $r);
    $cy = min(max($y, $r), 16 - $r);
    if (($x - $cx) ** 2 + ($y - $cy) ** 2 > $r * $r) {
        return null;
    }
    // Canasta: trapecio de (3.5,5)-(12.5,5) a (4.8,10)-(11.2,10).
    if ($y >= 5 && $y <= 10) {
        $t = ($y - 5) / 5;
        if ($x >= 3.5 + 1.3 * $t && $x <= 12.5 - 1.3 * $t) {
            return BLANCO;
        }
    }
    // Asa de la canasta.
    if ($y >= 3.2 && $y <= 5 && (abs($x - 5) < 0.6 || abs($x - 11) < 0.6)) {
        return BLANCO;
    }
    // Ruedas.
    foreach ([[5.8, 12], [10.2, 12]] as [$rx, $ry]) {
        if (($x - $rx) ** 2 + ($y - $ry) ** 2 <= 1.0) {
            return BLANCO;
        }
    }
    return AZUL;
}

/** Imagen BMP (32 bits, de abajo hacia arriba) de $n × $n píxeles para el ICO. */
function imagen(int $n): string
{
    $sub = 4;   // 4×4 muestras por píxel para bordes suaves
    $pixeles = '';
    for ($fila = $n - 1; $fila >= 0; $fila--) {
        for ($col = 0; $col < $n; $col++) {
            $suma = [0, 0, 0];
            $cubiertas = 0;
            for ($i = 0; $i < $sub; $i++) {
                for ($j = 0; $j < $sub; $j++) {
                    $c = color_en(($col + ($j + 0.5) / $sub) * 16 / $n, ($fila + ($i + 0.5) / $sub) * 16 / $n);
                    if ($c !== null) {
                        $suma[0] += $c[0]; $suma[1] += $c[1]; $suma[2] += $c[2];
                        $cubiertas++;
                    }
                }
            }
            $a = (int) round(255 * $cubiertas / ($sub * $sub));
            $k = max(1, $cubiertas);
            $pixeles .= chr(intdiv($suma[2], $k)) . chr(intdiv($suma[1], $k)) . chr(intdiv($suma[0], $k)) . chr($a);
        }
    }
    $mascara = str_repeat("\0", (int) (ceil($n / 32) * 4) * $n);
    $cabecera = pack('VVVvvVVVVVV', 40, $n, $n * 2, 1, 32, 0, strlen($pixeles) + strlen($mascara), 0, 0, 0, 0);
    return $cabecera . $pixeles . $mascara;
}

$tamanos = [16, 24, 32, 48, 64, 128, 256];
$datos = array_map('imagen', $tamanos);
$ico = pack('vvv', 0, 1, count($tamanos));
$desplazamiento = 6 + 16 * count($tamanos);
foreach ($tamanos as $i => $n) {
    $ico .= pack('CCCCvvVV', $n % 256, $n % 256, 0, 0, 1, 32, strlen($datos[$i]), $desplazamiento);
    $desplazamiento += strlen($datos[$i]);
}
file_put_contents(__DIR__ . '/tiendaweb.ico', $ico . implode('', $datos));
echo "Listo: " . __DIR__ . DIRECTORY_SEPARATOR . "tiendaweb.ico\n";
