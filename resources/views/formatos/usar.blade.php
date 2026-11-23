@extends('layouts.app')
@section('titulo', 'Completar '.$p->nombre)
@section('contenido')
<div class="cabecera">
    <div><div class="migas"><a href="{{ route('formatos.index') }}">Formatos</a> / <a href="{{ route('formatos.show', $p) }}">{{ $p->nombre }}</a> /</div>
        <h1>Completar formato</h1><p>Llene solo los datos importantes; el resto del documento ya está redactado.</p></div>
    <a class="btn" href="{{ route('formatos.blanco', $p) }}">⇩ Descargar en blanco</a>
</div>
<form method="post" action="{{ route('formatos.generar', $p) }}" id="form-usar">
    @csrf
    <input type="hidden" name="expediente_id" id="expediente_id" value="{{ $expediente?->id }}">
    <div class="rejilla r-1-2">
        <div>
            <div class="tarjeta">
                <label>Tomar datos de un expediente</label>
                <div class="acciones">
                    <select id="sel-expediente" style="flex:1"><option value="">— Ninguno —</option>
                        @foreach ($expedientesTipo as $ex)<option value="{{ $ex->id }}" @selected($expediente?->id === $ex->id)>{{ $ex->numero_expediente ?: '#'.$ex->id }} · {{ \Illuminate\Support\Str::limit($ex->titulo, 40) }}</option>@endforeach
                    </select>
                    <button type="button" class="btn btn-chico" id="btn-ia" title="La IA lee el expediente y propone los valores">✦ Completar con IA</button>
                </div>
                <div id="msg-ia" class="ayuda"></div>
            </div>
            <div class="tarjeta">
                @foreach ($p->campos as $c)
                    <div class="campo"><label>{{ $c->etiqueta }} @if($c->obligatorio)*@endif</label>
                        <input type="{{ $c->tipo_dato->inputHtml() }}" name="valores[{{ $c->clave }}]" data-clave="{{ $c->clave }}" value="{{ $valores[$c->clave] ?? '' }}" placeholder="Ej.: {{ \Illuminate\Support\Str::limit($c->valor_ejemplo, 50) }}">
                    </div>
                @endforeach
                <label class="check"><input type="checkbox" name="permitir_vacios" value="1"> Permitir espacios vacíos (se imprimen como líneas para llenar a mano)</label>
                <button class="btn btn-primario" style="margin-top:10px">⇩ Generar documento Word</button>
            </div>
        </div>
        <div class="tarjeta">
            <h2>Vista previa</h2>
            <div class="documento" id="vista"></div>
        </div>
    </div>
</form>
@endsection
@push('scripts')
<script>
(function () {
    const plantilla = @json($p->contenido_plantilla);
    const etiquetas = @json($p->campos->pluck('etiqueta', 'clave'));
    const vista = document.getElementById('vista');
    const inputs = () => document.querySelectorAll('[data-clave]');
    function pintar() {
        const v = {}; inputs().forEach(i => v[i.dataset.clave] = i.value.trim());
        vista.innerHTML = esc(plantilla).replace(/\{\{([a-z0-9_]+)\}\}/g, (m, k) => v[k] ? '<span class="dato">' + esc(v[k]) + '</span>' : '<span class="blanco">' + esc(etiquetas[k] || k) + '</span>');
    }
    inputs().forEach(i => i.addEventListener('input', pintar));
    pintar();

    const sel = document.getElementById('sel-expediente');
    sel.addEventListener('change', () => { if (sel.value) location.href = @json(route('formatos.usar', $p)) + '?expediente=' + sel.value; });
    document.getElementById('btn-ia').addEventListener('click', async e => {
        const msg = document.getElementById('msg-ia');
        if (!sel.value) { msg.textContent = 'Primero elija un expediente.'; return; }
        e.target.disabled = true; msg.innerHTML = '<span class="escribiendo">La IA está leyendo el expediente</span>';
        const r = await api(@json(route('formatos.sugerir', $p)), { expediente_id: sel.value });
        e.target.disabled = false;
        if (!r.ok) { msg.textContent = r.error || 'No se pudo completar.'; return; }
        let n = 0; inputs().forEach(i => { if (r.valores[i.dataset.clave] && !i.value) { i.value = r.valores[i.dataset.clave]; n++; } });
        msg.textContent = n + ' campo(s) completados. Revíselos antes de generar.'; pintar();
    });
})();
</script>
@endpush
