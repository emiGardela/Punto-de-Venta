<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * TiendaWeb - Conexión a la base de datos (SQLite con PDO).
 *
 * Toda la información se guarda en UN solo archivo: tiendaweb.sqlite.
 * No hace falta instalar ni iniciar MySQL. La primera vez que se abre el
 * sistema el archivo se crea solo, con las tablas y productos de ejemplo.
 *
 * Ubicación del archivo (la primera que aplique):
 *   1. La carpeta indicada en la variable de entorno TIENDAWEB_DATOS.
 *   2. %LOCALAPPDATA%\TiendaWeb  (por ejemplo C:\Users\Ana\AppData\Local\TiendaWeb)
 *      Así los datos no se pierden al actualizar o reinstalar el programa.
 *   3. La carpeta "datos" dentro del sistema.
 */

declare(strict_types=1);

const DB_ARCHIVO_NOMBRE = 'tiendaweb.sqlite';

// Súbelo cada vez que database.sql cambie: las bases existentes se actualizan solas al abrirse
// (ver migrar_base()).
const DB_ESQUEMA_VERSION = 9;

/** Carpeta donde viven la base de datos y los respaldos automáticos. */
function carpeta_datos(): string
{
    static $carpeta = null;
    if ($carpeta !== null) {
        return $carpeta;
    }
    $propia = getenv('TIENDAWEB_DATOS');
    $local  = getenv('LOCALAPPDATA');
    if (is_string($propia) && $propia !== '') {
        $carpeta = rtrim($propia, '\\/');
    } elseif (is_string($local) && $local !== '') {
        $carpeta = rtrim($local, '\\/') . DIRECTORY_SEPARATOR . 'TiendaWeb';
    } else {
        $carpeta = __DIR__ . DIRECTORY_SEPARATOR . 'datos';
    }
    if (!is_dir($carpeta) && !@mkdir($carpeta, 0775, true) && !is_dir($carpeta)) {
        throw new RuntimeException('No se pudo crear la carpeta de datos: ' . $carpeta);
    }
    return $carpeta;
}

function archivo_base_datos(): string
{
    return carpeta_datos() . DIRECTORY_SEPARATOR . DB_ARCHIVO_NOMBRE;
}

/**
 * Quita acentos y pasa a minúsculas: "Lápiz" → "lapiz".
 * SQLite no compara sin acentos por sí solo, así que se registra como
 * función SQL (sin_acentos) para las búsquedas y el orden alfabético.
 */
function sin_acentos(?string $texto): ?string
{
    if ($texto === null) {
        return null;
    }
    return strtr(mb_strtolower($texto, 'UTF-8'), [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ñ' => 'n',
    ]);
}

/**
 * Devuelve la conexión PDO (se crea una sola vez por petición).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $archivo = archivo_base_datos();
    $nueva   = !is_file($archivo) || filesize($archivo) === 0;
    $opciones = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,   // espera hasta 10 s si otra petición está escribiendo
    ];

    try {
        // PHP 8.4+ devuelve Pdo\Sqlite con PDO::connect(); antes, un PDO normal.
        $pdo = method_exists(PDO::class, 'connect')
            ? PDO::connect('sqlite:' . $archivo, null, null, $opciones)
            : new PDO('sqlite:' . $archivo, null, null, $opciones);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 10000');
        if (method_exists($pdo, 'createFunction')) {
            $pdo->createFunction('sin_acentos', 'sin_acentos', 1, PDO::SQLITE_DETERMINISTIC);
        } else {
            $pdo->sqliteCreateFunction('sin_acentos', 'sin_acentos', 1, PDO::SQLITE_DETERMINISTIC);
        }

        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version < DB_ESQUEMA_VERSION) {
            migrar_base($pdo, $version, $nueva);
        }
    } catch (PDOException $e) {
        $pdo = null;
        error_log('TiendaWeb: error al abrir la base de datos ' . $archivo . ': ' . $e->getMessage());
        throw new RuntimeException(
            'No se pudo abrir la base de datos (' . $archivo . '). Verifica que la carpeta exista '
            . 'y que tengas permiso para escribir en ella.'
        );
    }

    return $pdo;
}

/**
 * Crea la base o la actualiza a DB_ESQUEMA_VERSION sin perder datos.
 * database.sql solo usa "IF NOT EXISTS": aplicarlo de nuevo agrega lo que falte.
 */
