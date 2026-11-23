/**
 * Expediente Judicial — aplicación de escritorio.
 * Ventana Electron que arranca el motor Laravel (PHP portátil incluido) y lo muestra
 * como un programa normal. Nada sale del equipo: el servidor solo escucha en 127.0.0.1.
 */
const { app, BrowserWindow, Menu, dialog, shell } = require('electron');
const { spawn } = require('child_process');
const fs = require('fs');
const http = require('http');
const path = require('path');
const { iniciar } = require('./servidor');

const NOMBRE = 'Expediente Judicial';
app.setName(NOMBRE);

if (!app.requestSingleInstanceLock()) {
  app.quit();
  return;
}

let ventana = null;
let splash = null;
let servidor = null;
let ollamaPropio = null;
let saliendo = false;

function rutas() {
  if (app.isPackaged) {
    const r = process.resourcesPath;
    return {
      laravel: path.join(r, 'laravel'),
      php: path.join(r, 'php', process.platform === 'win32' ? 'php.exe' : 'bin/php'),
      phpIniPlantilla: path.join(r, 'php', 'php.ini.plantilla'),
    };
  }
  // Desarrollo: el proyecto Laravel es la carpeta superior y PHP es el del sistema.
  return {
    laravel: path.resolve(__dirname, '..'),
    php: process.env.PHP_BIN || 'php',
    phpIniPlantilla: null,
  };
}

// Carpeta propia para los datos del sistema, separada de los archivos internos de la ventana
// (Electron guarda su "Cache", "Local Storage", etc. en userData).
const datos = path.join(app.getPath('userData'), 'Datos'); // Windows: %APPDATA%\Expediente Judicial\Datos

function mostrarSplash() {
  splash = new BrowserWindow({
    width: 440,
    height: 260,
    frame: false,
    resizable: false,
    show: false,
    backgroundColor: '#1d2b4a',
    icon: path.join(__dirname, 'recursos', 'icon.png'),
    webPreferences: { contextIsolation: true, nodeIntegration: false },
  });
  splash.loadFile(path.join(__dirname, 'splash.html'));
  splash.once('ready-to-show', () => splash.show());
}

function progresoSplash(mensaje) {
  if (splash && !splash.isDestroyed()) {
    splash.webContents.executeJavaScript(`window.progreso && window.progreso(${JSON.stringify(mensaje)})`).catch(() => {});
  }
}

function crearVentana(url) {
  ventana = new BrowserWindow({
    width: 1366,
    height: 860,
    minWidth: 1000,
    minHeight: 640,
    show: false,
    title: NOMBRE,
    icon: path.join(__dirname, 'recursos', 'icon.png'),
    webPreferences: { contextIsolation: true, nodeIntegration: false, sandbox: true, spellcheck: true },
  });
  ventana.webContents.session.setSpellCheckerLanguages(['es-ES']);

  const origen = new URL(url).origin;

  // Enlaces externos → navegador del sistema. Enlaces internos con target=_blank → nueva ventana de la app.
  ventana.webContents.setWindowOpenHandler(({ url: destino }) => {
    if (destino.startsWith(origen)) return { action: 'allow', overrideBrowserWindowOptions: { autoHideMenuBar: true } };
    shell.openExternal(destino);
    return { action: 'deny' };
  });
  ventana.webContents.on('will-navigate', (e, destino) => {
    if (!destino.startsWith(origen)) {
      e.preventDefault();
      shell.openExternal(destino);
    }
  });

  // Descargas (Word generados, Excel exportados, respaldos): diálogo "Guardar como" en Descargas.
  ventana.webContents.session.on('will-download', (_e, item) => {
    item.setSaveDialogOptions({ title: 'Guardar archivo', defaultPath: path.join(app.getPath('downloads'), item.getFilename()) });
    item.once('done', (_ev, estado) => {
      if (estado === 'completed') shell.showItemInFolder(item.getSavePath());
    });
  });

  ventana.once('ready-to-show', () => {
    ventana.maximize();
    ventana.show();
    if (splash && !splash.isDestroyed()) splash.close();
  });
  ventana.on('page-title-updated', (e, t) => {
    e.preventDefault();
    ventana.setTitle(t && !t.startsWith('http') ? `${t} — ${NOMBRE}` : NOMBRE);
  });
  ventana.on('closed', () => (ventana = null));
  ventana.loadURL(url);
}

