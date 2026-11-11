# SPDX-License-Identifier: GPL-3.0-only
<#
  TiendaWeb - Arma la app de escritorio con PHP Desktop.

  Uso (desde la carpeta del proyecto):
      powershell -ExecutionPolicy Bypass -File empaquetar\armar_app.ps1 -PhpDesktop "C:\ruta\phpdesktop-chrome-130.1-php-8.3"

  Resultado: la carpeta -Salida (por defecto C:\xampp\TiendaWeb-escritorio)
  con "Punto de venta.exe" listo para copiar a otra computadora o instalar.
  Los datos del negocio NO van dentro: viven en %LOCALAPPDATA%\TiendaWeb.
#>
param(
    [Parameter(Mandatory = $true)][string]$PhpDesktop,
    [string]$Salida = "C:\xampp\TiendaWeb-escritorio"
)
$ErrorActionPreference = "Stop"

$proyecto = Split-Path -Parent $PSScriptRoot
$aqui     = $PSScriptRoot

if (-not (Test-Path (Join-Path $PhpDesktop "phpdesktop-chrome.exe"))) {
    throw "No encontré phpdesktop-chrome.exe en $PhpDesktop"
}

# Solo se borra una salida anterior si es claramente una app armada por este script.
if (Test-Path $Salida) {
    if (-not (Test-Path (Join-Path $Salida "Punto de venta.exe")) -and -not (Test-Path (Join-Path $Salida "TiendaWeb.exe"))) {
        throw "La carpeta $Salida ya existe y no parece una app del Punto de venta. Elige otra con -Salida."
    }
    Remove-Item -Recurse -Force $Salida
}

Write-Host "1/4 Copiando PHP Desktop..."
robocopy $PhpDesktop $Salida /E /NFL /NDL /NJH /NJS /NP `
    /XD (Join-Path $PhpDesktop "www") webcache `
    /XF settings.json debug.log phpdesktop-chrome.exe | Out-Null
if ($LASTEXITCODE -ge 8) { throw "robocopy falló copiando PHP Desktop ($LASTEXITCODE)" }
Copy-Item (Join-Path $PhpDesktop "phpdesktop-chrome.exe") (Join-Path $Salida "Punto de venta.exe")

Write-Host "2/4 Copiando el sistema a www..."
robocopy $proyecto (Join-Path $Salida "www") /E /NFL /NDL /NJH /NJS /NP `
    /XD (Join-Path $proyecto "empaquetar") (Join-Path $proyecto "datos") (Join-Path $proyecto "tests") .git .claude `
    /XF *.stackdump *.sqlite | Out-Null
if ($LASTEXITCODE -ge 8) { throw "robocopy falló copiando el sistema ($LASTEXITCODE)" }

Write-Host "3/4 Configuración, php.ini e ícono..."
Copy-Item (Join-Path $aqui "settings.json") (Join-Path $Salida "settings.json")
Copy-Item (Join-Path $aqui "php.ini")       (Join-Path $Salida "php\php.ini") -Force
Copy-Item (Join-Path $aqui "tiendaweb.ico") (Join-Path $Salida "tiendaweb.ico")

# PHP necesita el runtime de Visual C++ (vcruntime140.dll). Muchas computadoras
# recién instaladas no lo tienen y entonces la app muestra "500 Server Error".
# Se incluye junto a PHP (instalación local, la busca primero en su carpeta).
foreach ($dll in "vcruntime140.dll", "vcruntime140_1.dll") {
    $origen = Join-Path $env:WINDIR "System32\$dll"
    if (-not (Test-Path $origen)) { throw "No encontré $origen. Instala el 'Visual C++ Redistributable x64' de Microsoft y vuelve a armar." }
    if ((Get-AuthenticodeSignature $origen).Status -ne "Valid") { throw "La firma de $origen no es válida." }
    Copy-Item $origen (Join-Path $Salida "php\$dll") -Force
}

Write-Host "4/4 Verificando extensiones de PHP..."
$modulos = & (Join-Path $Salida "php\php.exe") -c (Join-Path $Salida "php\php.ini") -m
foreach ($m in "mbstring", "pdo_sqlite", "session") {
    if ($modulos -notcontains $m) { throw "Falta la extensión de PHP: $m" }
}

$mb = [math]::Round(((Get-ChildItem $Salida -Recurse | Measure-Object Length -Sum).Sum / 1MB))
Write-Host ""
Write-Host "Listo: $Salida\Punto de venta.exe  ($mb MB)"
