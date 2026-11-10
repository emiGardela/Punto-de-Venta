<?php
/**
 * TiendaWeb - Respaldos de la base de datos.
 *
 * - Cada día, la primera vez que se abre el sistema, se guarda una copia en
 *   la carpeta "respaldos" junto a la base de datos (se conservan 30).
 * - Desde la página Respaldos se puede descargar una copia (por ejemplo a
 *   una USB) y restaurar una copia anterior.
 *
 * Las copias se hacen con VACUUM INTO: generan un archivo .sqlite completo y
 * consistente aunque el sistema esté en uso.
 */

declare(strict_types=1);

const RESPALDOS_A_CONSERVAR = 30;

function carpeta_respaldos(): string
{
    $carpeta = carpeta_datos() . DIRECTORY_SEPARATOR . 'respaldos';
    if (!is_dir($carpeta) && !@mkdir($carpeta, 0775, true) && !is_dir($carpeta)) {
        throw new RuntimeException('No se pudo crear la carpeta de respaldos: ' . $carpeta);
    }
    return $carpeta;
}

/** Copia la base de datos completa a $destino (el archivo no debe existir). */
function crear_respaldo(string $destino): void
{
    db()->exec('VACUUM INTO ' . db()->quote($destino));
}

/** Respaldo diario automático. Nunca interrumpe al usuario si falla. */
function respaldo_automatico(): void
{
    try {
        $carpeta = carpeta_respaldos();
        $archivo = $carpeta . DIRECTORY_SEPARATOR . 'tiendaweb-' . date('Y-m-d') . '.sqlite';
        if (is_file($archivo)) {
            return;
        }
        crear_respaldo($archivo);

        $diarios = glob($carpeta . DIRECTORY_SEPARATOR . 'tiendaweb-????-??-??.sqlite') ?: [];
        rsort($diarios);   // el nombre lleva la fecha: más reciente primero
        foreach (array_slice($diarios, RESPALDOS_A_CONSERVAR) as $viejo) {
            @unlink($viejo);
        }
    } catch (Throwable $e) {
        error_log('TiendaWeb: no se pudo crear el respaldo automático: ' . $e->getMessage());
    }
}

/** Respaldos guardados, del más reciente al más antiguo. */
function listar_respaldos(): array
{
    $lista = [];
    foreach (glob(carpeta_respaldos() . DIRECTORY_SEPARATOR . '*.sqlite') ?: [] as $ruta) {
        $lista[] = ['nombre' => basename($ruta), 'ruta' => $ruta, 'tamano' => filesize($ruta), 'fecha' => filemtime($ruta)];
    }
    usort($lista, fn($a, $b) => $b['fecha'] <=> $a['fecha']);
    return $lista;
}

/** Ruta de un respaldo guardado a partir de su nombre, o null si no existe. */
function ruta_respaldo(string $nombre): ?string
{
    if (!preg_match('/^[A-Za-z0-9_.\-]+\.sqlite$/', $nombre)) {
        return null;
    }
    $ruta = carpeta_respaldos() . DIRECTORY_SEPARATOR . $nombre;
    return is_file($ruta) ? $ruta : null;
}

function tamano_fmt(int $bytes): string
{
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return number_format($bytes / 1024 / 1024, 1) . ' MB';
}

/**
 * Tablas de datos en el orden en que se insertan (primero las que otras referencian).
 * "ajustes" no está: la impresora y los datos del negocio son de cada computadora.
 */
const TABLAS_RESPALDO = ['usuarios', 'categorias', 'proveedores', 'productos', 'precios_volumen', 'kit_componentes', 'listas', 'lista_productos', 'clientes', 'turnos', 'ventas', 'detalle_ventas', 'pagos', 'devoluciones', 'devolucion_productos', 'facturas', 'factura_archivos', 'compras', 'compra_productos',
                         'abonos', 'movimientos_caja', 'movimientos_inventario', 'auditoria'];

/** Columnas mínimas para aceptar un archivo como respaldo de TiendaWeb (cualquier versión). */
const RESPALDO_MINIMO = [
    'productos'      => ['id', 'nombre', 'precio', 'existencia'],
    'ventas'         => ['id', 'fecha', 'total', 'metodo_pago', 'estado'],
    'detalle_ventas' => ['id', 'venta_id', 'nombre_producto', 'cantidad', 'subtotal'],
];

