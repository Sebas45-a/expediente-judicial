@extends('layouts.app')
@section('titulo', 'Tipos de expediente')
@section('contenido')
<div class="cabecera">
    <div><h1>Aprendizaje de tipos de expediente</h1>
        <p>Cada tipo aprende sus palabras características y los datos que suele tener a partir de los expedientes registrados o importados.</p></div>
    <div class="acciones">
        <form class="inline" method="post" action="{{ route('tipos.entrenar') }}" data-carga="Entrenando con todos los expedientes…">@csrf<button class="btn">↻ Reentrenar</button></form>
        <form class="inline" method="post" action="{{ route('tipos.reclasificar') }}" data-carga="Clasificando expedientes sin tipo…">@csrf<button class="btn btn-primario">◈ Clasificar los «sin tipo»</button></form>
    </div>
</div>

<div class="rejilla r-2-1">
    <div class="tarjeta">
        @if ($tipos->isEmpty())
            <div class="vacio"><span class="grande">◈</span>No hay tipos todavía. Se crean al importar un Excel con columna de tipo, o aquí a la derecha.</div>
        @else
        <table><thead><tr><th>Tipo</th><th>Ejemplos</th><th>Palabras que lo identifican</th><th>Formatos</th></tr></thead><tbody>
        @foreach ($tipos as $t)
            <tr><td><a href="{{ route('tipos.show', $t) }}">@include('partials.tipo', ['tipo' => $t])</a>@if($t->materia)<br><small>{{ $t->materia }}</small>@endif</td>
                <td><b>{{ $t->expedientes_count }}</b>@if($t->total_ejemplos !== $t->expedientes_count)<br><small>{{ $t->total_ejemplos }} para aprender</small>@endif</td>
                <td><div class="palabras">@forelse (array_slice((array) $t->palabras_clave, 0, 7) as $p)<span class="chip">{{ $p }}</span>@empty<small class="suave">Aún sin aprender</small>@endforelse</div></td>
                <td>{{ $t->plantillas_count }}</td></tr>
        @endforeach</tbody></table>
        @php($precision = $tipos->first()?->precision_entrenamiento)
        <p class="ayuda">Último entrenamiento: {{ $tipos->max('entrenado_en')?->format('d/m/Y H:i') ?? 'nunca' }}@if($precision !== null) · precisión estimada (validación cruzada): <b>{{ $precision }}%</b>@endif</p>
        @endif
    </div>
    <div>
        <div class="tarjeta">
            <h2>Probar el clasificador</h2>
            <form method="post" action="{{ route('tipos.probar') }}" id="form-probar">@csrf
                <textarea name="texto" placeholder="Pegue el texto o el asunto de un expediente…" required>{{ old('texto') }}</textarea>
                <label class="check" style="margin:8px 0"><input type="checkbox" name="usar_ia" value="1"> Consultar a la IA si hay duda</label>
                <button class="btn btn-primario">Clasificar</button>
            </form>
            <div id="resultado-prueba" style="margin-top:12px">
                @if ($p = session('prueba'))
                    @include('tipos._prueba', ['p' => $p])
                @endif
            </div>
        </div>
        <div class="tarjeta">
            <h2>Nuevo tipo</h2>
            <form method="post" action="{{ route('tipos.store') }}">@csrf
                <div class="campo"><label>Nombre</label><input type="text" name="nombre" required placeholder="Ej.: Juicio Ejecutivo"></div>
                <div class="campo"><label>Materia</label><input type="text" name="materia" placeholder="Civil, Penal, Familia, Laboral…"></div>
                <div class="campo"><label>Descripción (ayuda a la IA a clasificar)</label><textarea name="descripcion" style="min-height:60px"></textarea></div>
                <button class="btn">Crear tipo</button>
            </form>
        </div>
    </div>
</div>
@endsection
@push('scripts')
<script>
document.getElementById('form-probar').addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target, caja = document.getElementById('resultado-prueba');
    caja.innerHTML = '<p class="suave escribiendo">Clasificando</p>';
    const r = await api(f.action, { texto: f.texto.value, usar_ia: f.usar_ia.checked });
    if (!r.ok) { caja.innerHTML = '<div class="alerta error">' + esc(r.error) + '</div>'; return; }
    if (!r.tipo) { caja.innerHTML = '<div class="alerta aviso">No hay suficiente aprendizaje para clasificar este texto.</div>'; return; }
    caja.innerHTML = '<div class="alerta ' + (r.confiable ? 'ok' : 'aviso') + '"><b>' + esc(r.tipo) + '</b> · ' + r.confianza + '% (' + esc(r.metodo) + ')' + (r.confiable ? '' : '<br><small>Confianza baja: se recomienda revisar.</small>') + '</div>'
        + r.alternativas.map(a => '<div class="fila-barra"><span>' + esc(a.tipo) + '</span><div class="barra"><span style="width:' + a.probabilidad + '%"></span></div><b>' + Math.round(a.probabilidad) + '%</b></div>').join('');
});
</script>
@endpush
