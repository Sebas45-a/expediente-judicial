@extends('layouts.app')
@section('titulo', 'Asistente IA')
@section('contenido')
<div class="cabecera">
    <div><h1>Asistente IA local (Ollama)</h1><p>Responde únicamente con la información de los expedientes. Nada sale de su equipo.</p></div>
    <div class="acciones"><button class="btn" id="btn-probar">⚡ Probar conexión</button><a class="btn" href="{{ route('asistente.index', ['forzar' => 1]) }}" id="btn-refrescar">↻ Verificar de nuevo</a></div>
</div>

<div class="rejilla r-2-1">
    <div class="tarjeta">
        <div class="tarjeta-titulo">
            <h2>Consulta</h2>
            <select id="expediente" style="max-width: 380px">
                <option value="">Todos los expedientes (búsqueda general)</option>
                @foreach ($expedientes as $ex)<option value="{{ $ex->id }}" @selected($expediente?->id === $ex->id)>{{ $ex->numero_expediente ?: '#'.$ex->id }} · {{ \Illuminate\Support\Str::limit($ex->titulo, 45) }}</option>@endforeach
            </select>
        </div>
        <div class="chat" id="chat">
            @forelse ($historial as $h)
                <div class="msg usuario">{{ $h->pregunta }}@if($h->expediente)<div class="meta">Exp. {{ $h->expediente->numero_expediente ?: '#'.$h->expediente->id }}</div>@endif</div>
                <div class="msg {{ $h->exito ? 'ia' : 'error' }}">{{ $h->exito ? $h->respuesta : $h->error }}<div class="meta">{{ $h->modelo }} · {{ round($h->duracion_ms / 1000, 1) }} s</div></div>
            @empty
                <div class="vacio" id="chat-vacio"><span class="grande">✦</span>Ejemplos: «¿Qué expedientes de pensión alimenticia están en audiencia?», «¿Quién es el demandado en el expediente 01043-2024-00321?»</div>
            @endforelse
        </div>
        <form class="chat-form" id="chat-form">
            <textarea id="pregunta" placeholder="Escriba su pregunta… (Enter para enviar, Shift+Enter nueva línea)" required></textarea>
            <button class="btn btn-primario" id="btn-enviar">Enviar</button>
        </form>
    </div>

    <div>
        <div class="tarjeta">
            <h2>Estado</h2>
            @if ($estado['disponible'])
                <div class="alerta ok"><span class="punto ok"></span>{{ $estado['mensaje'] }}</div>
            @else
                <div class="alerta error"><span class="punto mal"></span>{{ $estado['mensaje'] }}</div>
            @endif
            @foreach ($estado['advertencias'] as $adv)<div class="alerta aviso">{{ $adv }}</div>@endforeach
            <dl class="ficha">
                <dt>Servidor</dt><dd class="mono">{{ $estado['url'] }}</dd>
                <dt>Versión Ollama</dt><dd>{{ $estado['version'] ?? '—' }}</dd>
                <dt>Modelo de chat</dt><dd>{{ $estado['modelo_efectivo'] ?? $estado['modelo'] }}</dd>
                <dt>Embeddings</dt><dd>{{ $estado['modelo_embeddings'] }} {!! $estado['embeddings_instalado'] ? '<span class="chip verde">instalado</span>' : '<span class="chip naranja">no instalado</span>' !!}</dd>
                <dt>Fragmentos indexados</dt><dd>{{ number_format($estado['fragmentos_indexados']) }}</dd>
                <dt>Consultas</dt><dd>{{ $estado['consultas']['total'] }} ({{ $estado['consultas']['exitosas'] }} exitosas · prom. {{ round($estado['consultas']['promedio_ms'] / 1000, 1) }} s)</dd>
            </dl>
            <div id="resultado-prueba" style="margin-top:10px"></div>
        </div>

        @if ($estado['modelos'])
        <div class="tarjeta">
            <h2>Cambiar modelo</h2>
            <form method="post" action="{{ route('asistente.modelo') }}">@csrf
                <select name="modelo">@foreach ($estado['modelos'] as $m)@unless($m['embeddings'])<option value="{{ $m['nombre'] }}" @selected($m['nombre'] === ($estado['modelo_efectivo'] ?? ''))>{{ $m['nombre'] }} · {{ $m['parametros'] }} {{ $m['tamano'] }}</option>@endunless @endforeach</select>
                <button class="btn" style="margin-top:8px">Usar este modelo</button>
            </form>
        </div>
        @endif

        <div class="tarjeta">
            <h2>Instalación de Ollama</h2>
            <ol style="padding-left:18px;margin:0;font-size:.9rem">
                <li>Descargue e instale desde <b>ollama.com</b>.</li>
                <li>En una terminal ejecute:<pre class="mono" style="background:#f4f5f7;padding:8px;border-radius:5px;margin:6px 0">ollama pull {{ $estado['modelo'] }}
ollama pull {{ $estado['modelo_embeddings'] }}</pre></li>
                <li>Deje Ollama abierto y pulse «Verificar de nuevo».</li>
            </ol>
            <p class="ayuda">Con poca memoria (≤ 8 GB) use <span class="mono">llama3.2:1b</span> o <span class="mono">qwen2.5:1.5b</span>. Con 16 GB o GPU: <span class="mono">qwen2.5:7b</span> o <span class="mono">llama3.1:8b</span>.</p>
        </div>
    </div>
</div>
@endsection
@push('scripts')
<script>
(function () {
    const chat = document.getElementById('chat'), form = document.getElementById('chat-form'), campo = document.getElementById('pregunta'), btn = document.getElementById('btn-enviar'), sel = document.getElementById('expediente');
    const agregar = (clase, html) => { document.getElementById('chat-vacio')?.remove(); const d = document.createElement('div'); d.className = 'msg ' + clase; d.innerHTML = html; chat.appendChild(d); chat.scrollTop = chat.scrollHeight; return d; };
    form.addEventListener('submit', async e => {
        e.preventDefault(); const p = campo.value.trim(); if (!p) return;
        campo.value = ''; agregar('usuario', esc(p)); const espera = agregar('ia escribiendo', 'Buscando en los expedientes'); btn.disabled = true;
        const r = await api(@json(route('asistente.preguntar')), { pregunta: p, expediente_id: sel.value || null });
        espera.remove(); btn.disabled = false;
        r.ok ? agregar('ia', esc(r.respuesta) + '<div class="meta">' + esc(r.modelo) + ' · ' + r.segundos + ' s · ' + (r.fragmentos || []).length + ' fragmento(s)</div>') : agregar('error', esc(r.error || 'Sin respuesta.'));
    });
    campo.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });
    sel.addEventListener('change', () => location.href = @json(route('asistente.index')) + (sel.value ? '?expediente=' + sel.value : ''));
    document.getElementById('btn-probar').addEventListener('click', async e => {
        const caja = document.getElementById('resultado-prueba'); e.target.disabled = true; caja.innerHTML = '<p class="suave escribiendo">Probando</p>';
        const r = await api(@json(route('asistente.probar')), {}); e.target.disabled = false;
        caja.innerHTML = r.ok ? '<div class="alerta ok">✓ Respondió «' + esc(r.respuesta) + '» en ' + r.segundos + ' s con ' + esc(r.modelo) + '</div>' : '<div class="alerta error">' + esc(r.error) + '</div>';
    });
    chat.scrollTop = chat.scrollHeight;
})();
</script>
@endpush