function migrar_base(PDO $pdo, int $version, bool $nueva): void
{
    $esquema = (string) file_get_contents(__DIR__ . '/database.sql');
    $columnas = fn(string $tabla) => array_column($pdo->query("PRAGMA table_info($tabla)")->fetchAll(), 'name');

    // Las llaves foráneas se apagan para reconstruir tablas (procedimiento recomendado por SQLite).
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $existe = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'ventas'")->fetchColumn();

        // ventas: si su definición es de una versión anterior (le falta el pago "mixto" de v4), se vuelve
        // a crear y se copian los datos. SQLite no puede cambiar un CHECK de una tabla existente.
        $sqlVentas = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'ventas'")->fetchColumn();
        if ($existe && !str_contains($sqlVentas, "'mixto'")) {
            if (!preg_match('/CREATE TABLE IF NOT EXISTS ventas \((.*?)\n\);/s', $esquema, $m)) {
                throw new RuntimeException('database.sql no tiene la tabla ventas.');
            }
            $anteriores = $columnas('ventas');
            $pdo->exec("CREATE TABLE ventas_nueva ({$m[1]}\n)");
            $comunes = implode(', ', array_intersect($columnas('ventas_nueva'), $anteriores));
            $pdo->exec("INSERT INTO ventas_nueva ($comunes) SELECT $comunes FROM ventas");
            if (!in_array('subtotal', $anteriores, true)) {
                $pdo->exec('UPDATE ventas_nueva SET subtotal = total');   // antes de v3 no había descuentos
            }
            $pdo->exec('DROP TABLE ventas');
            $pdo->exec('ALTER TABLE ventas_nueva RENAME TO ventas');
        }

        // Columnas agregadas en versiones posteriores (ALTER TABLE ADD COLUMN conserva los datos).
        $nuevas = [
            ['detalle_ventas', 'descuento', 'REAL NOT NULL DEFAULT 0'],                                   // v3
            ['detalle_ventas', 'tasa_iva',  "TEXT NULL DEFAULT NULL"],                                    // v4
            ['detalle_ventas', 'iva',       'REAL NOT NULL DEFAULT 0'],                                   // v4
            ['productos',      'tasa_iva',  "TEXT NOT NULL DEFAULT '16' CHECK (tasa_iva IN ('16','8','0','exento'))"], // v4
            ['productos',      'codigo_interno', 'TEXT NULL DEFAULT NULL'],                               // v5
            ['productos',      'descripcion',    'TEXT NULL DEFAULT NULL'],                               // v5
            ['productos',      'categoria_id',   'INTEGER NULL DEFAULT NULL REFERENCES categorias (id)'], // v5
            ['productos',      'marca',          'TEXT NULL DEFAULT NULL'],                               // v5
            ['productos',      'unidad',         "TEXT NOT NULL DEFAULT 'pieza'"],                        // v5
            ['productos',      'proveedor_id',   'INTEGER NULL DEFAULT NULL REFERENCES proveedores (id)'],// v5
            ['productos',      'tipo',             "TEXT NOT NULL DEFAULT 'normal' CHECK (tipo IN ('normal','paquete','kit'))"], // v6
            ['productos',      'producto_base_id', 'INTEGER NULL DEFAULT NULL REFERENCES productos (id)'],  // v6
            ['productos',      'factor_base',      'REAL NULL DEFAULT NULL CHECK (factor_base IS NULL OR factor_base > 0)'], // v6
            ['clientes',       'rfc',            'TEXT NULL DEFAULT NULL'],                               // v8
            ['clientes',       'razon_social',   'TEXT NULL DEFAULT NULL'],                               // v8
            ['clientes',       'cp_fiscal',      'TEXT NULL DEFAULT NULL'],                               // v8
            ['clientes',       'regimen_fiscal', 'TEXT NULL DEFAULT NULL'],                               // v8
            ['clientes',       'uso_cfdi',       'TEXT NULL DEFAULT NULL'],                               // v8
            ['clientes',       'correo',         'TEXT NULL DEFAULT NULL'],                               // v8
            ['facturas',       'motivo_cancelacion', 'TEXT NULL DEFAULT NULL'], // v9
            ['facturas',       'uuid_sustituto',  'TEXT NULL DEFAULT NULL'], // v9
            ['facturas',       'cancelada_en',    'TEXT NULL DEFAULT NULL'], // v9
            ['facturas',       'cancelada_sat',   'INTEGER NOT NULL DEFAULT 0'], // v9
            ['facturas',       'sustituye_id',    'INTEGER NULL DEFAULT NULL REFERENCES facturas (id)'], // v9
            ['facturas',       'enviada_en',      'TEXT NULL DEFAULT NULL'], // v9
            ['facturas',       'enviada_a',       'TEXT NULL DEFAULT NULL'], // v9
        ];
        foreach ($nuevas as [$tabla, $columna, $definicion]) {
            $tablaExiste = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = '$tabla'")->fetchColumn();
            if ($tablaExiste && !in_array($columna, $columnas($tabla), true)) {
                $pdo->exec("ALTER TABLE $tabla ADD COLUMN $columna $definicion");
            }
        }

        // Una base nueva empieza vacía: el asistente de primer uso (bienvenida.php) ofrece los ejemplos.
        $pdo->exec($esquema);
        // El historial de inventario empieza con la existencia que hay hoy.
        if ($version < 3) {
            $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, fecha, tipo, cantidad, existencia_final, costo, nota)
                           SELECT id, :fecha, 'inicial', existencia, existencia, costo, :nota
                           FROM productos
                           WHERE controlar_stock = 1
                             AND id NOT IN (SELECT producto_id FROM movimientos_inventario)")
                ->execute([':fecha' => date('Y-m-d H:i:s'), ':nota' => $nueva ? 'Existencia inicial' : 'Existencia al actualizar el sistema']);
        }
        // v4: cada venta anterior tiene un solo pago, por el total, con su método.
        if ($version < 4) {
            $pdo->exec("INSERT INTO pagos (venta_id, metodo, monto)
                        SELECT id, metodo_pago, total FROM ventas WHERE id NOT IN (SELECT venta_id FROM pagos)");
        }

        if ($pdo->query('PRAGMA foreign_key_check')->fetch()) {
            throw new RuntimeException('La actualización de la base dejó referencias rotas.');
        }
        $pdo->exec('PRAGMA user_version = ' . DB_ESQUEMA_VERSION);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

/* ---------------------------------------------------------------------
 *  Transacciones
 *
 *  Se usa BEGIN IMMEDIATE: reserva la escritura desde el inicio, así
 *  dos cobros simultáneos (doble clic) se forman en fila en lugar de
 *  chocar. Sustituye al "SELECT ... FOR UPDATE" de MySQL.
 * --------------------------------------------------------------------- */

$GLOBALS['__tw_transaccion'] = false;

function transaccion_iniciar(): void
{
    db()->exec('BEGIN IMMEDIATE');
    $GLOBALS['__tw_transaccion'] = true;
}

function transaccion_confirmar(): void
{
    db()->exec('COMMIT');
    $GLOBALS['__tw_transaccion'] = false;
}

function transaccion_revertir(): void
{
    if ($GLOBALS['__tw_transaccion']) {
        $GLOBALS['__tw_transaccion'] = false;
        db()->exec('ROLLBACK');
    }
}

function en_transaccion(): bool
{
    return $GLOBALS['__tw_transaccion'];
}
