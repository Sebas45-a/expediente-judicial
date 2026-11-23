/**
 * Copia el proyecto Laravel a desktop/build/laravel, sin lo que no hace falta en
 * producción, e instala las dependencias de Composer sin paquetes de desarrollo.
 *
 *   node scripts/preparar-laravel.mjs
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const escritorio = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const origen = path.resolve(escritorio, '..');
const destino = path.join(escritorio, 'build', 'laravel');

// Rutas relativas al proyecto que NO se copian.
const excluir = new Set([
  'desktop', '.git', '.github', '.idea', '.vscode', 'node_modules', 'vendor', 'tests',
  'storage', '.env', '.env.backup', 'database/database.sqlite',
  'instalar.bat', 'iniciar.bat', 'iniciar.sh', 'construir-instalador.bat',
  'phpunit.xml', '.phpunit.result.cache', '.styleci.yml', '.editorconfig', '.gitattributes',
]);

function copiar(rel = '') {
  const abs = path.join(origen, rel);
  for (const e of fs.readdirSync(abs, { withFileTypes: true })) {
    const r = rel ? `${rel}/${e.name}` : e.name;
    if (excluir.has(r)) continue;
    if (r.startsWith('bootstrap/cache/') && e.name.endsWith('.php')) continue;
    const de = path.join(origen, r);
    const a = path.join(destino, r);
    if (e.isDirectory()) {
      fs.mkdirSync(a, { recursive: true });
      copiar(r);
    } else if (e.isFile()) {
      fs.copyFileSync(de, a);
    }
  }
}

console.log(`→ Copiando proyecto a ${destino}`);
fs.rmSync(destino, { recursive: true, force: true });
fs.mkdirSync(destino, { recursive: true });
copiar();

// Estructura mínima de storage (en uso real, el programa usa la carpeta de datos del usuario).
for (const c of ['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs']) {
  fs.mkdirSync(path.join(destino, 'storage', c), { recursive: true });
}
fs.mkdirSync(path.join(destino, 'bootstrap', 'cache'), { recursive: true });

console.log('→ composer install --no-dev');
const composer = 'composer';
execFileSync(
  composer,
  ['install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress', '--no-scripts', '--prefer-dist'],
  { cwd: destino, stdio: 'inherit', shell: process.platform === 'win32' },
);

// Quitar archivos de documentación/pruebas de vendor para reducir el tamaño del instalador.
let quitados = 0;
const basura = /^(tests?|docs?|\.github|examples?)$/i;
(function limpiar(dir, nivel) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) {
      // Solo carpetas de primer nivel de cada paquete (vendor/<autor>/<paquete>/tests)
      if (nivel === 2 && basura.test(e.name)) {
        fs.rmSync(p, { recursive: true, force: true });
        quitados++;
      } else if (nivel < 2) {
        limpiar(p, nivel + 1);
      }
    }
  }
})(path.join(destino, 'vendor'), 0);

console.log(`✓ Laravel listo (${quitados} carpetas de pruebas/documentación quitadas de vendor).`);
