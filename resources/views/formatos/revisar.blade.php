@extends('layouts.app')
@section('titulo', 'Revisar formato')
@section('contenido')
<div class="cabecera"><div><div class="migas"><a href="{{ route('formatos.index') }}">Formatos</a> /</div><h1>Revisar espacios en blanco</h1>
    <p><b>{{ $a['archivo_nombre'] }}</b> · {{ count($a['campos']) }} datos detectados @if($a['uso_ia'])(reglas + IA)@else(reglas)@endif</p></div>
    <form method="post" action="{{ route('formatos.descartar', $a['token']) }}">@csrf @method('DELETE')<button class="btn">Descartar</button></form></div>
<div class="pasos"><div class="hecho">Cargar documento</div><div class="actual">Revisar espacios en blanco</div><div>Formato listo</div></div>
@foreach ($a['avisos'] as $av)<div class="alerta aviso">{{ $av }}</div>@endforeach
@if ($a['sugerencia'])<div class="alerta info">Tipo sugerido por el aprendizaje con {{ $a['sugerencia']['confianza'] }}% de confianza ({{ $a['sugerencia']['metodo'] }}). Verifíquelo abajo.</div>@endif

<form method="post" action="{{ route('formatos.confirmar', $a['token']) }}" data-carga="Creando la plantilla…" id="form-revisar">
    @csrf
    <div class="rejilla r-1-2">
        <div>
            <div class="tarjeta">
                <div class="campo"><label>Tipo de expediente *</label><select name="tipo_expediente_id" required><option value="">— Seleccione —</option>
                    @foreach ($tipos as $t)<option value="{{ $t->id }}" @selected(old('tipo_expediente_id', $a['tipo_id']) == $t->id)>{{ $t->nombre }}</option>@endforeach</select></div>
                <div class="campo"><label>Nombre del formato</label><input type="text" name="nombre" value="{{ old('nombre', pathinfo($a['archivo_nombre'], PATHINFO_FILENAME)) }}"></div>
                <button class="btn btn-primario">✓ Guardar formato aprendido</button>
            </div>
            <div class="tarjeta">
                <h3>Agregar un dato manualmente</h3>
                <p class="ayuda" style="margin-top:0">Seleccione con el mouse un texto del documento y pulse el botón, o escríbalo.</p>
                <div id="manuales"></div>
                <button type="button" class="btn btn-chico" id="btn-seleccion">＋ Usar texto seleccionado</button>
                <button type="button" class="btn btn-chico" id="btn-manual">＋ Escribir</button>
            </div>
        </div>
        <div class="tarjeta">
            <div class="tarjeta-titulo"><h2>Datos que quedarán en blanco</h2><small>Desmarque lo que sea texto fijo</small></div>
            @forelse ($a['campos'] as $i => $c)
                <div class="campo-fila" data-fila="{{ $i }}">
                    <input type="checkbox" name="campos[{{ $i }}][incluir]" value="1" @checked($c['incluir']) data-check="{{ $i }}">
                    <div class="valor" title="Aparece {{ $c['ocurrencias'] }} vez/veces">{{ $c['valor'] }}@if($c['ocurrencias'] > 1) <small>×{{ $c['ocurrencias'] }}</small>@endif
                        @if ($c['nota'])<br><small style="color:var(--naranja)">⚠ {{ $c['nota'] }}</small>@endif</div>
                    <input type="text" name="campos[{{ $i }}][etiqueta]" value="{{ $c['etiqueta'] }}" title="Etiqueta del espacio en blanco">
                    <select name="campos[{{ $i }}][tipo]">@foreach ($tiposCampo as $v => $l)<option value="{{ $v }}" @selected($c['tipo'] === $v)>{{ $l }}</option>@endforeach</select>
                    <div><small class="chip {{ $c['fuente'] === 'ia' ? 'morado' : 'azul' }}">{{ $c['fuente'] === 'ia' ? 'IA' : 'regla' }}</small> <small class="mono">{{ $c['clave'] }}</small>@if(! empty($c['conocido'])) <small class="chip verde">ya aprendido</small>@endif</div>
                    <input type="hidden" name="campos[{{ $i }}][clave]" value="{{ $c['clave'] }}">
                    <input type="hidden" name="campos[{{ $i }}][valor]" value="{{ $c['valor'] }}">
                    <input type="hidden" name="campos[{{ $i }}][fuente]" value="{{ $c['fuente'] }}">
                </div>
            @empty
                <p class="suave">No se detectaron datos automáticamente. Agréguelos manualmente seleccionando texto del documento.</p>
            @endforelse
        </div>
    </div>
</form>
<div class="tarjeta">
    <h2>Documento <small class="suave">— lo resaltado en amarillo quedará en blanco</small></h2>
    <div class="documento" id="documento">{!! $vistaHtml !!}</div>
</div>
@endsection
@push('scripts')
<script>
(function () {
    const sincronizar = () => document.querySelectorAll('[data-check]').forEach(ch => {
        const i = ch.dataset.check, on = ch.checked;
        document.querySelector('[data-fila="' + i + '"]').classList.toggle('desmarcado', !on);
        document.querySelectorAll('mark[data-campo="' + i + '"]').forEach(m => m.className = on ? 'marca-campo' : '');
    });
    document.querySelectorAll('[data-check]').forEach(ch => ch.addEventListener('change', sincronizar));
    sincronizar();
    document.querySelectorAll('mark[data-campo]').forEach(m => m.addEventListener('click', () => { const ch = document.querySelector('[data-check="' + m.dataset.campo + '"]'); ch.checked = !ch.checked; sincronizar(); }));

    let n = 0;
    const manuales = document.getElementById('manuales');
    function agregar(valor) {
        const d = document.createElement('div'); d.className = 'campo';
        d.innerHTML = '<input type="text" name="manuales[' + n + '][valor]" placeholder="Texto exacto del documento" value="' + esc(valor) + '" style="margin-bottom:4px">'
            + '<input type="text" name="manuales[' + n + '][etiqueta]" placeholder="Etiqueta (ej.: Nombre del testigo)" required>';
        manuales.appendChild(d); d.querySelector('[name$="[etiqueta]"]').focus(); n++;
    }
    document.getElementById('btn-manual').addEventListener('click', () => agregar(''));
    const btnSel = document.getElementById('btn-seleccion');
    btnSel.addEventListener('mousedown', e => e.preventDefault()); // conserva la selección de texto
    btnSel.addEventListener('click', () => {
        const t = String(window.getSelection() || '').trim();
        if (!t) { alert('Primero seleccione con el mouse un texto dentro del documento.'); return; }
        agregar(t);
    });
})();
</script>
@endpush
