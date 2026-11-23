@extends('layouts.app')
@section('titulo', $tabla)
@section('contenido')
<div class="cabecera">
    <div><div class="migas"><a href="{{ route('bd.index') }}">Base de datos</a> /</div><h1 class="mono">{{ $tabla }}</h1><p>{{ $nombre }} · {{ number_format($filas->total()) }} registros · solo lectura</p></div>
    <form method="get" class="acciones"><input type="search" name="buscar" value="{{ $buscar }}" placeholder="Buscar en todas las columnas" style="width:280px"><button class="btn">Buscar</button></form>
</div>
<div class="tarjeta">
    <div class="tabla-wrap"><table class="tabla-bd">
        <thead><tr>@foreach ($columnas as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse ($filas as $f)<tr>@foreach ($columnas as $c)<td title="{{ $f->$c }}">{{ $f->$c === null ? 'NULL' : \Illuminate\Support\Str::limit((string) $f->$c, 60) }}</td>@endforeach</tr>
        @empty<tr><td colspan="{{ count($columnas) }}" class="vacio">Sin registros.</td></tr>@endforelse
        </tbody>
    </table></div>
    {{ $filas->links() }}
</div>
@endsection
