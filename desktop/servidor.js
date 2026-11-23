/**
 * Arranque del motor Laravel para la aplicación de escritorio.
 *
 * - Prepara la carpeta de datos del usuario (.env, base SQLite, storage, cachés).
 * - Ejecuta las migraciones (y los datos iniciales la primera vez).
 * - Inicia el servidor PHP integrado en 127.0.0.1 en un puerto libre.
 * - Espera a que /up responda antes de devolver la URL.
 *
 * No depende de Electron: se puede probar con `node servidor.js`.
 */
const { spawn, execFile } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const http = require('http');
const net = require('net');
const path = require('path');

const PUERTO_PREFERIDO = 8765;

function crearCarpetas(datos) {
  const carpetas = [
    'storage/app/private',
    'storage/app/public',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
    'cache',
    'database',
    'respaldos',
    'php',
  ];
  for (const c of carpetas) fs.mkdirSync(path.join(datos, c), { recursive: true });
}

/** Crea el .env del usuario a partir de .env.example la primera vez (o repara una clave vacía). */
function asegurarEnv(laravel, datos) {
  const destino = path.join(datos, '.env');
  const clave = () => 'base64:' + crypto.randomBytes(32).toString('base64');

  if (!fs.existsSync(destino)) {
    let texto = fs.readFileSync(path.join(laravel, '.env.example'), 'utf8');
    const fijar = (k, v) => {
      const re = new RegExp(`^${k}=.*$`, 'm');
      texto = re.test(texto) ? texto.replace(re, `${k}=${v}`) : texto + `\n${k}=${v}`;
    };
    fijar('APP_ENV', 'production');
    fijar('APP_DEBUG', 'false');
    fijar('APP_KEY', clave());
    fijar('LOG_LEVEL', 'warning');
    // La ruta de la base de datos la define el programa en cada inicio (ver entornoPhp).
    texto = texto.replace(/^DB_DATABASE=.*$/m, '# DB_DATABASE la define el programa');
    fs.writeFileSync(destino, texto, 'utf8');
    return;
  }

  const texto = fs.readFileSync(destino, 'utf8');
  if (/^APP_KEY=\s*$/m.test(texto)) {
    fs.writeFileSync(destino, texto.replace(/^APP_KEY=\s*$/m, `APP_KEY=${clave()}`), 'utf8');
  }
}

/** php.ini con rutas absolutas (extension_dir relativo fallaría porque el directorio de trabajo es public/). */
function prepararPhpIni(phpBin, datos, plantilla) {
  if (!plantilla || !fs.existsSync(plantilla)) return null;
  const extDir = path.join(path.dirname(phpBin), 'ext');
  const ini = fs.readFileSync(plantilla, 'utf8').replace(/\{\{EXT_DIR\}\}/g, extDir.replace(/\\/g, '/'));
  const destino = path.join(datos, 'php', 'php.ini');
  fs.writeFileSync(destino, ini, 'utf8');
  return destino;
}

function entornoPhp(datos, puerto) {
  const cache = (n) => path.join(datos, 'cache', n);
  return {
    ...process.env,
    EXPEDIENTE_DATOS: datos,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: path.join(datos, 'database', 'expedientes.sqlite'),
    APP_URL: puerto ? `http://127.0.0.1:${puerto}` : 'http://127.0.0.1',
    APP_SERVICES_CACHE: cache('services.php'),
    APP_PACKAGES_CACHE: cache('packages.php'),
    APP_CONFIG_CACHE: cache('config.php'),
    APP_ROUTES_CACHE: cache('routes-v7.php'),
    APP_EVENTS_CACHE: cache('events.php'),
  };
}

function argsPhp(phpIni) {
  const base = ['-d', 'upload_max_filesize=50M', '-d', 'post_max_size=55M', '-d', 'memory_limit=512M', '-d', 'max_execution_time=600'];
  return phpIni ? ['-c', phpIni, ...base] : base;
}

