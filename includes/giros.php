<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * TiendaWeb - Tipos de negocio (giros).
 *
 * Todo lo que cambia entre una tienda y otra vive aquí: productos de ejemplo,
 * categorías sugeridas y textos. Para agregar un giro basta otra entrada en
 * GIROS. Lo que hace el sistema (vender, caja, inventario) es igual para todos.
 *
 * Productos: nombre, código de barras, precio, costo, existencia, mínimo,
 *            controlar inventario, se vende a granel. Todos llevan IVA 16%.
 */

declare(strict_types=1);

const GIRO_PREDETERMINADO = 'general';

const GIROS = [
    'abarrotes' => [
        'etiqueta' => 'Abarrotes',
        'icono'    => 'bi-basket',
        'producto' => 'Ej. Arroz 1 kg, Frijol a granel…',
        'resumen'  => 'Arroz, aceite, refrescos, frijol a granel…',
        'categorias' => ['Abarrotes', 'Bebidas', 'Lácteos', 'Botanas y dulces', 'Limpieza', 'Higiene personal', 'Enlatados', 'A granel'],
        'productos' => [
            ['Arroz 1 kg',                '7501000001014', 28.00, 21.00, 30, 8, 1, 0],
            ['Aceite vegetal 1 L',        '7501000001021', 42.00, 33.50, 20, 5, 1, 0],
            ['Leche entera 1 L',          '7501000001038', 27.00, 22.00, 24, 6, 1, 0],
            ['Atún en lata 140 g',        '7501000001045', 22.00, 16.00, 36, 10, 1, 0],
            ['Sopa de pasta 200 g',       '7501000001052',  9.00,  6.20, 40, 10, 1, 0],
            ['Galletas surtidas',         '7501000001069', 19.00, 13.50, 25, 6, 1, 0],
            ['Refresco de cola 600 ml',   '7501000000062', 18.00, 12.50, 24, 6, 1, 0],
            ['Papas fritas 45 g',         '7501000000079', 17.00, 11.20, 4, 6, 1, 0],
            ['Jabón de barra',            '7501000001076', 16.00, 11.00, 18, 5, 1, 0],
            ['Papel higiénico 4 rollos',  '7501000001083', 32.00, 24.00, 15, 4, 1, 0],
            ['Frijol a granel (kg)',      null,            38.00, 29.00, 20.5, 5, 1, 1],
            ['Azúcar a granel (kg)',      null,            32.00, 24.00, 15.5, 3, 1, 1],
            ['Huevo (kg)',                null,            48.00, 39.00, 12, 3, 1, 1],
        ],
    ],
    'licoreria' => [
        'etiqueta' => 'Licorería',
        'icono'    => 'bi-cup-straw',
        'producto' => 'Ej. Tequila blanco 750 ml, Cerveza lata…',
        'resumen'  => 'Tequila, cerveza, refrescos, hielo…',
        'categorias' => ['Tequila y mezcal', 'Ron', 'Whisky', 'Vodka y ginebra', 'Vinos', 'Cerveza', 'Refrescos y mezcladores', 'Hielo y botanas'],
        'productos' => [
            ['Tequila blanco 750 ml',     '7501000002011', 280.00, 205.00, 18, 4, 1, 0],
            ['Mezcal joven 750 ml',       '7501000002028', 420.00, 310.00, 10, 3, 1, 0],
            ['Ron añejo 750 ml',          '7501000002035', 230.00, 170.00, 14, 4, 1, 0],
            ['Vodka 750 ml',              '7501000002042', 210.00, 155.00, 12, 3, 1, 0],
            ['Whisky 750 ml',             '7501000002059', 380.00, 285.00, 8, 2, 1, 0],
            ['Vino tinto 750 ml',         '7501000002066', 190.00, 135.00, 12, 3, 1, 0],
            ['Cerveza lata 355 ml',       '7501000002073',  22.00,  16.00, 120, 24, 1, 0],
            ['Cerveza botella 1.2 L',     '7501000002080',  52.00,  40.00, 40, 10, 1, 0],
            ['Refresco de cola 2 L',      '7501000002097',  38.00,  29.00, 24, 6, 1, 0],
            ['Agua mineral 2 L',          '7501000002103',  30.00,  22.00, 18, 5, 1, 0],
            ['Cacahuates 100 g',          '7501000002110',  20.00,  13.00, 3, 6, 1, 0],
            ['Hielo bolsa 5 kg',          null,             35.00,  20.00, 15, 5, 1, 0],
            ['Vasos desechables (paquete)', null,           28.00,  18.00, 20, 5, 1, 0],
        ],
    ],
    'papeleria' => [
        'etiqueta' => 'Papelería',
        'icono'    => 'bi-pencil',
        'producto' => 'Ej. Cuaderno profesional, Copia a color…',
        'resumen'  => 'Cuadernos, plumas, copias, impresiones…',
        'categorias' => ['Útiles escolares', 'Cuadernos y libretas', 'Escritura', 'Papel', 'Arte y manualidades', 'Oficina',
                         'Regalos', 'Copias e impresiones', 'Mochilas y estuches', 'Dulcería y bebidas'],
        'productos' => [
            ['Cuaderno profesional raya', '7501000000017', 35.00, 22.50, 40, 10, 1, 0],
            ['Lápiz grafito No. 2',       '7501000000024',  5.00,  2.40, 120, 20, 1, 0],
            ['Pluma tinta azul',          '7501000000031',  8.00,  4.10, 80, 15, 1, 0],
            ['Borrador blanco',           '7501000000048',  6.00,  2.90, 50, 10, 1, 0],
            ['Folder tamaño carta',       '7501000000055',  4.00,  1.80, 60, 15, 1, 0],
            ['Refresco de cola 600 ml',   '7501000000062', 18.00, 12.50, 24, 6, 1, 0],
            ['Papas fritas 45 g',         '7501000000079', 17.00, 11.20, 4, 6, 1, 0],
            ['Hojas blancas sueltas',     null,             0.50,  0.15, 500, 100, 1, 0],
            ['Copia blanco y negro',      null,             1.00,  null, 0, 0, 0, 0],
            ['Copia a color',             null,             5.00,  null, 0, 0, 0, 0],
            ['Impresión blanco y negro',  null,             2.00,  null, 0, 0, 0, 0],
            ['Impresión a color',         null,             8.00,  null, 0, 0, 0, 0],
            ['Azúcar a granel (kg)',      null,            32.00, 24.00, 15.5, 3, 1, 1],
        ],
    ],
    'general' => [
        'etiqueta' => 'Otro',
        'icono'    => 'bi-shop',
        'producto' => 'Ej. Refresco 600 ml, Servicio a domicilio…',
        'resumen'  => 'Un poco de todo; lo ajustas a tu gusto.',
        'categorias' => ['Bebidas', 'Botanas y dulces', 'Limpieza', 'Hogar', 'Papelería', 'Servicios', 'Regalos', 'Varios'],
        'productos' => [
            ['Refresco de cola 600 ml',   '7501000000062', 18.00, 12.50, 24, 6, 1, 0],
            ['Agua natural 1 L',          '7501000003018', 12.00,  7.50, 30, 8, 1, 0],
            ['Papas fritas 45 g',         '7501000000079', 17.00, 11.20, 4, 6, 1, 0],
            ['Galletas surtidas',         '7501000001069', 19.00, 13.50, 25, 6, 1, 0],
            ['Jabón de barra',            '7501000001076', 16.00, 11.00, 18, 5, 1, 0],
            ['Pilas AA (par)',            '7501000003025', 35.00, 24.00, 20, 5, 1, 0],
            ['Encendedor',                '7501000003032', 12.00,  6.50, 30, 8, 1, 0],
            ['Cuaderno profesional raya', '7501000000017', 35.00, 22.50, 40, 10, 1, 0],
            ['Pluma tinta azul',          '7501000000031',  8.00,  4.10, 80, 15, 1, 0],
            ['Bolsa de regalo',           null,            15.00,  8.00, 30, 8, 1, 0],
            ['Azúcar a granel (kg)',      null,            32.00, 24.00, 15.5, 3, 1, 1],
            ['Servicio a domicilio',      null,            20.00,  null, 0, 0, 0, 0],
            ['Copia blanco y negro',      null,             1.00,  null, 0, 0, 0, 0],
        ],
    ],
];

/** Datos del giro indicado; si no existe, los del giro predeterminado. */
function datos_giro(string $giro): array
{
    return GIROS[$giro] ?? GIROS[GIRO_PREDETERMINADO];
}

/** Datos del giro del negocio. */
function giro_actual(): array
{
    return datos_giro(ajuste('giro'));
}

/**
 * ¿Se muestran las listas escolares? Son de papelería; otro giro las ve solo si
 * ya tiene listas creadas, para que a nadie se le escondan sus datos.
 */
function usa_listas_escolares(): bool
{
    return ajuste('giro') === 'papeleria' || (int) db()->query('SELECT COUNT(*) FROM listas')->fetchColumn() > 0;
}
