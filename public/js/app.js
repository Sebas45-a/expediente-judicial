// Utilidades de la interfaz (sin dependencias externas)
(function () {
  const token = document.querySelector('meta[name="csrf-token"]')?.content;

  window.api = async function (url, datos = {}, metodo = 'POST') {
    const opciones = {
      method: metodo,
      headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'Content-Type': 'application/json' },
    };
    if (metodo !== 'GET') opciones.body = JSON.stringify(datos);
    let r;
    try {
      r = await fetch(url, opciones);
    } catch (e) {
      return { ok: false, error: 'No hay conexión con el servidor de la aplicación.' };
    }
    let json = {};
    try { json = await r.json(); } catch (e) { json = { ok: false, error: 'Respuesta inesperada del servidor (HTTP ' + r.status + ').' }; }
    if (r.status === 422 && json.errors) json.error = Object.values(json.errors).flat().join(' ');
    if (json.ok === undefined) json.ok = r.ok;
    return json;
  };

  // Pantalla de espera para formularios largos (importaciones, análisis con IA)
  window.mostrarCarga = function (texto) {
    const c = document.getElementById('cargando');
    if (!c) return;
    c.querySelector('p').textContent = texto || 'Procesando…';
    c.classList.add('visible');
  };
  document.querySelectorAll('form[data-carga]').forEach(f => f.addEventListener('submit', () => {
    if (f.checkValidity()) mostrarCarga(f.dataset.carga);
  }));

  // Confirmaciones
  document.querySelectorAll('[data-confirmar]').forEach(el => el.addEventListener('submit', e => {
    if (!confirm(el.dataset.confirmar)) e.preventDefault();
  }));

  // Escapar HTML
  window.esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // Indicador de estado de Ollama en el menú lateral (se refresca cada 60 s)
  const ind = document.getElementById('estado-ia');
  async function refrescarEstado() {
    if (!ind) return;
    const e = await api(ind.dataset.url, {}, 'GET');
    const p = ind.querySelector('.punto'), t = ind.querySelector('.txt');
    if (e.disponible) {
      p.className = 'punto ' + ((e.advertencias || []).length ? 'aviso' : 'ok');
      t.textContent = 'IA lista · ' + (e.modelo_efectivo || e.modelo);
    } else {
      p.className = 'punto mal';
      t.textContent = 'IA no disponible';
    }
    ind.title = e.mensaje || e.error || '';
  }
  refrescarEstado();
  setInterval(refrescarEstado, 60000);
})();
