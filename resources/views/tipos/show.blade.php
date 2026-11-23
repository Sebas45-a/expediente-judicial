@extends('layouts.app')
@section('titulo', $tipo->nombre)
@section('contenido')
<div class="cabecera">
    <div><div class="migas"><a href="{{ route('tipos.index') }}">Tipos de expediente</a> /</div>
        <h1>@include('partials.tipo', ['tipo' => $tipo])</h1><p>{{ $tipo->materia }} {{ $tipo->descripcion ? '· '.$tipo->descripcion : '' }}</p></div>
    <div class="acciones">
        <a class="btn" href="{{ route('expedientes.index', ['tipo' => $tipo->id]) }}">Ver sus {{ $tipo->expedientes_count }} expedientes</a>
        <a class="btn btn-primario" href="{{ route('formatos.create', ['tipo' => $tipo->id]) }}">▦ Enseñar formato</a>
    </div>
</div>
<div class="rejilla r3">
    <div class="tarjeta cifra"><div class="n">{{ $tipo->total_ejemplos }}</div><div class="l">Ejemplos aprendidos</div></div>
    <div class="tarjeta cifra"><div class="n">{{ $tipo->perfil['terminos'] ?? 0 }}</div><div class="l">Términos analizados</div></div>
    <div class="tarjeta cifra"><div class="n">{{ $plantillas->count() }}</div><div class="l">Versiones de formato</div></div>
</div>
<div class="rejilla r2">
    <div class="tarjeta">
        <h2>Lo que el sistema aprendió</h2>
        <h3>Palabras que lo distinguen de otros tipos</h3>
        <div class="palabras" style="margin-bottom:14px">@forelse ((array) $tipo->palabras_clave as $p)<span class="chip azul">{{ $p }}</span>@empty<span class="suave">Necesita ejemplos. Reentrene después de registrar expedientes.</span>@endforelse</div>
        @php($cf = (array) $tipo->campos_frecuentes)
        @if (! empty($cf['columnas']))
            <h3>Datos que suele traer</h3>
            @foreach ($cf['columnas'] as $col => $pct)<div class="fila-barra"><span>{{ $col }}</span><div class="barra"><span style="width: {{ $pct }}%"></span></div><b>{{ $pct }}%</b></div>@endforeach
        @endif
        @if (! empty($cf['juzgados']))
            <h3>Juzgados frecuentes</h3><ul style="margin-top:0">@foreach ($cf['juzgados'] as $j => $n)<li>{{ $j }} <small>({{ $n }})</small></li>@endforeach</ul>
        @endif
        @if (! empty($cf['estados']))
            <h3>Estados</h3><div class="palabras">@foreach ($cf['estados'] as $e => $n)<span class="chip">{{ $e }}: {{ $n }}</span>@endforeach</div>
        @endif
    </div>
    <div>
        <div class="tarjeta">
            <h2>Formatos aprendidos</h2>
            @forelse ($plantillas as $p)
                <div class="acciones" style="justify-content:space-between;margin-bottom:8px"><span><a href="{{ route('formatos.show', $p) }}">{{ $p->nombre }}</a> <small>v{{ $p->version }} · {{ $p->campos_count }} campos</small> @if($p->activa)<span class="chip verde">activo</span>@endif</span>
                    <a class="btn btn-chico" href="{{ route('formatos.usar', $p) }}">Usar</a></div>
            @empty <p class="suave">Sin formatos. <a href="{{ route('formatos.create', ['tipo' => $tipo->id]) }}">Cargue un documento de ejemplo</a>.</p> @endforelse
        </div>
        <div class="tarjeta">
            <h2>Editar</h2>
            <form method="post" action="{{ route('tipos.update', $tipo) }}">@csrf @method('PUT')
                <div class="rejilla r2"><div class="campo"><label>Nombre</label><input type="text" name="nombre" value="{{ $tipo->nombre }}" required></div>
                    <div class="campo"><label>Materia</label><input type="text" name="materia" value="{{ $tipo->materia }}"></div></div>
                <div class="campo"><label>Descripción</label><textarea name="descripcion" style="min-height:60px">{{ $tipo->descripcion }}</textarea></div>
                <div class="campo"><label>Color</label><input type="text" name="color" value="{{ $tipo->color }}" style="max-width:120px"></div>
                <button class="btn">Guardar</button>
            </form>
            @if ($otros->isNotEmpty())
            <hr style="border:0;border-top:1px solid var(--borde);margin:16px 0">
            <form method="post" action="{{ route('tipos.fusionar', $tipo) }}" data-confirmar="Todos los expedientes y formatos pasarán al tipo elegido y este se eliminará. ¿Continuar?">@csrf
                <label>Fusionar dentro de otro tipo (duplicados como «Pensión» y «Pensión alimenticia»)</label>
                <div class="acciones"><select name="destino_id" style="max-width:260px">@foreach ($otros as $o)<option value="{{ $o->id }}">{{ $o->nombre }}</option>@endforeach</select><button class="btn">Fusionar</button></div>
            </form>
            @endif
            <form method="post" action="{{ route('tipos.destroy', $tipo) }}" data-confirmar="¿Eliminar el tipo? Sus expedientes quedarán sin clasificar." style="margin-top:12px">@csrf @method('DELETE')<button class="btn btn-peligro btn-chico">Eliminar tipo</button></form>
        </div>
    </div>
</div>
<div class="tarjeta"><h2>Últimos ejemplos</h2>
    <table><tbody>@foreach ($ejemplos as $e)<tr><td class="mono"><a href="{{ route('expedientes.show', $e) }}">{{ $e->numero_expediente ?: '#'.$e->id }}</a></td><td>{{ $e->titulo }}</td><td>@include('partials.estado', ['estado' => $e->estado])</td></tr>@endforeach</tbody></table></div>
@endsection
