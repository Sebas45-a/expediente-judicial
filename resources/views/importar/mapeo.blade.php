@extends('layouts.app')
@section('titulo', 'Revisar columnas')
@section('contenido')
<div class="cabecera">
    <div><div class="migas"><a href="{{ route('importar.index') }}">Importar</a> /</div>
        <h1>Revisar columnas</h1><p><b>{{ $importacion->archivo_nombre }}</b> · hoja «{{ $importacion->hoja }}» · {{ number_format($total) }} filas de datos</p></div>
</div>
<div class="pasos"><div class="hecho">Cargar archivo</div><div class="actual">Revisar columnas</div><div>Importar y aprender</div></div>

<form method="post" action="{{ route('importar.ejecutar', $importacion) }}" data-carga="Importando expedientes y entrenando el aprendizaje… (puede tardar con archivos grandes)">
    @csrf
    <div class="rejilla r-2-1">
        <div class="tarjeta">
            <h2>2. ¿Qué columna corresponde a cada dato?</h2>
            <p class="suave">Se propuso una asignación automática. Corrija lo que sea necesario.</p>
            <table><thead><tr><th>Dato del sistema</th><th>Columna del Excel</th></tr></thead><tbody>
            @foreach ($campos as $campo => $etiqueta)
                <tr><td><b>{{ $etiqueta }}</b>@if ($campo === 'tipo')<br><small>Clave para el aprendizaje de tipos</small>@endif</td>
                    <td><select name="mapeo[{{ $campo }}]"><option value="">— No importar —</option>
                        @foreach ($encabezados as $h)<option value="{{ $h }}" @selected(old("mapeo.$campo", $mapeo[$campo] ?? null) === $h)>{{ $h }}</option>@endforeach
                    </select></td></tr>
            @endforeach
            </tbody></table>
            <p class="ayuda">Las columnas no asignadas se guardan igualmente como «datos extra» de cada expediente.</p>
        </div>
        <div>
            <div class="tarjeta">
                <h2>Opciones</h2>
                <div class="campo"><label>Si el número de expediente ya existe</label>
                    <select name="duplicados"><option value="actualizar">Actualizar el existente</option><option value="omitir">Omitir la fila</option></select></div>
                <div class="campo"><label>Tipo para filas sin tipo</label>
                    <select name="tipo_defecto"><option value="">— Clasificar automáticamente —</option>
                        @foreach ($tipos as $t)<option value="{{ $t->id }}">{{ $t->nombre }}</option>@endforeach</select></div>
                <label class="check"><input type="checkbox" name="crear_tipos" value="1" checked> Crear los tipos nuevos que aparezcan en el Excel</label>
                <label class="check"><input type="checkbox" name="clasificar" value="1" checked> Clasificar con lo aprendido las filas sin tipo</label>
                <label class="check"><input type="checkbox" name="usar_ia" value="1"> Consultar a la IA cuando el aprendizaje dude (más lento)</label>
            </div>
            <div class="acciones"><button class="btn btn-primario">Importar {{ number_format($total) }} filas →</button><a class="btn" href="{{ route('importar.index') }}">Cancelar</a></div>
        </div>
    </div>
</form>

<div class="tarjeta">
    <h2>Vista previa (primeras {{ count($muestra) }} filas)</h2>
    <div class="tabla-wrap"><table class="tabla-bd"><thead><tr><th>Fila</th>@foreach ($encabezados as $h)<th>{{ $h }}</th>@endforeach</tr></thead><tbody>
    @foreach ($muestra as $f)<tr><td>{{ $f['__fila'] }}</td>@foreach ($encabezados as $h)<td title="{{ $f[$h] }}">{{ \Illuminate\Support\Str::limit((string) $f[$h], 40) }}</td>@endforeach</tr>@endforeach
    </tbody></table></div>
</div>
@endsection
