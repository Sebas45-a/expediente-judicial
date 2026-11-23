# Expediente Judicial — Aplicación de escritorio (Windows)

Convierte el sistema Laravel en un **programa instalable**: el usuario descarga
`ExpedienteJudicial-Instalador-1.0.0.exe`, da doble clic, y queda un ícono en el
escritorio. **No necesita instalar PHP, Composer, XAMPP ni nada más.**

## Cómo funciona

```
Expediente Judicial.exe  (Electron: ventana del programa)
   │
   ├── resources/php/        PHP 8.3 portátil + runtime de Visual C++
   ├── resources/laravel/    Su sistema (sin paquetes de desarrollo)
   │
   └── al abrir:
        1. Prepara %APPDATA%\Expediente Judicial  (.env, base SQLite, archivos, registros)
        2. php artisan migrate   (+ tipos iniciales la primera vez)
        3. Inicia PHP en 127.0.0.1:8765 (o el siguiente puerto libre)
        4. Muestra el sistema en la ventana
        5. Si Ollama está instalado pero apagado, lo enciende
```

- **Los datos del usuario nunca están en la carpeta de instalación**, sino en
  `%APPDATA%\Expediente Judicial`. Al actualizar o desinstalar, los expedientes se conservan.
- Antes de aplicar una versión nueva se guarda automáticamente una copia de la base en
  `%APPDATA%\Expediente Judicial\respaldos`.
- Cada instalación genera su propia `APP_KEY`.
- El servidor solo escucha en `127.0.0.1`: no es accesible desde la red.
- Menú **Archivo** → abrir la carpeta de datos, los respaldos o editar la configuración (`.env`,
  p. ej. para cambiar el modelo de Ollama).

## Construir el instalador

### Opción A — En una PC con Windows (una sola vez se instalan las herramientas)
Requisitos **solo en la PC que construye**: Node.js 20+, PHP 8.2+ y Composer.

1. Doble clic en **`construir-instalador.bat`** (en la carpeta raíz del proyecto).
2. Al terminar se abre `desktop\dist\` con `ExpedienteJudicial-Instalador-1.0.0.exe`.

El script copia el proyecto, instala dependencias sin las de desarrollo, descarga PHP
portátil oficial (verificando su SHA-256), **prueba que el sistema arranque** con ese PHP
y genera el instalador.

### Opción B — Sin instalar nada (GitHub Actions)
1. Suba el proyecto a un repositorio de GitHub.
2. Pestaña **Actions → Instalador de Windows → Run workflow**.
3. Descargue el `.exe` de la sección *Artifacts* cuando termine (~5–8 min).

Si crea una etiqueta (`git tag v1.1.0 && git push --tags`), se construye esa versión y se
publica como *Release* lista para compartir.

## Versiones y actualizaciones
Cambie `"version"` en `desktop/package.json` (o use una etiqueta `v1.2.3`) y vuelva a
construir. Al instalar encima de la versión anterior se conservan los datos y se aplican
las migraciones nuevas automáticamente.

## Probar durante el desarrollo (sin construir)
```bash
cd desktop
npm install
npm start          # usa el PHP del sistema y el proyecto de la carpeta superior
```
Los datos de prueba van a la carpeta de datos del usuario igual que en producción.

## La IA (Ollama)
Ollama **no se incluye** en el instalador (los modelos pesan varios GB). Si el usuario
quiere el asistente IA: instalar Ollama desde https://ollama.com/download y ejecutar
`ollama pull llama3.2:3b`. El programa lo detecta y lo enciende solo. Sin Ollama todo
lo demás funciona normalmente (como ya estaba diseñado).

## Avisos al instalar
- **Windows SmartScreen** mostrará "Windows protegió su PC" porque el instalador no está
  firmado. El usuario debe dar clic en *Más información → Ejecutar de todas formas*. Para
  quitar el aviso hay que firmar el `.exe` con un certificado de firma de código
  (se configura en `package.json` → `build.win`).
- El instalador **no pide permisos de administrador** (se instala para el usuario actual en
  `%LOCALAPPDATA%\Programs\Expediente Judicial`), aunque permite elegir otra carpeta.

## Límites conocidos
- El servidor PHP integrado atiende **una petición a la vez**: mientras la IA genera una
  respuesta larga, las demás pantallas esperan. Para uso de una persona es suficiente.
- Para que **varias personas** trabajen sobre la misma base, conviene instalarlo como
  sistema web en un servidor (ver README principal), no como programa de escritorio.
- macOS/Linux: el código del lanzador ya es multiplataforma, pero falta empaquetar un PHP
  portátil para esas plataformas.

## Archivos
| Archivo | Para qué |
|---|---|
| `main.js` | Ventana, menú en español, descargas, enlaces externos, Ollama |
| `servidor.js` | Carpeta de datos, migraciones, respaldo previo a actualización, servidor PHP |
| `splash.html` | Pantalla de carga |
| `recursos/php.ini.plantilla` | Configuración de PHP (extensiones, límites de 50 MB, zona horaria) |
| `recursos/icon.ico` / `icon.png` | Ícono del programa |
| `scripts/preparar-laravel.mjs` | Copia el proyecto y ejecuta `composer install --no-dev` |
| `scripts/preparar-php-windows.ps1` | Descarga y verifica PHP portátil |
| `construir-windows.ps1` | Ejecuta todo y genera el instalador |
| `../bootstrap/app.php` | Único cambio en Laravel: usa la carpeta de datos cuando existe `EXPEDIENTE_DATOS` |
