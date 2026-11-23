@extends('layouts.app')
@section('titulo', 'Resultado de importación')
@section('contenido')
<div class="cabecera">
    <div><div class="migas"><a href="{{ route('importar.index') }}">Importar</a> /</div>
        <h1>Resultado de la importación</h1><p>{{ $imp->archivo_nombre }} · {{ $imp->created_at->format('d/m/Y H:i') }}</p></div>
    <div class="acciones"><a class="btn btn-primario" href="{{ route('expedientes.index', ['importacion' => $imp->id]) }}">Ver expedientes importados</a></div>
</div>
<div class="pasos"><div class="hecho">Cargar archivo</div><div class="hecho">Revisar columnas</div><div class="hecho">Importar y aprender</div></div>
<div class="rejilla r4">
    <div class="tarjeta cifra"><div class="n">{{ $imp->total_filas }}</div><div class="l">Filas leídas</div></div>
    <div class="tarjeta cifra"><div class="n" style="color:var(--verde)">{{ $imp->importadas }}</div><div class="l">Nuevos</div></div>
    <div class="tarjeta cifra"><div class="n">{{ $imp->actualizadas }}</div><div class="l">Actualizados</div></div>
    <div class="tarjeta cifra"><div class="n" style="color:var(--rojo)">{{ $imp->omitidas }}</div><div class="l">Omitidos</div></div>
</div>
<div class="rejilla r2">
    <div class="tarjeta">
        <h2>Expedientes por tipo</h2>
        @forelse ($porTipo as $nombre => $n)
            <div class="fila-barra"><span>{{ $nombre }}</span><div class="barra"><span style="width: {{ $n / max(1, $porTipo->max()) * 100 }}%"></span></div><b>{{ $n }}</b></div>
        @empty <p class="suave">Sin expedientes.</p> @endforelse
        <p class="ayuda">El aprendizaje de tipos se actualizó con estos ejemplos. <a href="{{ route('tipos.index') }}">Ver lo aprendido →</a></p>
    </div>
    <div class="tarjeta">
        <h2>Observaciones</h2>
        @forelse ((array) $imp->errores as $err)
            <div class="alerta {{ ! empty($err['info']) ? 'info' : 'aviso' }}" style="margin-bottom:6px;padding:7px 10px">@if(empty($err['info']))<b>Fila {{ $err['fila'] }}:</b> @endif{{ $err['error'] }}</div>
        @empty <p class="suave">Sin observaciones.</p> @endforelse
    </div>
</div>
<div class="tarjeta"><h3>Columnas utilizadas</h3>
    <div class="palabras">@foreach ((array) $imp->mapeo as $campo => $col)<span class="chip azul">{{ \App\Application\Services\ImportacionExcelService::CAMPOS[$campo] ?? $campo }} ← {{ $col }}</span>@endforeach</div></div>
@endsection
