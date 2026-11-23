@extends('layouts.app')
@section('titulo', 'Panel')
@section('contenido')
<div class="cabecera">
    <div><h1>Panel general</h1><p>Resumen del sistema de expedientes, aprendizaje y asistente de IA local.</p></div>
    <div class="acciones">
        <a class="btn" href="{{ route('importar.index') }}">⇪ Importar Excel</a>
        <a class="btn btn-primario" href="{{ route('expedientes.create') }}">＋ Nuevo expediente</a>
    </div>
</div>

@if (! $ia['disponible'])
    <div class="alerta aviso"><b>Asistente IA no disponible.</b> {{ $ia['mensaje'] }} <a href="{{ route('asistente.index') }}">Ver guía de instalación →</a></div>
@elseif ($ia['advertencias'])
    <div class="alerta info">@foreach ($ia['advertencias'] as $a)<div>ℹ {{ $a }}</div>@endforeach</div>
@endif

<div class="rejilla r4">
    <div class="tarjeta cifra"><div class="n">{{ number_format($stats['total']) }}</div><div class="l">Expedientes registrados</div></div>
    <div class="tarjeta cifra"><div class="n">{{ $tipos->where('total_ejemplos', '>', 0)->count() }}</div><div class="l">Tipos aprendidos</div></div>
    <div class="tarjeta cifra"><div class="n">{{ $formatos }}</div><div class="l">Formatos activos</div></div>
    <div class="tarjeta cifra"><div class="n">{{ number_format($stats['sin_clasificar']) }}</div><div class="l">Sin clasificar @if($stats['sin_clasificar'])<a href="{{ route('expedientes.index', ['tipo' => 'sin']) }}">ver</a>@endif</div></div>
</div>

<div class="rejilla r-2-1">
    <div class="tarjeta">
        <div class="tarjeta-titulo"><h2>Expedientes recientes</h2><a href="{{ route('expedientes.index') }}">Ver todos →</a></div>
        @if ($recientes->isEmpty())
            <div class="vacio"><span class="grande">▤</span>Aún no hay expedientes. <a href="{{ route('importar.index') }}">Importe un Excel</a> o <a href="{{ route('expedientes.create') }}">registre uno</a>.</div>
        @else
            <div class="tabla-wrap"><table>
                <thead><tr><th>Número</th><th>Título</th><th>Tipo</th><th>Estado</th></tr></thead>
                <tbody>@foreach ($recientes as $e)
                    <tr><td class="mono"><a href="{{ route('expedientes.show', $e) }}">{{ $e->numero_expediente ?: '#'.$e->id }}</a></td>
                        <td>{{ \Illuminate\Support\Str::limit($e->titulo, 60) }}</td>
                        <td>@include('partials.tipo', ['tipo' => $e->tipo])</td>
                        <td>@include('partials.estado', ['estado' => $e->estado])</td></tr>
                @endforeach</tbody>
            </table></div>
        @endif
    </div>
    <div>
        <div class="tarjeta">
            <h2>Por estado</h2>
            @php($max = max(1, collect($stats['por_estado'])->max('total')))
            @foreach ($stats['por_estado'] as $est)
                <div class="fila-barra"><span>{{ $est['etiqueta'] }}</span><div class="barra"><span style="width: {{ $est['total'] / $max * 100 }}%"></span></div><b>{{ $est['total'] }}</b></div>
            @endforeach
        </div>
        <div class="tarjeta">
            <div class="tarjeta-titulo"><h2>Tipos más frecuentes</h2><a href="{{ route('tipos.index') }}">Ver →</a></div>
            @forelse ($tipos as $t)
                <div class="fila-barra"><span>@include('partials.tipo', ['tipo' => $t])</span><div class="barra"><span style="width: {{ $t->expedientes_count / max(1, $tipos->max('expedientes_count')) * 100 }}%; background: {{ $t->color }}"></span></div><b>{{ $t->expedientes_count }}</b></div>
            @empty
                <p class="suave">Los tipos se crean al importar un Excel con la columna "Tipo" o al registrar expedientes.</p>
            @endforelse
        </div>
    </div>
</div>

@if ($importaciones->isNotEmpty())
<div class="tarjeta">
    <h2>Últimas importaciones de Excel</h2>
    <table><thead><tr><th>Archivo</th><th>Fecha</th><th>Nuevos</th><th>Actualizados</th><th>Omitidos</th><th>Estado</th></tr></thead><tbody>
    @foreach ($importaciones as $i)
        <tr><td><a href="{{ route('importar.show', $i) }}">{{ $i->archivo_nombre }}</a></td><td>{{ $i->created_at->format('d/m/Y H:i') }}</td>
            <td>{{ $i->importadas }}</td><td>{{ $i->actualizadas }}</td><td>{{ $i->omitidas }}</td><td><span class="chip {{ ['completada' => 'verde', 'con_errores' => 'naranja', 'fallida' => 'rojo'][$i->estado] ?? 'gris' }}">{{ str_replace('_', ' ', $i->estado) }}</span></td></tr>
    @endforeach
    </tbody></table>
</div>
@endif
@endsection
