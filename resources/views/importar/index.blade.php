@extends('layouts.app')
@section('titulo', 'Importar desde Excel')
@section('contenido')
<div class="cabecera">
    <div><h1>Importar expedientes desde Excel</h1><p>Cargue un .xlsx, .xls o .csv. El sistema reconoce las columnas, crea los expedientes y aprende cada tipo.</p></div>
    <a class="btn" href="{{ route('importar.plantilla') }}">⇩ Descargar Excel de ejemplo</a>
</div>
<div class="pasos"><div class="actual">Cargar archivo</div><div>Revisar columnas</div><div>Importar y aprender</div></div>

<div class="rejilla r-2-1">
    <form class="tarjeta" method="post" action="{{ route('importar.previsualizar') }}" enctype="multipart/form-data" data-carga="Leyendo el archivo de Excel…">
        @csrf
        <h2>1. Seleccione el archivo</h2>
        <div class="campo"><input type="file" name="archivo" accept=".xlsx,.xls,.csv" required></div>
        <div class="campo"><label>Hoja (opcional)</label><input type="text" name="hoja" placeholder="Vacío = primera hoja"></div>
        <button class="btn btn-primario">Continuar →</button>
    </form>
    <div class="tarjeta">
        <h3>Columnas que se reconocen automáticamente</h3>
        <ul style="margin:0;padding-left:18px;font-size:.9rem">
            @foreach ($campos as $c => $l)<li>{{ $l }}</li>@endforeach
        </ul>
        <p class="ayuda">Los encabezados pueden tener otros nombres ("No. de causa", "Actor", "Sindicado", "Situación"…). Las columnas adicionales se guardan como datos extra del expediente y también sirven para el aprendizaje.</p>
    </div>
</div>

<div class="tarjeta">
    <h2>Historial de importaciones</h2>
    @if ($historial->isEmpty())<p class="suave">Aún no se ha importado ningún archivo.</p>@else
    <table><thead><tr><th>Archivo</th><th>Fecha</th><th>Filas</th><th>Nuevos</th><th>Actualizados</th><th>Omitidos</th><th>Estado</th></tr></thead><tbody>
    @foreach ($historial as $i)
        <tr><td><a href="{{ $i->estado === 'pendiente' ? route('importar.mapeo', $i) : route('importar.show', $i) }}">{{ $i->archivo_nombre }}</a></td><td>{{ $i->created_at->format('d/m/Y H:i') }}</td><td>{{ $i->total_filas }}</td>
            <td>{{ $i->importadas }}</td><td>{{ $i->actualizadas }}</td><td>{{ $i->omitidas }}</td>
            <td><span class="chip {{ ['completada' => 'verde', 'con_errores' => 'naranja', 'fallida' => 'rojo', 'pendiente' => 'azul'][$i->estado] ?? 'gris' }}">{{ str_replace('_', ' ', $i->estado) }}</span></td></tr>
    @endforeach</tbody></table>@endif
</div>
@endsection