/**
 * Reemplaza todos los datos actuales por los de un archivo de respaldo.
 * Sirve también con respaldos de versiones anteriores: se copian las columnas
 * que tengan en común y lo que falte toma su valor predeterminado.
 * Antes guarda una copia de lo actual ("antes-de-restaurar-..."). Devuelve su nombre.
 */
function restaurar_respaldo(string $archivo): string
{
    // 1. Revisa que el archivo sea una base de TiendaWeb sana.
    $columnasRespaldo = [];
    try {
        $prueba = new PDO('sqlite:' . $archivo, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ($prueba->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('dañado');
        }
        foreach (TABLAS_RESPALDO as $tabla) {
            $columnasRespaldo[$tabla] = array_column($prueba->query("PRAGMA table_info($tabla)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        }
        foreach (RESPALDO_MINIMO as $tabla => $columnas) {
            if (array_diff($columnas, $columnasRespaldo[$tabla])) {
                throw new RuntimeException('incompleto');
            }
        }
        $prueba = null;
    } catch (Throwable $e) {
        throw new RuntimeException('El archivo elegido no es un respaldo válido de este sistema o está dañado.');
    }

    // 2. Copia de seguridad de lo que hay ahora, por si hay que deshacer.
    $n = 1;
    do {
        $copia = 'antes-de-restaurar-' . date('Y-m-d-His') . ($n > 1 ? '-' . $n : '') . '.sqlite';
        $n++;
    } while (is_file(carpeta_respaldos() . DIRECTORY_SEPARATOR . $copia));
    crear_respaldo(carpeta_respaldos() . DIRECTORY_SEPARATOR . $copia);

    // 3. Reemplaza los datos dentro de una transacción: o se copia todo o nada.
    //    Si el respaldo es de antes de que existieran usuarios, se conservan los usuarios actuales.
    $pdo = db();
    $conUsuarios = $columnasRespaldo['usuarios'] !== [];
    $pdo->exec('ATTACH DATABASE ' . $pdo->quote($archivo) . ' AS respaldo');
    try {
        transaccion_iniciar();
        $pdo->exec('PRAGMA defer_foreign_keys = ON');   // un paquete puede apuntar a un producto con id mayor: se revisa al final
        foreach (array_reverse(TABLAS_RESPALDO) as $tabla) {
            if ($tabla !== 'usuarios' || $conUsuarios) {
                $pdo->exec("DELETE FROM main.$tabla");
                $pdo->exec('DELETE FROM main.sqlite_sequence WHERE name = ' . $pdo->quote($tabla));
            }
        }
        foreach (TABLAS_RESPALDO as $tabla) {
            if (!$columnasRespaldo[$tabla]) {
                continue;
            }
            $actuales = array_column($pdo->query("PRAGMA main.table_info($tabla)")->fetchAll(PDO::FETCH_ASSOC), 'name');
            $lista = implode(', ', array_intersect($actuales, $columnasRespaldo[$tabla]));
            $pdo->exec("INSERT INTO main.$tabla ($lista) SELECT $lista FROM respaldo.$tabla ORDER BY id");
        }
        // Respaldos de versiones anteriores: completa lo que no traían.
        if (!in_array('subtotal', $columnasRespaldo['ventas'], true)) {
            $pdo->exec('UPDATE main.ventas SET subtotal = total');
        }
        if (!$columnasRespaldo['pagos']) {
            $pdo->exec('INSERT INTO main.pagos (venta_id, metodo, monto) SELECT id, metodo_pago, total FROM main.ventas');
        }
        if (!$columnasRespaldo['movimientos_inventario']) {
            $pdo->prepare("INSERT INTO main.movimientos_inventario (producto_id, fecha, tipo, cantidad, existencia_final, costo, nota)
                           SELECT id, :fecha, 'inicial', existencia, existencia, costo, 'Existencia al restaurar un respaldo'
                           FROM main.productos WHERE controlar_stock = 1")->execute([':fecha' => date('Y-m-d H:i:s')]);
        }
        if ($pdo->query('PRAGMA main.foreign_key_check')->fetch()) {
            throw new RuntimeException('El respaldo tiene datos incompletos (referencias rotas). No se restauró nada.');
        }
        transaccion_confirmar();
    } catch (Throwable $e) {
        transaccion_revertir();
        throw $e;
    } finally {
        $pdo->exec('DETACH DATABASE respaldo');
    }
    return $copia;
}
