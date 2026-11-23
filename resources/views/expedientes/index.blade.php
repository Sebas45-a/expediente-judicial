@extends('layouts.app')
@section('titulo', 'Expedientes')
@section('contenido')
<div class="cabecera">
    <div><h1>Expedientes</h1><p>{{ number_format($expedientes->total()) }} registros</p></div>
    <div class="acciones">
        <a class="btn" href="{{ route('expedientes.exportar', request()->query()) }}">⇩ Exportar a Excel</a>
        <a class="btn" href="{{ route('importar.index') }}">⇪ Importar</a>
        <a class="btn btn-primario" href="{{ route('expedientes.create') }}">＋ Nuevo</a>
    </div>
</div>

<form class="tarjeta filtros" method="get">
    <div><label>Buscar</label><input type="search" name="buscar" value="{{ $filtros['buscar'] ?? '' }}" placeholder="Número, título, partes, juzgado…"></div>
    <div><label>Tipo</label><select name="tipo"><option value="">Todos</option><option value="sin" @selected(($filtros['tipo'] ?? '') === 'sin')>— Sin clasificar —</option>
        @foreach ($tipos as $t)<option value="{{ $t->id }}" @selected(($filtros['tipo'] ?? '') == $t->id)>{{ $t->nombre }}</option>@endforeach</select></div>
    <div><label>Estado</label><select name="estado"><option value="">Todos</option>
        @foreach ($estados as $v => $l)<option value="{{ $v }}" @selected(($filtros['estado'] ?? '') === $v)>{{ $l }}</option>@endforeach</select></div>
    <div><label>Origen</label><select name="origen"><option value="">Todos</option>
        @foreach (\App\Domain\Enums\OrigenExpediente::cases() as $o)<option value="{{ $o->value }}" @selected(($filtros['origen'] ?? '') === $o->value)>{{ $o->etiqueta() }}</option>@endforeach</select></div>
    <div class="acciones"><button class="btn btn-primario">Filtrar</button>@if(array_filter($filtros))<a class="btn" href="{{ route('expedientes.index') }}">Limpiar</a>@endif</div>
</form>

<div class="tarjeta">
    @if ($expedientes->isEmpty())
        <div class="vacio"><span class="grande">▤</span>No se encontraron expedientes.</div>
    @else
    <div class="tabla-wrap"><table>
        <thead><tr><th>Número</th><th>Título</th><th>Tipo</th><th>Partes</th><th>Juzgado</th><th>Inicio</th><th>Estado</th></tr></thead>
        <tbody>
        @foreach ($expedientes as $e)
            <tr>
                <td class="mono"><a href="{{ route('expedientes.show', $e) }}">{{ $e->numero_expediente ?: '#'.$e->id }}</a></td>
                <td><a href="{{ route('expedientes.show', $e) }}">{{ \Illuminate\Support\Str::limit($e->titulo, 55) }}</a>
                    @if ($e->archivo_ruta)<span class="chip gris" title="Tiene documento">{{ strtoupper($e->formato_archivo) }}</span>@endif</td>
                <td>@include('partials.tipo', ['tipo' => $e->tipo])
                    @if ($e->clasificado_por && ! in_array($e->clasificado_por, ['manual', 'excel']))<br><small title="Clasificado automáticamente">auto · {{ $e->clasificacion_confianza }}%</small>@endif</td>
                <td><small>{{ \Illuminate\Support\Str::limit($e->demandante, 30) }}@if($e->demandado)<br>vs. {{ \Illuminate\Support\Str::limit($e->demandado, 30) }}@endif</small></td>
                <td><small>{{ \Illuminate\Support\Str::limit($e->juzgado, 40) }}</small></td>
                <td>{{ $e->fecha_inicio?->format('d/m/Y') }}</td>
                <td>@include('partials.estado', ['estado' => $e->estado])</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    {{ $expedientes->links() }}
    @endif
</div>
@endsection
