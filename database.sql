-- SPDX-License-Identifier: GPL-3.0-only
-- =====================================================================
--  TiendaWeb - Punto de venta universal
--  Esquema de la base de datos (SQLite 3.27+)
--
--  No hace falta ejecutarlo a mano: conexion.php lo aplica solo la
--  primera vez que se abre el sistema y crea el archivo de la base.
--  Se puede ejecutar varias veces: las tablas solo se crean si no existen.
--
--  Los importes se guardan como REAL, pero el sistema siempre calcula en
--  centavos/milésimas (enteros) en PHP y redondea al guardar.
-- =====================================================================

-- ---------------------------------------------------------------------
--  Productos y servicios
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS productos (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre           TEXT    NOT NULL CHECK (length(nombre) <= 150),
  codigo_barras    TEXT    NULL DEFAULT NULL,                -- opcional; único cuando tiene valor
  precio           REAL    NOT NULL DEFAULT 0 CHECK (precio >= 0),            -- precio de venta
  costo            REAL    NULL DEFAULT NULL CHECK (costo IS NULL OR costo >= 0), -- costo (opcional)
  existencia       REAL    NOT NULL DEFAULT 0 CHECK (existencia >= 0),        -- admite fracciones
  stock_minimo     REAL    NOT NULL DEFAULT 5 CHECK (stock_minimo >= 0),      -- aviso de existencias bajas
  controlar_stock  INTEGER NOT NULL DEFAULT 1,               -- 0 = servicio, no descuenta inventario
  permite_fraccion INTEGER NOT NULL DEFAULT 0,               -- 1 = se puede vender 0.5, 1.250, etc.
  tasa_iva         TEXT    NOT NULL DEFAULT '16' CHECK (tasa_iva IN ('16','8','0','exento')),  -- el precio ya lo incluye
  activo           INTEGER NOT NULL DEFAULT 1,               -- 0 = no se puede vender
  creado_en        TEXT    NOT NULL DEFAULT (datetime('now', 'localtime')),
  actualizado_en   TEXT    NULL DEFAULT NULL,
  codigo_interno   TEXT    NULL DEFAULT NULL,                -- clave propia de la tienda (opcional, única)
  descripcion      TEXT    NULL DEFAULT NULL,
  categoria_id     INTEGER NULL DEFAULT NULL REFERENCES categorias (id),
  marca            TEXT    NULL DEFAULT NULL,
  unidad           TEXT    NOT NULL DEFAULT 'pieza',         -- pieza, paquete, caja, kg, m… (ver UNIDADES)
  proveedor_id     INTEGER NULL DEFAULT NULL REFERENCES proveedores (id),  -- proveedor preferido
  -- normal: tiene su propia existencia (o es servicio)
  -- paquete: es N unidades de otro producto (caja de 12 lápices); al venderse descuenta N del producto base
  -- kit: varios productos juntos (kit escolar); al venderse descuenta cada componente
  tipo             TEXT    NOT NULL DEFAULT 'normal' CHECK (tipo IN ('normal','paquete','kit')),
  producto_base_id INTEGER NULL DEFAULT NULL REFERENCES productos (id),    -- solo en paquetes
  factor_base      REAL    NULL DEFAULT NULL CHECK (factor_base IS NULL OR factor_base > 0)  -- unidades del base por paquete
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_productos_codigo ON productos (codigo_barras);  -- varios NULL permitidos
CREATE UNIQUE INDEX IF NOT EXISTS uk_productos_codigo_interno ON productos (codigo_interno);
CREATE INDEX IF NOT EXISTS idx_productos_categoria ON productos (categoria_id);

-- ---------------------------------------------------------------------
--  Categorías (útiles escolares, oficina, regalos, servicios…)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categorias (
  id      INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre  TEXT    NOT NULL COLLATE NOCASE UNIQUE,
  activo  INTEGER NOT NULL DEFAULT 1
);

-- ---------------------------------------------------------------------
--  Proveedores (a quién se le compra la mercancía)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS proveedores (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre     TEXT    NOT NULL,
  contacto   TEXT    NULL DEFAULT NULL,
  telefono   TEXT    NULL DEFAULT NULL,
  correo     TEXT    NULL DEFAULT NULL,
  rfc        TEXT    NULL DEFAULT NULL,
  notas      TEXT    NULL DEFAULT NULL,
  activo     INTEGER NOT NULL DEFAULT 1,
  creado_en  TEXT    NOT NULL DEFAULT (datetime('now', 'localtime'))
);

-- ---------------------------------------------------------------------
--  Precios por cantidad (mayoreo / volumen): desde N unidades, precio c/u.
--  Ej.: lápiz $5; desde 12, $4.50; desde 100, $4.00.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS precios_volumen (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  producto_id  INTEGER NOT NULL REFERENCES productos (id) ON DELETE CASCADE,
  desde        REAL    NOT NULL CHECK (desde > 1),
  precio       REAL    NOT NULL CHECK (precio >= 0),
  UNIQUE (producto_id, desde)
);
CREATE INDEX IF NOT EXISTS idx_productos_nombre ON productos (nombre);
CREATE INDEX IF NOT EXISTS idx_productos_activo ON productos (activo);

CREATE TRIGGER IF NOT EXISTS trg_productos_actualizado
AFTER UPDATE ON productos
FOR EACH ROW WHEN NEW.actualizado_en IS OLD.actualizado_en
BEGIN
  UPDATE productos SET actualizado_en = datetime('now', 'localtime') WHERE id = NEW.id;
END;

-- ---------------------------------------------------------------------
--  Ventas (encabezado). Las ventas nunca se borran: se cancelan.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ventas (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,      -- folio
  fecha              TEXT    NOT NULL,                       -- hora de México (la asigna PHP)
  subtotal           REAL    NOT NULL DEFAULT 0 CHECK (subtotal >= 0),   -- antes del descuento
  descuento          REAL    NOT NULL DEFAULT 0 CHECK (descuento >= 0),
  total              REAL    NOT NULL CHECK (total >= 0),    -- subtotal - descuento (IVA incluido)
  iva                REAL    NOT NULL DEFAULT 0,             -- IVA contenido en el total
  metodo_pago        TEXT    NOT NULL CHECK (metodo_pago IN ('efectivo','transferencia','tarjeta','credito','mixto')), -- mixto = ver pagos
  recibido           REAL    NULL DEFAULT NULL,              -- solo en efectivo
  cambio             REAL    NOT NULL DEFAULT 0 CHECK (cambio >= 0),
  estado             TEXT    NOT NULL DEFAULT 'completada' CHECK (estado IN ('completada','cancelada')),
  token_operacion    TEXT    NULL DEFAULT NULL,              -- evita ventas duplicadas
  motivo_cancelacion TEXT    NULL DEFAULT NULL,
  cancelada_en       TEXT    NULL DEFAULT NULL,
  usuario_id         INTEGER NULL DEFAULT NULL REFERENCES usuarios (id),   -- quién cobró
  turno_id           INTEGER NULL DEFAULT NULL REFERENCES turnos (id),     -- turno de caja
  cliente_id         INTEGER NULL DEFAULT NULL REFERENCES clientes (id),   -- obligatorio en crédito
  cancelada_por      INTEGER NULL DEFAULT NULL REFERENCES usuarios (id)
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_ventas_token ON ventas (token_operacion);
CREATE INDEX IF NOT EXISTS idx_ventas_fecha ON ventas (fecha);
CREATE INDEX IF NOT EXISTS idx_ventas_estado_fecha ON ventas (estado, fecha);
CREATE INDEX IF NOT EXISTS idx_ventas_turno ON ventas (turno_id);
CREATE INDEX IF NOT EXISTS idx_ventas_cliente ON ventas (cliente_id);

-- ---------------------------------------------------------------------
--  Detalle de cada venta. Guarda nombre, precio y costo del momento.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS detalle_ventas (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  venta_id         INTEGER NOT NULL REFERENCES ventas (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  producto_id      INTEGER NULL DEFAULT NULL REFERENCES productos (id) ON UPDATE CASCADE ON DELETE SET NULL,
  nombre_producto  TEXT    NOT NULL,
  cantidad         REAL    NOT NULL CHECK (cantidad > 0),
  precio_unitario  REAL    NOT NULL CHECK (precio_unitario >= 0),
  costo_unitario   REAL    NULL DEFAULT NULL,                -- para la utilidad estimada
  subtotal         REAL    NOT NULL CHECK (subtotal >= 0),   -- cantidad × precio
  descuenta_stock  INTEGER NOT NULL DEFAULT 0,               -- 1 = se descontó inventario
  descuento        REAL    NOT NULL DEFAULT 0,               -- parte del descuento de la venta que le toca
  tasa_iva         TEXT    NULL DEFAULT NULL,                -- '16', '8', '0', 'exento' (NULL en ventas antiguas)
  iva              REAL    NOT NULL DEFAULT 0                -- IVA contenido en (subtotal - descuento)
);

-- ---------------------------------------------------------------------
--  Pagos de cada venta. Una venta puede pagarse con varios métodos
--  (pago dividido). monto = lo que se aplica a la venta; el cambio del
--  efectivo está en ventas.cambio.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pagos (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  venta_id  INTEGER NOT NULL REFERENCES ventas (id),
  metodo    TEXT    NOT NULL CHECK (metodo IN ('efectivo','transferencia','tarjeta','credito')),
  monto     REAL    NOT NULL CHECK (monto > 0)
);
CREATE INDEX IF NOT EXISTS idx_pagos_venta ON pagos (venta_id);
CREATE INDEX IF NOT EXISTS idx_detalle_venta ON detalle_ventas (venta_id);
CREATE INDEX IF NOT EXISTS idx_detalle_producto ON detalle_ventas (producto_id);

-- ---------------------------------------------------------------------
--  Ajustes del sistema (datos del negocio, impresora de tickets, cajón).
--  Se cambian desde el menú Configuración. No se reemplazan al restaurar
--  un respaldo: la impresora es propia de cada computadora.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ajustes (
  clave TEXT PRIMARY KEY,
  valor TEXT NOT NULL
);

-- ---------------------------------------------------------------------
--  Usuarios (dueño y cajeros). Si no hay ninguno, el sistema no pide
--  clave. El PIN se guarda cifrado (password_hash).
--  permisos: lista separada por comas (ver PERMISOS en includes/usuarios.php)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre     TEXT    NOT NULL COLLATE NOCASE UNIQUE,
  pin_hash   TEXT    NOT NULL,
  rol        TEXT    NOT NULL DEFAULT 'cajero' CHECK (rol IN ('dueno','cajero')),
  permisos   TEXT    NOT NULL DEFAULT '',
  activo     INTEGER NOT NULL DEFAULT 1,
  creado_en  TEXT    NOT NULL DEFAULT (datetime('now', 'localtime'))
);

-- ---------------------------------------------------------------------
--  Turnos de caja: se abre con un fondo y se cierra con el corte.
--  Solo puede haber un turno abierto a la vez.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS turnos (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  abierto_en        TEXT    NOT NULL,
  abierto_por       INTEGER NULL REFERENCES usuarios (id),
  fondo             REAL    NOT NULL DEFAULT 0 CHECK (fondo >= 0),
  cerrado_en        TEXT    NULL DEFAULT NULL,
  cerrado_por       INTEGER NULL REFERENCES usuarios (id),
  efectivo_esperado REAL    NULL DEFAULT NULL,   -- calculado al cerrar
  efectivo_contado  REAL    NULL DEFAULT NULL,   -- lo que había en el cajón
  notas             TEXT    NULL DEFAULT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_turno_abierto ON turnos ((1)) WHERE cerrado_en IS NULL;

-- Entradas y salidas de efectivo que no son ventas (retiros, pagos a proveedores, cambio…).
CREATE TABLE IF NOT EXISTS movimientos_caja (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  turno_id    INTEGER NOT NULL REFERENCES turnos (id),
  fecha       TEXT    NOT NULL,
  tipo        TEXT    NOT NULL CHECK (tipo IN ('entrada','salida')),
  monto       REAL    NOT NULL CHECK (monto > 0),
  concepto    TEXT    NOT NULL,
  usuario_id  INTEGER NULL REFERENCES usuarios (id),
  venta_id    INTEGER NULL REFERENCES ventas (id)    -- devolución de una venta de otro turno
);
CREATE INDEX IF NOT EXISTS idx_movcaja_turno ON movimientos_caja (turno_id);

-- ---------------------------------------------------------------------
--  Clientes y crédito ("fiado"). Saldo = ventas a crédito - abonos.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clientes (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre          TEXT    NOT NULL,
  telefono        TEXT    NULL DEFAULT NULL,
  notas           TEXT    NULL DEFAULT NULL,
  limite_credito  REAL    NULL DEFAULT NULL CHECK (limite_credito IS NULL OR limite_credito >= 0), -- NULL = sin límite
  -- Datos fiscales (de su Constancia de Situación Fiscal), para pedir factura.
  rfc             TEXT    NULL DEFAULT NULL,
  razon_social    TEXT    NULL DEFAULT NULL,
  cp_fiscal       TEXT    NULL DEFAULT NULL,
  regimen_fiscal  TEXT    NULL DEFAULT NULL,   -- clave del SAT, ej. 612
  uso_cfdi        TEXT    NULL DEFAULT NULL,   -- clave del SAT, ej. G03
  correo          TEXT    NULL DEFAULT NULL,
  activo          INTEGER NOT NULL DEFAULT 1,
  creado_en       TEXT    NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX IF NOT EXISTS idx_clientes_nombre ON clientes (nombre);

CREATE TABLE IF NOT EXISTS abonos (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,
  cliente_id         INTEGER NOT NULL REFERENCES clientes (id),
  fecha              TEXT    NOT NULL,
  monto              REAL    NOT NULL CHECK (monto > 0),
  metodo_pago        TEXT    NOT NULL CHECK (metodo_pago IN ('efectivo','transferencia','tarjeta')),
  nota               TEXT    NULL DEFAULT NULL,
  estado             TEXT    NOT NULL DEFAULT 'aplicado' CHECK (estado IN ('aplicado','cancelado')),
  turno_id           INTEGER NULL REFERENCES turnos (id),
  usuario_id         INTEGER NULL REFERENCES usuarios (id),
  cancelado_en       TEXT    NULL DEFAULT NULL,
  motivo_cancelacion TEXT    NULL DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_abonos_cliente ON abonos (cliente_id);
CREATE INDEX IF NOT EXISTS idx_abonos_turno ON abonos (turno_id);

-- ---------------------------------------------------------------------
--  Historial de inventario (kardex): cada cambio de existencia.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS movimientos_inventario (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  producto_id      INTEGER NOT NULL REFERENCES productos (id),
  fecha            TEXT    NOT NULL,
  tipo             TEXT    NOT NULL CHECK (tipo IN ('inicial','entrada','ajuste','venta','cancelacion','importacion')),
  cantidad         REAL    NOT NULL,              -- positiva entra, negativa sale
  existencia_final REAL    NOT NULL,
  costo            REAL    NULL DEFAULT NULL,     -- costo unitario en entradas
  nota             TEXT    NULL DEFAULT NULL,
  usuario_id       INTEGER NULL REFERENCES usuarios (id),
  venta_id         INTEGER NULL REFERENCES ventas (id)
);
CREATE INDEX IF NOT EXISTS idx_movinv_producto ON movimientos_inventario (producto_id, fecha);
CREATE INDEX IF NOT EXISTS idx_movinv_fecha ON movimientos_inventario (fecha);

-- ---------------------------------------------------------------------
--  Bitácora de auditoría: quién hizo qué y cuándo (inicios de sesión,
--  cancelaciones, descuentos, cajón abierto sin venta, cambios de precio,
--  cortes, configuración…). Solo se agregan registros; nunca se editan.
--  Guarda el nombre del usuario para que se lea aunque luego lo borren.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS auditoria (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  fecha       TEXT    NOT NULL,
  usuario_id  INTEGER NULL DEFAULT NULL,
  usuario     TEXT    NULL DEFAULT NULL,
  accion      TEXT    NOT NULL,          -- clave corta: venta_cancelada, cajon_abierto, precio_cambiado…
  detalle     TEXT    NOT NULL,          -- texto legible
  entidad     TEXT    NULL DEFAULT NULL, -- venta, producto, turno, cliente, usuario…
  entidad_id  INTEGER NULL DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_auditoria_fecha ON auditoria (fecha);
CREATE INDEX IF NOT EXISTS idx_auditoria_accion ON auditoria (accion);

CREATE TRIGGER IF NOT EXISTS trg_auditoria_sin_cambios
BEFORE UPDATE ON auditoria
BEGIN
  SELECT RAISE(ABORT, 'La bitácora no se puede modificar');
END;

-- ---------------------------------------------------------------------
--  Componentes de los kits (kit escolar = cuaderno + lápiz + goma…).
--  La existencia del kit es la de sus componentes.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kit_componentes (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  kit_id       INTEGER NOT NULL REFERENCES productos (id) ON DELETE CASCADE,
  producto_id  INTEGER NOT NULL REFERENCES productos (id),
  cantidad     REAL    NOT NULL CHECK (cantidad > 0),
  UNIQUE (kit_id, producto_id)
);

-- ---------------------------------------------------------------------
--  Listas escolares: la lista de útiles de una escuela y grado. En el
--  punto de venta se carga completa y se quita lo que el cliente ya tiene.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS listas (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre     TEXT    NOT NULL,             -- "3° primaria"
  escuela    TEXT    NULL DEFAULT NULL,    -- "Esc. Benito Juárez"
  notas      TEXT    NULL DEFAULT NULL,
  activo     INTEGER NOT NULL DEFAULT 1,
  creado_en  TEXT    NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE TABLE IF NOT EXISTS lista_productos (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  lista_id     INTEGER NOT NULL REFERENCES listas (id) ON DELETE CASCADE,
  producto_id  INTEGER NOT NULL REFERENCES productos (id),
  cantidad     REAL    NOT NULL CHECK (cantidad > 0),
  UNIQUE (lista_id, producto_id)
);

-- ---------------------------------------------------------------------
--  Compras a proveedores: lo que llega, a qué costo y si se pagó con
--  dinero de la caja. Suman al inventario; cancelar una compra la resta.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS compras (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  proveedor_id     INTEGER NULL DEFAULT NULL REFERENCES proveedores (id),
  fecha            TEXT    NOT NULL,
  folio_proveedor  TEXT    NULL DEFAULT NULL,   -- número de factura o nota del proveedor
  total            REAL    NOT NULL DEFAULT 0 CHECK (total >= 0),
  pagada_de_caja   INTEGER NOT NULL DEFAULT 0,  -- 1 = el dinero salió del cajón (se registra la salida)
  turno_id         INTEGER NULL DEFAULT NULL REFERENCES turnos (id),
  estado           TEXT    NOT NULL DEFAULT 'recibida' CHECK (estado IN ('recibida','cancelada')),
  notas            TEXT    NULL DEFAULT NULL,
  usuario_id       INTEGER NULL DEFAULT NULL REFERENCES usuarios (id),
  cancelada_en     TEXT    NULL DEFAULT NULL,
  motivo_cancelacion TEXT  NULL DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_compras_fecha ON compras (fecha);
CREATE INDEX IF NOT EXISTS idx_compras_proveedor ON compras (proveedor_id);

CREATE TABLE IF NOT EXISTS compra_productos (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  compra_id       INTEGER NOT NULL REFERENCES compras (id),
  producto_id     INTEGER NOT NULL REFERENCES productos (id),
  cantidad        REAL    NOT NULL CHECK (cantidad > 0),
  costo_unitario  REAL    NOT NULL CHECK (costo_unitario >= 0),
  importe         REAL    NOT NULL CHECK (importe >= 0)
);
CREATE INDEX IF NOT EXISTS idx_compra_productos_compra ON compra_productos (compra_id);

-- ---------------------------------------------------------------------
--  Devoluciones parciales de una venta (una o varias piezas).
--  reembolso: cómo se le regresó el dinero al cliente; "credito" = se
--  descontó de lo que debe.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS devoluciones (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  venta_id    INTEGER NOT NULL REFERENCES ventas (id),
  fecha       TEXT    NOT NULL,
  total       REAL    NOT NULL CHECK (total >= 0),
  reembolso   TEXT    NOT NULL CHECK (reembolso IN ('efectivo','transferencia','tarjeta','credito')),
  motivo      TEXT    NOT NULL,
  turno_id    INTEGER NULL DEFAULT NULL REFERENCES turnos (id),
  usuario_id  INTEGER NULL DEFAULT NULL REFERENCES usuarios (id)
);
CREATE INDEX IF NOT EXISTS idx_devoluciones_venta ON devoluciones (venta_id);
CREATE INDEX IF NOT EXISTS idx_devoluciones_fecha ON devoluciones (fecha);

CREATE TABLE IF NOT EXISTS devolucion_productos (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,
  devolucion_id      INTEGER NOT NULL REFERENCES devoluciones (id),
  detalle_id         INTEGER NOT NULL REFERENCES detalle_ventas (id),
  cantidad           REAL    NOT NULL CHECK (cantidad > 0),
  importe            REAL    NOT NULL CHECK (importe >= 0),   -- lo que se reembolsa por este renglón
  costo_unitario     REAL    NULL DEFAULT NULL,
  regresa_inventario INTEGER NOT NULL DEFAULT 1                -- 0 = dañado, no vuelve al anaquel
);
CREATE INDEX IF NOT EXISTS idx_devprod_devolucion ON devolucion_productos (devolucion_id);
CREATE INDEX IF NOT EXISTS idx_devprod_detalle ON devolucion_productos (detalle_id);

-- ---------------------------------------------------------------------
--  Facturas pedidas por los clientes (v8). El sistema guarda los datos
--  fiscales y la lista para el contador o el portal del SAT; el timbrado
--  se hace fuera. Una venta tiene a lo más una factura vigente.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS facturas (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  venta_id        INTEGER NOT NULL REFERENCES ventas (id),
  cliente_id      INTEGER NULL DEFAULT NULL REFERENCES clientes (id),
  rfc             TEXT    NOT NULL,
  razon_social    TEXT    NOT NULL,
  cp_fiscal       TEXT    NOT NULL,
  regimen_fiscal  TEXT    NOT NULL,
  uso_cfdi        TEXT    NOT NULL,
  correo          TEXT    NULL DEFAULT NULL,
  estado          TEXT    NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente','hecha','cancelada')),
  uuid            TEXT    NULL DEFAULT NULL,   -- folio fiscal cuando ya se hizo
  solicitada_en   TEXT    NOT NULL,
  hecha_en        TEXT    NULL DEFAULT NULL,
  usuario_id      INTEGER NULL DEFAULT NULL REFERENCES usuarios (id),
  notas           TEXT    NULL DEFAULT NULL,
  -- Cancelación (v9). motivo: clave del SAT 01, 02, 03 o 04; en solicitudes sin hacer, NULL.
  motivo_cancelacion TEXT NULL DEFAULT NULL,
  uuid_sustituto  TEXT    NULL DEFAULT NULL,   -- motivo 01: folio fiscal de la factura que la reemplaza
  cancelada_en    TEXT    NULL DEFAULT NULL,
  cancelada_sat   INTEGER NOT NULL DEFAULT 0,  -- 1 = ya se canceló también en el SAT
  sustituye_id    INTEGER NULL DEFAULT NULL REFERENCES facturas (id),
  -- Último envío por correo.
  enviada_en      TEXT    NULL DEFAULT NULL,
  enviada_a       TEXT    NULL DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_facturas_venta ON facturas (venta_id);
CREATE INDEX IF NOT EXISTS idx_facturas_estado ON facturas (estado);
CREATE UNIQUE INDEX IF NOT EXISTS ux_facturas_venta_vigente ON facturas (venta_id) WHERE estado <> 'cancelada';

-- XML y PDF de cada factura ya hecha (v9). Se guardan dentro de la base
-- para que entren en los respaldos.
CREATE TABLE IF NOT EXISTS factura_archivos (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  factura_id  INTEGER NOT NULL REFERENCES facturas (id),
  tipo        TEXT    NOT NULL CHECK (tipo IN ('xml','pdf')),
  nombre      TEXT    NOT NULL,
  contenido   BLOB    NOT NULL,
  subido_en   TEXT    NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_factura_archivos ON factura_archivos (factura_id, tipo);