function ejecutar(phpBin, args, opciones, log) {
  return new Promise((resolve, reject) => {
    execFile(phpBin, args, { ...opciones, windowsHide: true, timeout: 5 * 60 * 1000, maxBuffer: 20 * 1024 * 1024 }, (err, stdout, stderr) => {
      log(`$ php ${args.slice(-3).join(' ')}\n${stdout}${stderr}`);
      if (err) {
        err.message = `${err.message}\n${stderr || stdout}`;
        return reject(err);
      }
      resolve(stdout);
    });
  });
}

function puertoLibre(preferido) {
  const probar = (p) =>
    new Promise((resolve) => {
      const srv = net.createServer();
      srv.once('error', () => resolve(false));
      srv.once('listening', () => srv.close(() => resolve(true)));
      srv.listen(p, '127.0.0.1');
    });
  return (async () => {
    for (let p = preferido; p < preferido + 50; p++) if (await probar(p)) return p;
    // Último recurso: que el sistema elija uno.
    return new Promise((resolve) => {
      const srv = net.createServer().listen(0, '127.0.0.1', () => {
        const { port } = srv.address();
        srv.close(() => resolve(port));
      });
    });
  })();
}

function esperarListo(url, msTotal = 60000) {
  const limite = Date.now() + msTotal;
  return new Promise((resolve, reject) => {
    const intentar = () => {
      const req = http.get(url + '/up', { timeout: 3000 }, (res) => {
        res.resume();
        if (res.statusCode === 200) return resolve();
        reintentar(new Error(`El servidor respondió ${res.statusCode}`));
      });
      req.on('error', reintentar);
      req.on('timeout', () => req.destroy(new Error('Tiempo agotado')));
    };
    const reintentar = (e) => (Date.now() > limite ? reject(e) : setTimeout(intentar, 300));
    intentar();
  });
}

/**
 * @param {object} o
 * @param {string} o.laravel   Carpeta del proyecto Laravel
 * @param {string} o.php       Ejecutable de PHP
 * @param {string} o.datos     Carpeta de datos del usuario
 * @param {string} o.version   Versión del programa (para respaldar la base antes de actualizar)
 * @param {string} [o.phpIniPlantilla]
 * @param {(msg:string)=>void} [o.progreso]
 */
