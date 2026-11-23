<#
  Construye el instalador de Windows de Expediente Judicial.
  Resultado: desktop\dist\ExpedienteJudicial-Instalador-<versión>.exe

  Requisitos en la PC donde se construye (solo esta, no la de los usuarios):
    - Node.js 20 o superior   https://nodejs.org
    - PHP 8.2+ y Composer     (Laragon/XAMPP + https://getcomposer.org)
#>
$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

function Requiere($cmd, $ayuda) {
    if (-not (Get-Command $cmd -ErrorAction SilentlyContinue)) { throw "No se encontró '$cmd'. $ayuda" }
}
Requiere node     'Instale Node.js desde https://nodejs.org'
Requiere npm      'Instale Node.js desde https://nodejs.org'
Requiere php      'Instale PHP 8.2+ (Laragon o XAMPP) y agréguelo al PATH'
Requiere composer 'Instale Composer desde https://getcomposer.org'

Write-Host "`n[1/4] Preparando Laravel (sin paquetes de desarrollo)..." -ForegroundColor Cyan
node scripts/preparar-laravel.mjs
if ($LASTEXITCODE) { throw 'Falló la preparación de Laravel' }

Write-Host "`n[2/4] Preparando PHP portátil..." -ForegroundColor Cyan
& (Join-Path $PSScriptRoot 'scripts\preparar-php-windows.ps1')

Write-Host "`n[3/4] Probando Laravel con el PHP portátil..." -ForegroundColor Cyan
$datos = Join-Path $env:TEMP ("expediente-prueba-" + [guid]::NewGuid())
New-Item -ItemType Directory $datos | Out-Null
$prueba = @"
const { iniciar } = require('./servidor');
iniciar({ laravel: 'build/laravel', php: 'build/php/php.exe', phpIniPlantilla: 'build/php/php.ini.plantilla',
          datos: process.argv[2], version: 'prueba', progreso: (m) => console.log('   ' + m) })
  .then(async (s) => {
    const r = await fetch(s.url + '/');
    s.detener();
    if (r.status !== 200) { console.error('El panel respondió ' + r.status); process.exit(1); }
    console.log('   Panel principal OK (' + s.url + ')');
  })
  .catch((e) => { console.error(e.message); process.exit(1); });
"@
[IO.File]::WriteAllText((Join-Path $PSScriptRoot 'probar-temp.js'), $prueba)
node .\probar-temp.js $datos
$fallo = $LASTEXITCODE
Remove-Item .\probar-temp.js
if ($fallo) { throw "La prueba falló. Revise $datos\storage\logs" }
Remove-Item $datos -Recurse -Force

Write-Host "`n[4/4] Generando instalador..." -ForegroundColor Cyan
if (Test-Path package-lock.json) { npm ci --no-audit --no-fund } else { npm install --no-audit --no-fund }
if ($LASTEXITCODE) { throw 'Falló npm install' }
npm run dist:win
if ($LASTEXITCODE) { throw 'Falló electron-builder' }

$exe = Get-ChildItem dist\*.exe | Sort-Object LastWriteTime -Descending | Select-Object -First 1
Write-Host "`nListo: $($exe.FullName)  ($([math]::Round($exe.Length/1MB)) MB)" -ForegroundColor Green
