; =====================================================================
;  TiendaWeb - Instalador para Windows (Inno Setup 6.3+ / 7)
;
;  Antes: arma la app con empaquetar\armar_app.ps1 (crea C:\xampp\TiendaWeb-escritorio).
;  Compilar:  ISCC.exe empaquetar\instalador.iss
;  Resultado: C:\xampp\TiendaWeb-instalador\Punto-de-venta-Instalador-<versión>.exe
;
;  - Se instala solo para el usuario actual (no pide permisos de administrador)
;    en %LOCALAPPDATA%\Programs\TiendaWeb.
;  - Crea acceso directo en el escritorio y en el menú Inicio.
;  - Los datos (%LOCALAPPDATA%\TiendaWeb) nunca se borran: ni al actualizar
;    ni al desinstalar.
; =====================================================================

#define AppNombre   "Punto de venta"
#define AppVersion  "3.8.1"
#define AppExe      "Punto de venta.exe"
#define AppOrigen   "C:\xampp\TiendaWeb-escritorio"

[Setup]
AppId={{8B3F6C21-4D7A-4E59-A1C3-5F2E9D7B6A40}
AppName={#AppNombre}
AppVersion={#AppVersion}
AppVerName={#AppNombre} {#AppVersion}
AppPublisher={#AppNombre}
DefaultDirName={autopf}\TiendaWeb
DefaultGroupName={#AppNombre}
DisableProgramGroupPage=yes
DisableDirPage=yes
PrivilegesRequired=lowest
; Requisitos: Windows 10 u 11 de 64 bits (el navegador interno, Chromium 130, ya no funciona
; en Windows 7/8/8.1). En un equipo que no cumple, el instalador lo dice en lugar de fallar después.
MinVersion=10.0
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
OutputDir=C:\xampp\TiendaWeb-instalador
OutputBaseFilename=Punto-de-venta-Instalador-{#AppVersion}
SetupIconFile=tiendaweb.ico
UninstallDisplayIcon={app}\tiendaweb.ico
UninstallDisplayName={#AppNombre}
Compression=lzma2/max
SolidCompression=yes
WizardStyle=modern
CloseApplications=yes
RestartApplications=no

[Languages]
Name: "es"; MessagesFile: "compiler:Languages\Spanish.isl"

[Files]
Source: "{#AppOrigen}\*"; DestDir: "{app}"; Excludes: "webcache,debug.log"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{autodesktop}\{#AppNombre}"; Filename: "{app}\{#AppExe}"; IconFilename: "{app}\tiendaweb.ico"; WorkingDir: "{app}"; Comment: "Punto de venta"
Name: "{autoprograms}\{#AppNombre}"; Filename: "{app}\{#AppExe}"; IconFilename: "{app}\tiendaweb.ico"; WorkingDir: "{app}"; Comment: "Punto de venta"

[Run]
Filename: "{app}\{#AppExe}"; Description: "Abrir {#AppNombre} ahora"; WorkingDir: "{app}"; Flags: nowait postinstall skipifsilent

[InstallDelete]
; Una actualización reemplaza el sistema completo (los datos están en otra carpeta).
Type: filesandordirs; Name: "{app}\www"
; Lo que quedó del nombre anterior.
Type: files; Name: "{app}\TiendaWeb.exe"
Type: files; Name: "{autodesktop}\TiendaWeb.lnk"
Type: files; Name: "{autoprograms}\TiendaWeb.lnk"

[UninstallDelete]
Type: filesandordirs; Name: "{app}\webcache"
Type: filesandordirs; Name: "{app}\www"

[Messages]
WinVersionTooLowError=El Punto de venta necesita Windows 10 u 11 de 64 bits.%n%nEsta computadora tiene una versión anterior de Windows, en la que el programa no puede funcionar.
OnlyOnTheseArchitectures=El Punto de venta necesita Windows 10 u 11 de 64 bits.%n%nEsta computadora tiene Windows de 32 bits, en el que el programa no puede funcionar.%n%n(%1)
FinishedLabel=Se instaló [name] en esta computadora.%n%nÁbrelo desde el ícono del escritorio.%n%nRecuerda: en el menú «Respaldos» puedes guardar una copia de tus datos en una USB.
