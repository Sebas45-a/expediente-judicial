@extends('layouts.app')
@section('titulo', $e->numero_expediente ?: $e->titulo)
@section('contenido')
<div class="cabecera">
    <div><div class="migas"><a href="{{ route('expedientes.index') }}">Expedientes</a> /</div>
        <h1>{{ $e->titulo }}</h1>
        <p><span class="mono">{{ $e->numero_expediente ?: 'Sin número' }}</span> · @include('partials.tipo', ['tipo' => $e->tipo]) · @include('partials.estado', ['estado' => $e->estado])</p></div>
    <div class="acciones">
        <a class="btn" href="{{ route('expedientes.edit', $e) }}">✎ Editar</a>
        <form class="inline" method="post" action="{{ route('expedientes.destroy', $e) }}" data-confirmar="¿Eliminar este expediente?">@csrf @method('DELETE')<button class="btn btn-peligro">Eliminar</button></form>
    </div>
</div>

<div class="rejilla r-1-2">
    <div>
        <div class="tarjeta">
            <h2>Ficha</h2>
            <dl class="ficha">
                <dt>Juzgado</dt><dd>{{ $e->juzgado ?: '—' }}</dd>
                <dt>Demandante / actor</dt><dd>{{ $e->demandante ?: '—' }}</dd>
                <dt>Demandado / sindicado</dt><dd>{{ $e->demandado ?: '—' }}</dd>
                <dt>Fecha de inicio</dt><dd>{{ $e->fecha_inicio?->format('d/m/Y') ?: '—' }}</dd>
                <dt>Origen</dt><dd>{{ $e->origen?->etiqueta() }} @if($e->importacion)(<a href="{{ route('importar.show', $e->importacion) }}">{{ $e->importacion->archivo_nombre }}</a>)@endif</dd>
                <dt>Clasificación</dt><dd>{{ ['manual' => 'Manual', 'excel' => 'Desde Excel', 'aprendizaje' => 'Aprendizaje automático', 'ia' => 'IA (Ollama)'][$e->clasificado_por] ?? '—' }} @if($e->clasificacion_confianza && $e->clasificado_por !== 'manual')· {{ $e->clasificacion_confianza }}%@endif</dd>
                @foreach ((array) $e->datos_extra as $k => $v)<dt>{{ $k }}</dt><dd>{{ is_scalar($v) ? $v : json_encode($v) }}</dd>@endforeach
                <dt>Registrado</dt><dd>{{ $e->created_at->format('d/m/Y H:i') }}</dd>
            </dl>
            @if ($e->descripcion)<h3 style="margin-top:14px">Descripción</h3><p style="white-space: pre-wrap">{{ $e->descripcion }}</p>@endif
            @if ($e->archivo_ruta)<p><a class="btn btn-chico" href="{{ route('expedientes.archivo', $e) }}">⇩ {{ $e->archivo_nombre }}</a></p>@endif
        </div>

        <div class="tarjeta">
            <h2>Generar documento con formato</h2>
            @if ($formatos->isEmpty())
                <p class="suave">Este tipo aún no tiene un formato aprendido. <a href="{{ route('formatos.create', ['tipo' => $e->tipo_expediente_id]) }}">Enseñar un formato</a> con un documento de ejemplo.</p>
            @else
                @foreach ($formatos as $f)
                    <div class="acciones" style="justify-content: space-between; margin-bottom: 8px">
                        <span>{{ $f->nombre }} <small>v{{ $f->version }}</small> @if($f->activa)<span class="chip verde">activo</span>@endif</span>
                        <a class="btn btn-chico btn-primario" href="{{ route('formatos.usar', [$f, 'expediente' => $e->id]) }}">Usar →</a>
                    </div>
                @endforeach
            @endif
        </div>
    </div>

    <div>
        <div class="tarjeta">
            <div class="tarjeta-titulo"><h2>Consultar a la IA sobre este expediente</h2>
                <div class="acciones">
                    <button class="btn btn-chico" id="btn-resumen">✦ Resumen</button>
                    <form class="inline" method="post" action="{{ route('expedientes.indexar', $e) }}">@csrf<button class="btn btn-chico" title="Recalcular índice de búsqueda">↻ Indexar</button></form>
                </div>
            </div>
            <div class="chat" id="chat">
                @forelse ($historial as $h)
                    <div class="msg usuario">{{ $h->pregunta }}</div>
                    <div class="msg {{ $h->exito ? 'ia' : 'error' }}">{{ $h->exito ? $h->respuesta : $h->error }}<div class="meta">{{ $h->modelo }} · {{ round($h->duracion_ms / 1000, 1) }} s · {{ $h->created_at->format('d/m H:i') }}</div></div>
                @empty
                    <div class="vacio" id="chat-vacio">Pregunte lo que necesite: partes, fechas, hechos, plazos… La respuesta se basa solo en este expediente.</div>
                @endforelse
            </div>
            <form class="chat-form" id="chat-form">
                <textarea id="pregunta" placeholder="Ej.: ¿Cuál es la pretensión del demandante?" required></textarea>
                <button class="btn btn-primario" id="btn-enviar">Enviar</button>
            </form>
            <div class="sugerencias">
                @foreach (['¿Quiénes son las partes?', '¿Cuáles son las fechas importantes?', 'Resume los hechos', '¿Cuál es el estado actual y qué sigue?'] as $s)
                    <button type="button" class="btn btn-chico" data-sugerencia>{{ $s }}</button>
                @endforeach
            </div>
        </div>
        @if ($e->contenido_texto)
        <div class="tarjeta">
            <details><summary>Ver contenido completo ({{ number_format(mb_strlen($e->contenido_texto)) }} caracteres)</summary>
                <div class="documento" style="margin-top:12px">{{ $e->contenido_texto }}</div></details>
        </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const chat = document.getElementById('chat'), form = document.getElementById('chat-form'), campo = document.getElementById('pregunta'), btn = document.getElementById('btn-enviar');
    const agregar = (clase, html) => { document.getElementById('chat-vacio')?.remove(); const d = document.createElement('div'); d.className = 'msg ' + clase; d.innerHTML = html; chat.appendChild(d); chat.scrollTop = chat.scrollHeight; return d; };
    async function enviar(url, datos, textoUsuario) {
        agregar('usuario', esc(textoUsuario));
        const espera = agregar('ia escribiendo', 'Analizando el expediente');
        btn.disabled = true;
        const r = await api(url, datos);
        espera.remove(); btn.disabled = false;
        if (r.ok) agregar('ia', esc(r.respuesta) + '<div class="meta">' + esc(r.modelo) + ' · ' + r.segundos + ' s</div>');
        else agregar('error', esc(r.error || 'No se pudo obtener respuesta.'));
    }
    form.addEventListener('submit', e => { e.preventDefault(); const p = campo.value.trim(); if (!p) return; campo.value = ''; enviar(@json(route('asistente.preguntar')), { pregunta: p, expediente_id: {{ $e->id }} }, p); });
    campo.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });
    document.querySelectorAll('[data-sugerencia]').forEach(b => b.addEventListener('click', () => { campo.value = b.textContent; form.requestSubmit(); }));
    document.getElementById('btn-resumen').addEventListener('click', () => enviar(@json(route('asistente.resumir', $e)), {}, 'Resumen ejecutivo del expediente'));
    chat.scrollTop = chat.scrollHeight;
})();
</script>
@endpush