function menu() {
  const plantilla = [
    {
      label: 'Archivo',
      submenu: [
        { label: 'Inicio', accelerator: 'Alt+Home', click: () => servidor && ventana?.loadURL(servidor.url) },
        { type: 'separator' },
        { label: 'Abrir carpeta de datos', click: () => shell.openPath(datos) },
        { label: 'Abrir carpeta de respaldos', click: () => shell.openPath(path.join(datos, 'respaldos')) },
        { label: 'Editar configuración (.env)', click: () => shell.openPath(path.join(datos, '.env')) },
        { type: 'separator' },
        { label: 'Salir', role: 'quit' },
      ],
    },
    {
      label: 'Editar',
      submenu: [
        { role: 'undo', label: 'Deshacer' },
        { role: 'redo', label: 'Rehacer' },
        { type: 'separator' },
        { role: 'cut', label: 'Cortar' },
        { role: 'copy', label: 'Copiar' },
        { role: 'paste', label: 'Pegar' },
        { role: 'selectAll', label: 'Seleccionar todo' },
      ],
    },
    {
      label: 'Ver',
      submenu: [
        { label: 'Atrás', accelerator: 'Alt+Left', click: () => ventana?.webContents.navigationHistory.goBack() },
        { label: 'Adelante', accelerator: 'Alt+Right', click: () => ventana?.webContents.navigationHistory.goForward() },
        { role: 'reload', label: 'Recargar' },
        { type: 'separator' },
        { role: 'zoomIn', label: 'Acercar' },
        { role: 'zoomOut', label: 'Alejar' },
        { role: 'resetZoom', label: 'Tamaño normal' },
        { role: 'togglefullscreen', label: 'Pantalla completa' },
        ...(app.isPackaged ? [] : [{ role: 'toggleDevTools', label: 'Herramientas de desarrollo' }]),
      ],
    },
    {
      label: 'Ayuda',
      submenu: [
        { label: 'Descargar Ollama (IA local)', click: () => shell.openExternal('https://ollama.com/download') },
        { label: 'Ver registro del programa', click: () => servidor && shell.openPath(servidor.archivoLog) },
        { type: 'separator' },
        {
          label: `Acerca de ${NOMBRE}`,
          click: () =>
            dialog.showMessageBox({
              type: 'info',
              title: NOMBRE,
              message: `${NOMBRE} ${app.getVersion()}`,
              detail: `Gestión de expedientes judiciales con IA local.\n\nDatos guardados en:\n${datos}`,
            }),
        },
      ],
    },
  ];
  Menu.setApplicationMenu(Menu.buildFromTemplate(plantilla));
}

/** Si Ollama está instalado pero apagado, lo enciende (la app funciona igual sin él). */
function encenderOllama() {
  const url = process.env.OLLAMA_HOST_URL || 'http://127.0.0.1:11434';
  const req = http.get(url + '/api/version', { timeout: 1500 }, (res) => res.resume());
  req.on('timeout', () => req.destroy());
  req.on('error', () => {
    const candidatos =
      process.platform === 'win32'
        ? [path.join(process.env.LOCALAPPDATA || '', 'Programs', 'Ollama', 'ollama.exe'), 'ollama.exe']
        : ['/usr/local/bin/ollama', '/opt/homebrew/bin/ollama', '/usr/bin/ollama', 'ollama'];
    const exe = candidatos.find((c) => !path.isAbsolute(c) || fs.existsSync(c));
    try {
      ollamaPropio = spawn(exe, ['serve'], { windowsHide: true, stdio: 'ignore', detached: false });
      ollamaPropio.on('error', () => (ollamaPropio = null));
    } catch {
      ollamaPropio = null;
    }
  });
}

async function arrancar() {
  menu();
  mostrarSplash();
  encenderOllama();
  const r = rutas();
  try {
    servidor = await iniciar({ ...r, datos, version: app.getVersion(), progreso: progresoSplash });
  } catch (e) {
    try {
      fs.mkdirSync(path.join(datos, 'storage', 'logs'), { recursive: true });
      fs.appendFileSync(path.join(datos, 'storage', 'logs', 'escritorio.log'), `[${new Date().toISOString()}] ERROR: ${e.stack || e.message}\n`);
    } catch {}
    if (splash && !splash.isDestroyed()) splash.close();
    await dialog.showMessageBox({
      type: 'error',
      title: NOMBRE,
      message: 'No se pudo iniciar el sistema.',
      detail: `${String(e.message).slice(0, 1500)}\n\nRegistro: ${path.join(datos, 'storage', 'logs', 'escritorio.log')}`,
    });
    app.exit(1);
    return;
  }
  servidor.onSalida(() => {
    if (saliendo) return;
    dialog.showErrorBox(NOMBRE, `El motor del sistema se detuvo inesperadamente.\nRevise: ${servidor.archivoLog}\n\nEl programa se cerrará.`);
    app.exit(1);
  });
  crearVentana(servidor.url);
}

app.on('second-instance', () => {
  if (ventana) {
    if (ventana.isMinimized()) ventana.restore();
    ventana.focus();
  }
});

app.whenReady().then(arrancar);

app.on('window-all-closed', () => app.quit());

app.on('before-quit', () => {
  saliendo = true;
  servidor?.detener();
  if (ollamaPropio) ollamaPropio.kill();
});
