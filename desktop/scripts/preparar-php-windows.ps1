<#
  Descarga PHP portátil para Windows (x64, NTS) y lo deja en desktop\build\php
  listo para incluirse en el instalador.

    powershell -ExecutionPolicy Bypass -File scripts\preparar-php-windows.ps1 [-Version 8.3]
#>
param(
    [string]$Version = '8.3',
    [string]$Destino = (Join-Path $PSScriptRoot '..\build\php')
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$Destino = [IO.Path]::GetFullPath($Destino)
Write-Host "-> Buscando la última versión de PHP $Version para Windows..."
$releases = Invoke-RestMethod 'https://windows.php.net/downloads/releases/releases.json'
$info = $releases.$Version
if (-not $info) { throw "No se encontró PHP $Version en windows.php.net" }

# La clave cambia según el compilador (vs16 para 8.3, vs17 para 8.4+)
$clave = $info.PSObject.Properties.Name | Where-Object { $_ -match '^nts-vs\d+-x64$' } | Select-Object -First 1
if (-not $clave) { throw "No hay compilación NTS x64 para PHP $Version" }
$zipInfo = $info.$clave.zip
$url = "https://windows.php.net/downloads/releases/$($zipInfo.path)"

$marca = Join-Path $Destino 'VERSION.txt'
if ((Test-Path $marca) -and ((Get-Content $marca -Raw).Trim() -eq $info.version)) {
    Write-Host "OK PHP $($info.version) ya estaba preparado."
} else {
    $zip = Join-Path $env:TEMP $zipInfo.path
    Write-Host "-> Descargando $url"
    Invoke-WebRequest $url -OutFile $zip
    if ($zipInfo.sha256) {
        $hash = (Get-FileHash $zip -Algorithm SHA256).Hash.ToLower()
        if ($hash -ne $zipInfo.sha256.ToLower()) { throw "La suma SHA256 de $($zipInfo.path) no coincide." }
    }
    if (Test-Path $Destino) { Remove-Item $Destino -Recurse -Force }
    Expand-Archive $zip -DestinationPath $Destino
    Remove-Item $zip
    # Lo que no se usa en tiempo de ejecución
    foreach ($x in 'dev', 'extras', 'php.ini-development', 'php.ini-production', 'phpdbg.exe', 'php-cgi.exe') {
        $p = Join-Path $Destino $x
        if (Test-Path $p) { Remove-Item $p -Recurse -Force }
    }
    Set-Content $marca $info.version
}

# Plantilla de php.ini (el programa pone la ruta real de las extensiones al iniciar)
Copy-Item (Join-Path $PSScriptRoot '..\recursos\php.ini.plantilla') (Join-Path $Destino 'php.ini.plantilla') -Force

# PHP necesita el runtime de Visual C++. Se copia junto a php.exe para que funcione
# aunque la PC del usuario no tenga instalado el "Visual C++ Redistributable".
foreach ($dll in 'vcruntime140.dll', 'vcruntime140_1.dll', 'msvcp140.dll') {
    $src = Join-Path $env:WINDIR "System32\$dll"
    if (Test-Path $src) { Copy-Item $src $Destino -Force }
    else { Write-Warning "$dll no está en este equipo; instale 'Microsoft Visual C++ Redistributable x64' antes de construir." }
}

# Verificación: PHP arranca con la configuración real y tiene las extensiones necesarias
$ini = Join-Path $env:TEMP 'php-expediente-prueba.ini'
(Get-Content (Join-Path $Destino 'php.ini.plantilla') -Raw).Replace('{{EXT_DIR}}', (Join-Path $Destino 'ext').Replace('\', '/')) |
    Set-Content $ini -Encoding UTF8
$php = Join-Path $Destino 'php.exe'
$modulos = & $php -c $ini -m 2>&1 | Out-String
foreach ($m in 'curl', 'fileinfo', 'gd', 'mbstring', 'openssl', 'pdo_sqlite', 'sqlite3', 'zip', 'dom', 'Zend OPcache') {
    if ($modulos -notmatch [regex]::Escape($m)) { throw "Falta la extensión PHP '$m'.`n$modulos" }
}
if ($modulos -match 'Warning|Unable to load') { throw "PHP mostró avisos al iniciar:`n$modulos" }
Remove-Item $ini
Write-Host "OK PHP $(Get-Content $marca) listo en $Destino"