async function iniciar(o) {
  const progreso = o.progreso || (() => {});
  // Rutas absolutas: el servidor PHP se ejecuta con public/ como directorio de trabajo.
  o = { ...o, laravel: path.resolve(o.laravel), datos: path.resolve(o.datos) };
  if (/[\\/]/.test(o.php)) o.php = path.resolve(o.php);
  if (o.phpIniPlantilla) o.phpIniPlantilla = path.resolve(o.phpIniPlantilla);
  crearCarpetas(o.datos);

  const archivoLog = path.join(o.datos, 'storage', 'logs', 'escritorio.log');
  try {
    if (fs.existsSync(archivoLog) && fs.statSync(archivoLog).size > 5 * 1024 * 1024) fs.rmSync(archivoLog);
  } catch {}
  const salidaLog = fs.createWriteStream(archivoLog, { flags: 'a' });
  const log = (t) => salidaLog.write(`[${new Date().toISOString()}] ${t}\n`);
  log(`--- Inicio v${o.version} · laravel=${o.laravel} · php=${o.php}`);

  progreso('Preparando datos…');
  asegurarEnv(o.laravel, o.datos);
  const phpIni = prepararPhpIni(o.php, o.datos, o.phpIniPlantilla);

  const db = path.join(o.datos, 'database', 'expedientes.sqlite');
  const baseNueva = !fs.existsSync(db) || fs.statSync(db).size === 0;
  if (!fs.existsSync(db)) fs.writeFileSync(db, '');
  const marcaInicial = path.join(o.datos, 'database', '.datos-iniciales');

  // Al cambiar de versión: respaldo de la base y limpieza de vistas compiladas.
  const archivoVersion = path.join(o.datos, 'version.txt');
  const versionAnterior = fs.existsSync(archivoVersion) ? fs.readFileSync(archivoVersion, 'utf8').trim() : null;
  if (versionAnterior !== o.version) {
    if (!baseNueva) {
      const sello = new Date().toISOString().replace(/[:T]/g, '-').slice(0, 19);
      fs.copyFileSync(db, path.join(o.datos, 'respaldos', `antes-de-v${o.version}_${sello}.sqlite`));
      log(`Respaldo previo a actualización (de v${versionAnterior}).`);
    }
    const vistas = path.join(o.datos, 'storage', 'framework', 'views');
    for (const f of fs.readdirSync(vistas)) if (f.endsWith('.php')) fs.rmSync(path.join(vistas, f), { force: true });
    for (const f of fs.readdirSync(path.join(o.datos, 'cache'))) fs.rmSync(path.join(o.datos, 'cache', f), { force: true });
  }

  const env = entornoPhp(o.datos, null);
  const artisan = path.join(o.laravel, 'artisan');

  progreso('Actualizando la base de datos…');
  await ejecutar(o.php, [...argsPhp(phpIni), artisan, 'migrate', '--force', '--no-interaction'], { cwd: o.laravel, env }, log);
  if (!fs.existsSync(marcaInicial)) {
    progreso('Cargando tipos de expediente iniciales…');
    await ejecutar(o.php, [...argsPhp(phpIni), artisan, 'db:seed', '--force', '--no-interaction'], { cwd: o.laravel, env }, log);
    fs.writeFileSync(marcaInicial, new Date().toISOString());
  }
  fs.writeFileSync(archivoVersion, o.version);

  progreso('Iniciando el sistema…');
  const puerto = await puertoLibre(PUERTO_PREFERIDO);
  const url = `http://127.0.0.1:${puerto}`;
  const router = path.join(o.laravel, 'vendor', 'laravel', 'framework', 'src', 'Illuminate', 'Foundation', 'resources', 'server.php');
  const publico = path.join(o.laravel, 'public');

  const proceso = spawn(o.php, [...argsPhp(phpIni), '-S', `127.0.0.1:${puerto}`, '-t', publico, router], {
    cwd: publico,
    env: entornoPhp(o.datos, puerto),
    windowsHide: true,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let terminado = false;
  const fallo = new Promise((_, reject) => {
    proceso.once('error', reject);
    proceso.once('exit', (code) => {
      terminado = true;
      log(`Servidor PHP terminó (código ${code}).`);
      reject(new Error(`El servidor PHP se cerró (código ${code}). Revise ${archivoLog}`));
    });
  });
  // El servidor integrado registra cada petición en stderr: solo guardamos avisos y errores.
  const filtrar = (b) => {
    for (const linea of b.toString().split(/\r?\n/)) {
      if (linea && !/\[\d{3}\]: (GET|POST|PUT|PATCH|DELETE|HEAD)|Accepted|Closing|Development Server/.test(linea)) log(linea);
    }
  };
  proceso.stdout.on('data', filtrar);
  proceso.stderr.on('data', filtrar);

  await Promise.race([esperarListo(url), fallo]);
  fallo.catch(() => {}); // a partir de aquí, el cierre se maneja con onSalida
  log(`Listo en ${url}`);

  return {
    url,
    puerto,
    archivoLog,
    onSalida: (fn) => (terminado ? fn() : proceso.once('exit', fn)),
    detener: () => {
      if (!terminado) proceso.kill();
      salidaLog.end();
    },
  };
}

module.exports = { iniciar };

// Prueba manual: node servidor.js <carpeta-laravel> <carpeta-datos> [php]
if (require.main === module) {
  const [laravel = path.resolve(__dirname, '..'), datos = path.join(__dirname, '.datos-prueba'), php = 'php'] = process.argv.slice(2);
  iniciar({ laravel, datos, php, version: 'dev', progreso: (m) => console.log(m) })
    .then((s) => {
      console.log(`Abra ${s.url}  (Ctrl+C para salir)`);
      process.on('SIGINT', () => (s.detener(), process.exit(0)));
    })
    .catch((e) => (console.error(e.message), process.exit(1)));
}
