=====================================================================
  Punto de venta 3.8.2 - Para abarrotes, licorerías, papelerías y más
  App de escritorio para Windows (PHP 8 + SQLite + Bootstrap 5)
=====================================================================

Requisitos: Windows 10 u 11 de 64 bits. (No funciona en Windows 7, 8 u
8.1 ni en Windows de 32 bits: el navegador interno ya no los soporta.
El instalador lo avisa.)

No necesita Internet, XAMPP ni MySQL. Todo (productos, ventas, caja,
clientes e inventario) se guarda en UN solo archivo:

    %LOCALAPPDATA%\TiendaWeb\tiendaweb.sqlite
    (por ejemplo C:\Users\Ana\AppData\Local\TiendaWeb\tiendaweb.sqlite)

Ahí se queda aunque se actualice o reinstale el programa.


---------------------------------------------------------------------
 QUÉ INCLUYE
---------------------------------------------------------------------

 Modo sencillo (así viene): el menú muestra solo lo básico para vender
 y el producto pide solo nombre, precio, código y existencia; lo demás
 está en "Más opciones". Las funciones avanzadas (compras a
 proveedores, listas escolares, paquetes y kits, mayoreo) se prenden en
 Configuración → "Funciones avanzadas". Usuarios, respaldos y bitácora
 están dentro de Configuración.

 - Punto de venta con lector de códigos de barras, servicios de un
   clic (copias, impresiones), productos a granel y ventas en espera.
 - Cobro en efectivo (calcula el cambio), tarjeta, transferencia y
   crédito a clientes, o dividido entre varios (por ejemplo, parte en
   efectivo y parte con tarjeta); descuentos en monto o porcentaje.
 - IVA por producto (16%, 8%, 0% o exento), incluido en el precio, con
   el desglose en el ticket y el total de IVA en los reportes.
 - Bitácora: quién inició sesión, canceló, hizo descuentos, abrió el
   cajón sin venta, cambió precios, hizo cortes o cambió la configuración.
 - Caja: apertura con fondo, entradas y salidas de efectivo, y corte
   con conteo (dice si sobra o falta dinero).
 - Usuarios con PIN: el dueño y cajeros con los permisos que se elijan.
 - Clientes y crédito ("fiado"): límite, saldo, abonos y estado de
   cuenta.
 - Inventario: entradas de mercancía, conteo físico y el historial de
   cada producto (quién, cuándo y por qué cambió la existencia).
 - Catálogo: categorías, marca, código interno, descripción, unidad de
   medida, proveedor preferido y precios por cantidad (mayoreo: "desde
   12 piezas a $4.50"); el punto de venta aplica el precio solo.
 - Proveedores y compras: registra lo que llegó del proveedor (suma al
   inventario y actualiza el costo), si se pagó con dinero de la caja, y
   un sugerido de compra con lo que está por agotarse.
 - Devoluciones parciales: el cliente regresa una o varias piezas (no toda
   la venta); se decide si vuelve al anaquel o viene dañado y cómo se le
   reembolsa.
 - Para papelería: paquetes (caja de 12 lápices que descuenta 12 lápices),
   kits (kit escolar), listas escolares que se cargan al carrito con un
   clic, y etiquetas con código de barras (hoja carta o rollo).
 - Importar y exportar productos con Excel (lo exportado se puede volver
   a importar); exportar ventas y reportes a Excel.
 - Impresora de tickets térmica (ESC/POS, 58 u 80 mm) y cajón de
   dinero.
 - Reportes: ventas, utilidad, métodos de pago, cajeros, crédito,
   productos más vendidos e inventario.
 - Respaldo automático diario y respaldo manual a USB.


---------------------------------------------------------------------
 PRIMER USO
---------------------------------------------------------------------

En cualquier pantalla, el botón "Ayuda" (arriba a la derecha) explica
paso por paso cómo vender, devolver, agregar productos, fiar, facturar
y cerrar el día. El Inicio tiene botones grandes con lo de todos los
días y una lista de "Primeros pasos".

1. Abre "Punto de venta" (doble clic en el ícono del escritorio). La
   primera vez aparece un asistente que pide:
   - El tipo de negocio: abarrotes, licorería, papelería u otro (cambia
     los ejemplos y las categorías sugeridas; se puede cambiar después
     en Configuración).
   - El nombre del negocio (y datos para el ticket, opcional).
   - Cómo empezar: VACÍO (para la tienda real) o con productos de
     ejemplo de tu giro, todos con IVA 16% (para practicar).
   - Tu usuario de dueño con un PIN de 4 a 6 números (recomendado).
     Desde ese momento el sistema pide el PIN al abrirse.
   El asistente solo aparece en un sistema sin estrenar: una vez que
   hay ventas, no se puede volver a abrir.

2. Configuración: si tienes impresora de tickets, elígela ahí y prueba
   la impresión y el cajón.

3. Usuarios: crea a tus cajeros y marca lo que cada uno puede hacer.

4. Productos: regístralos uno por uno o impórtalos de Excel
   ("Productos" → "Importar de Excel", hay una plantilla).

5. Vender: en "Punto de venta" primero se abre la caja con el fondo
   (el cambio con el que empieza el cajón). Escanea o busca los
   productos, presiona "Cobrar" (F9), elige cómo paga y confirma.

6. Al terminar el día: "Caja y cortes" → cuenta el efectivo del cajón,
   escríbelo y haz el corte. El sistema dice si cuadra, sobra o falta.


---------------------------------------------------------------------
 USO DEL PUNTO DE VENTA
---------------------------------------------------------------------

 - F2: ir al buscador.  F4: dejar la venta en espera.  F9: cobrar.
 - Flechas ↓ ↑ y Enter: elegir un resultado de la búsqueda.
 - Enter en la cantidad de un producto: regresa al buscador.
 - El carrito se conserva si se cierra la ventana por accidente.
 - Ventas en espera: si un cliente olvidó algo, "Espera" (F4) aparta
   su cuenta para atender al siguiente; el botón amarillo la recupera.

 Lector de códigos de barras (cualquier marca USB tipo "teclado"):
 conéctalo, haz clic en el buscador y escanea. Si el código existe, el
 producto se agrega (y si ya estaba, aumenta la cantidad); si no, se
 avisa y se ofrece registrarlo. Sin lector, escribe el código y Enter.

 Formas de pago: efectivo (calcula el cambio y no deja cobrar si falta),
 transferencia, tarjeta (solo se registra; el sistema NO hace cargos
 bancarios: cobra primero en tu terminal) y crédito (hay que elegir al
 cliente y no deja pasar su límite).

 Dividir pago: escribe cuánto paga con cada forma. Tarjeta,
 transferencia y crédito se cobran tal cual; en efectivo escribe lo que
 te entrega y el sistema calcula el cambio. El corte y los reportes
 cuentan cada parte en su método.

 IVA: el precio de cada producto ya incluye el IVA (como lo pide la ley
 en México). En la ficha del producto se elige la tasa: 16% general, 8%
 región fronteriza, 0% (libros, alimentos, medicinas) o exento. Si tienes
 dudas de qué tasa lleva un artículo, pregúntale a tu contador.
 Ojo: el ticket NO es una factura (CFDI). Ver "Ticket y facturas".

 Descuento: en la ventana de cobro, "Agregar descuento" ($ o %). Solo
 aparece a quien tiene permiso.


---------------------------------------------------------------------
 CAJA Y CORTE
---------------------------------------------------------------------

 - Sin caja abierta no se puede cobrar.
 - Entradas y salidas (pago a proveedor, retiro del dueño, más cambio)
   se registran en "Caja y cortes" para que el corte cuadre.
 - Debe haber en el cajón = fondo + ventas en efectivo + abonos en
   efectivo + entradas − salidas. Tarjeta, transferencia y crédito no
   entran al cajón y se muestran aparte.
 - Si se cancela una venta en efectivo de un día anterior, la
   devolución sale sola de la caja abierta.
 - Las devoluciones en efectivo y las compras pagadas con dinero de la
   caja se registran solas como salidas.
 - El corte se puede imprimir en la térmica o en hoja.


---------------------------------------------------------------------
 CLIENTES Y CRÉDITO
---------------------------------------------------------------------

 - "Clientes y crédito" → "Nuevo cliente". El límite es opcional.
 - Vender a crédito: en el cobro elige "Crédito" y al cliente. El ticket
   sale con su saldo y espacio para firma.
 - Abonos: en la ficha del cliente → "Registrar abono". Un abono en
   efectivo entra al corte de caja.
 - Cancelar una venta a crédito o un abono corrige el saldo solo.


---------------------------------------------------------------------
 TICKET Y FACTURAS
---------------------------------------------------------------------

 El ticket sale desglosado: cada producto con su cantidad, precio y
 descuento; lo que va sin IVA y el IVA de cada tasa; el total con letra
 ("CIENTO VEINTICINCO PESOS 00/100 M.N."); cómo pagó, el cambio y quién
 atendió. Al final trae el aviso para pedir factura (se cambia en
 Configuración; vacío = no sale).

 Factura: abre la venta en "Ventas hechas" → "Pedir factura" y copia los
 datos de la Constancia de Situación Fiscal del cliente (RFC, nombre,
 código postal, régimen y uso). Si el cliente está registrado, sus datos
 se guardan para la próxima vez.
 - "Ventas hechas" → "Facturas" muestra las que faltan por hacer.
   "Descargar para el contador" baja un Excel (.xlsx) con cada producto, su
   precio sin IVA, IVA, forma de pago (01 efectivo, 03 transferencia,
   04 tarjeta de crédito; si fue de débito es 28) y método (PUE o PPD).
 - Cuando ya esté hecha, dale a "Ya la hice" y sube el XML y el PDF que
   te dio el SAT: el folio fiscal se lee solo del XML (y revisa que sea
   del RFC correcto). Ahí mismo puedes mandarla por correo al cliente.
 - Mandar por correo: primero configura tu correo en Configuración →
   "Correo para mandar facturas" (hay botones para Gmail, Outlook y
   Yahoo; Gmail y Yahoo piden una "contraseña de aplicación"). Prueba con
   "Guardar y mandarme un correo de prueba". La contraseña se guarda solo
   en esta computadora y nunca se muestra.
 - Corregir: si todavía no se hace, "Corregir los datos". Si ya se hizo,
   hay que cancelarla y hacer otra.
 - Cancelar una factura hecha: elige el motivo del SAT:
     01 tenía errores y ya hiciste la que la reemplaza (pega su folio
        fiscal y los datos correctos),
     02 tenía errores y no se repone,
     03 la venta no se llevó a cabo,
     04 la venta ya entró en la factura global.
   Después cancélala también en sat.gob.mx (Factura electrónica →
   Cancela y recupera tus facturas) y dale a "Ya la cancelé en el SAT".
   Mientras falte, la lista de facturas lo avisa en rojo. Si es de más
   de $1,000, el cliente debe aceptar la cancelación en su buzón del SAT
   (si no contesta en 3 días, se acepta sola).
 - Si se cancela la venta, la factura que estaba por hacer se cancela
   sola; si ya estaba hecha, el sistema avisa que la canceles con 03.
 - Factura global: las ventas del mes sin factura propia se descargan
   juntas para que el contador haga la factura de "público en general".
 - El sistema NO timbra: la factura se hace en la página gratuita del SAT
   o con tu contador (o con un PAC, si algún día lo contratas).

---------------------------------------------------------------------
 COMPRAS Y DEVOLUCIONES
---------------------------------------------------------------------

 Compras: "Compras" → "Nueva compra" (o "Inventario" → "Registrar
 compra"). Elige al proveedor, escanea o busca lo que llegó y escribe
 cantidad y costo. Marca "Se pagó con dinero de la caja" si salió del
 cajón. "Sugerido" carga lo que está por agotarse de ese proveedor.
 Si la compra se registró mal, cancélala en su detalle: regresa la
 existencia y, si se pagó de la caja, el dinero.

 Devoluciones: en el historial abre la venta → "Devolver productos".
 Escribe cuántas piezas regresa el cliente y el motivo.
 - "¿Regresa al anaquel?": desmárcalo si viene dañado (se reembolsa
   pero no se suma a la existencia).
 - Reembolso: efectivo (sale de la caja), tarjeta (devuélvelo en tu
   terminal), transferencia, o descontarlo de lo que debe el cliente.
 - Se reembolsa lo que el cliente pagó por esas piezas (con su parte
   del descuento), nunca más.
 - Imprime su comprobante, queda en la bitácora y los reportes la
   restan el día que se hizo.
 - Una venta con devoluciones ya no se puede cancelar completa: lo que
   resta se devuelve con "Devolver productos".


---------------------------------------------------------------------
 PAQUETES, KITS, LISTAS ESCOLARES Y ETIQUETAS
---------------------------------------------------------------------

 Paquete: en la ficha del producto, "¿Qué es este producto?" →
 "Paquete de otro producto", y escribe cuántas trae (ej. 12 de "Lápiz").
 El paquete no tiene existencia propia: al venderlo se descuentan 12
 lápices. Así puedes vender la caja o la pieza suelta sin descuadrar.

 Kit: "Kit de varios productos" y elige sus artículos (ej. 2 cuadernos,
 3 lápices, 1 borrador). Ponle el precio de kit. Al venderlo se
 descuenta cada artículo; los disponibles se calculan solos.

 Listas escolares: menú "Listas escolares" → "Nueva lista" con el
 grado, la escuela y los artículos. Se puede imprimir como cotización
 para los papás. En el punto de venta, botón "Lista escolar": carga
 toda la lista al carrito y quitas lo que el cliente ya tenga (avisa si
 algo está agotado).

 Etiquetas: "Productos" → "Etiquetas". Elige cuántas de cada producto,
 el formato (hoja carta de 30 o 65, o rollo de 50×25 / 40×30 mm) y si
 llevan precio. A los productos sin código de fábrica se les puede
 asignar uno interno (empieza con 200) para escanearlos. Al imprimir,
 elige "Tamaño real" (100 %). Prueba una etiqueta con tu lector antes de
 imprimir muchas.


---------------------------------------------------------------------
 INVENTARIO
---------------------------------------------------------------------

 - "Entrada de mercancía": lo que llegó del proveedor se SUMA. Si
   escribes el costo nuevo, se actualiza el del producto.
 - "Conteo físico": escribes lo que hay en el anaquel y la existencia
   queda igual; la diferencia se guarda como ajuste.
 - Cada venta, cancelación, entrada o ajuste queda en el historial con
   quién lo hizo.


---------------------------------------------------------------------
 IMPRESORA DE TICKETS Y CAJÓN
---------------------------------------------------------------------

 Funciona con impresoras térmicas ESC/POS de 58 u 80 mm (Epson,
 3nStar, EC Line, Xprinter y genéricas). El cajón se conecta con su
 cable de teléfono (RJ11/RJ12) al puerto "DK" o "Cash drawer" de la
 impresora.

 1. Instala la impresora en Windows con el driver del fabricante.
 2. "Configuración" → "USB (instalada en Windows)" → elige la
    impresora (o "Cable de red" con su dirección IP, puerto 9100).
 3. "Guardar e imprimir prueba". Si salen signos raros en vez de
    acentos, cambia "Acentos y ñ". Si el cajón no abre, cambia
    "Conector del cajón" a "Alternativo".

 No elijas una impresora normal (de tinta o láser): imprimiría hojas
 con símbolos raros.


---------------------------------------------------------------------
 RESPALDOS (IMPORTANTE)
---------------------------------------------------------------------

 - Automático: cada día, la primera vez que se abre el sistema, se
   guarda una copia en %LOCALAPPDATA%\TiendaWeb\respaldos (se
   conservan las últimas 30).
 - Manual: "Respaldos" → "Descargar respaldo ahora". Guarda el archivo
   en una USB o en tu correo, al menos una vez por semana. Las copias
   automáticas están en la misma computadora: si el disco se daña,
   solo te salva una copia guardada fuera.
 - Restaurar o cambiar de computadora: instala el Punto de venta en la nueva,
   "Respaldos" → "Restaurar una copia" y elige el archivo .sqlite.
   Antes de reemplazar, se guarda una copia de lo que había. Sirve
   también con respaldos de versiones anteriores.


---------------------------------------------------------------------
 SI SE OLVIDA EL PIN DEL DUEÑO
---------------------------------------------------------------------

 Desde la carpeta donde está instalado el Punto de venta
 (%LOCALAPPDATA%\Programs\TiendaWeb), en una consola:

     php\php.exe -c php\php.ini www\herramientas\restablecer_pin.php
     php\php.exe -c php\php.ini www\herramientas\restablecer_pin.php Ana 1234

 El primero lista los usuarios; el segundo le pone a "Ana" el PIN 1234.


---------------------------------------------------------------------
 LIMPIAR LA BASE (MANTENIMIENTO)
---------------------------------------------------------------------

 No hay botón para borrar dentro del sistema, a propósito: así nadie
 en la tienda puede desaparecer ventas. La limpieza se hace desde la
 consola de la computadora, con el Punto de venta cerrado, en la carpeta donde
 está instalado (%LOCALAPPDATA%\Programs\TiendaWeb):

     php\php.exe -c php\php.ini www\herramientas\limpiar_base.php ventas
     php\php.exe -c php\php.ini www\herramientas\limpiar_base.php todo
     php\php.exe -c php\php.ini www\herramientas\limpiar_base.php fabrica

 - ventas:  borra ventas, cortes, movimientos de caja, abonos e
            historial de inventario. Conserva productos (con su
            existencia actual), clientes, usuarios y configuración.
 - todo:    además borra productos, categorías, proveedores y clientes.
            Conserva usuarios y
            configuración.
 - fabrica: deja el sistema como recién instalado (también borra
            usuarios y configuración); al abrirlo aparece el asistente.

 Pide escribir BORRAR para confirmar, guarda antes un respaldo
 "antes-de-limpiar-..." (se deshace con Respaldos → Restaurar) y los
 folios vuelven a empezar en 000001.

 Ojo: es para entregar el sistema limpio o empezar de nuevo, no para
 una tienda en operación (conviene conservar el historial de ventas).


---------------------------------------------------------------------
 SI VENÍAS DE LA VERSIÓN CON XAMPP / MYSQL
---------------------------------------------------------------------

 Con MySQL iniciado en XAMPP, ejecuta una sola vez en la consola:

     C:\xampp\php\php.exe herramientas\importar_desde_mysql.php

 Copia productos, ventas y detalle a la base SQLite.


---------------------------------------------------------------------
 CÓMO PROTEGE TUS DATOS
---------------------------------------------------------------------

 - El navegador solo envía qué productos y cuántos. Precios,
   existencias, descuentos, total y cambio se calculan en el servidor
   con los datos de la base; no se pueden alterar desde la pantalla.
 - Cada venta, cancelación, corte, abono o entrada se guarda dentro de
   una transacción: o se guarda todo o no se guarda nada.
 - Mientras se cobra, la base queda reservada: un doble clic o dos
   ventanas no pueden vender la misma última pieza.
 - Cada intento de cobro lleva un identificador único: un doble clic o
   un reenvío no crea ventas duplicadas.
 - Ventas, abonos y cortes nunca se borran: se cancelan con motivo y
   quedan registrados con el usuario que lo hizo.
 - Los productos no se borran: se desactivan, y el historial guarda el
   nombre, precio y costo que tenían al momento de cada venta.
 - PIN guardado cifrado (password_hash), permisos revisados en el
   servidor en cada acción, consultas preparadas (PDO), escape de HTML
   contra XSS y token CSRF en todo lo que modifica datos.


---------------------------------------------------------------------
 ESTRUCTURA DE ARCHIVOS
---------------------------------------------------------------------

 tiendaweb/
   index.php                  Inicio (resumen del día)
   bienvenida.php             Asistente de primer uso (solo en sistema nuevo)
   login.php / salir.php      Entrar con PIN / cambiar de usuario
   usuarios.php               Usuarios y permisos (solo dueño)
   ajustes.php                Configuración: negocio, impresora, cajón
   respaldos.php              Descargar y restaurar respaldos
   configuracion.php          Constantes: zona horaria, moneda, depuración
   conexion.php               Abre/crea/actualiza la base SQLite (PDO)
   database.sql               Tablas de la base (se aplica sola)
   includes/giros.php         Tipos de negocio: ejemplos, categorías y textos
   includes/
     app.php                  Arranque común, errores y sesión
     funciones.php            Formato, CSRF, validación, métodos de pago
     usuarios.php             PIN, sesión y permisos
     caja.php                 Turnos, entradas/salidas y corte
     clientes.php             Saldos, abonos y comprobantes
     inventario.php           Historial de movimientos (kardex)
     auditoria.php            Bitácora: función auditar() y acciones registradas
     ajustes.php              Ajustes guardados en la base
     impresora.php            Tickets ESC/POS, cajón y envío a impresora
     respaldos.php            Respaldo diario, descarga y restauración
     limpieza.php             Limpieza de la base y productos de ejemplo
     encabezado.php, pie.php  Menú lateral, barra superior, scripts
   ventas/                    Punto de venta, cobro, historial, detalle,
                              cancelación e impresora (JSON)
   caja/                      Caja, acciones y detalle de cortes
   clientes/                  Lista, ficha, estado de cuenta y abonos
   productos/                 Lista, alta, edición, categorías, etiquetas, importar/exportar
   listas/                    Listas escolares (alta, impresión)
   proveedores/               Proveedores
   inventario/                Historial, entradas y conteo físico
   reportes/                  Reportes por periodo
   auditoria.php              Bitácora de auditoría (solo dueño)
   herramientas/              Consola: limpiar base, restablecer PIN, importar MySQL
   tests/                     Pruebas automáticas (no van en el instalador)
   empaquetar/                Armado de la app de escritorio e instalador
   assets/                    Estilos, JavaScript y librerías (sin Internet)


---------------------------------------------------------------------
 PRUEBAS AUTOMÁTICAS (para quien da mantenimiento)
---------------------------------------------------------------------

     C:\xampp\php\php.exe tests\correr.php

 Arrancan un servidor de prueba con una base temporal (nunca tocan los
 datos del negocio) y revisan: números exactos, IVA, tickets ESC/POS,
 primer uso, caja y corte, descuentos, pagos divididos, crédito y su
 límite, cancelaciones, bitácora, todas las pantallas y la
 actualización de una base de la versión 1. Córrelas antes de armar un
 instalador nuevo: deben terminar con "0 fallaron".


---------------------------------------------------------------------
 ARMAR LA APP DE ESCRITORIO (para quien da mantenimiento)
---------------------------------------------------------------------

 1. Descarga PHP Desktop Chrome para Windows (probado con 130.1 /
    PHP 8.3): https://github.com/cztomczak/phpdesktop/releases
    y descomprímelo.
 2. Desde la carpeta del proyecto:
      powershell -ExecutionPolicy Bypass -File empaquetar\armar_app.ps1 -PhpDesktop "C:\ruta\phpdesktop-chrome-130.1-php-8.3"
 3. Resultado: C:\xampp\TiendaWeb-escritorio\ con "Punto de venta.exe".
 4. Instalador (requiere Inno Setup 6.3+ o 7, https://jrsoftware.org):
      "%LOCALAPPDATA%\Programs\Inno Setup 7\ISCC.exe" empaquetar\instalador.iss
    Resultado: C:\xampp\TiendaWeb-instalador\Punto-de-venta-Instalador-<versión>.exe
    Ese único archivo es el que se lleva a la computadora de la tienda.
    Al cambiar de versión, actualiza APP_VERSION en configuracion.php
    y AppVersion en instalador.iss. Si cambias database.sql, sube
    DB_ESQUEMA_VERSION en conexion.php (las bases se actualizan solas).

 El instalador no pide administrador, crea el ícono en el escritorio y
 en Inicio, y se desinstala desde Configuración → Aplicaciones. Ni la
 actualización ni la desinstalación borran los datos.

 Como el instalador no tiene firma digital, Windows puede mostrar
 "Windows protegió su PC": clic en "Más información" → "Ejecutar de
 todas formas".

 Archivos en empaquetar/: settings.json (ventana de PHP Desktop),
 php.ini (extensiones, FFI para la impresora, registro de errores en
 %LOCALAPPDATA%\TiendaWeb\errores-php.log), tiendaweb.ico y
 generar_icono.php (vuelve a crear el ícono).


---------------------------------------------------------------------
 PROBLEMAS FRECUENTES
---------------------------------------------------------------------

 "No se pudo abrir la base de datos"
   → La carpeta %LOCALAPPDATA%\TiendaWeb no existe o no se puede
     escribir en ella. Revisa el antivirus o los permisos de la carpeta.

 La impresora no imprime
   → Revisa que esté encendida y con papel, que en Windows imprima una
     página de prueba, y en "Configuración" usa "Guardar e imprimir
     prueba". El mensaje de error dice qué falló.

 Ver los errores en detalle mientras desarrollas
   → En configuracion.php cambia APP_DEBUG a true (regrésalo a false
     después).

 Desarrollar sin la app de escritorio
   → C:\xampp\php\php.exe -S 127.0.0.1:8000 -t C:\xampp\htdocs\tiendaweb
     y abre http://127.0.0.1:8000/  (usa la misma base de datos;
     define la variable TIENDAWEB_DATOS para usar otra carpeta).
     La impresora USB solo funciona con el PHP de la app (necesita FFI).
