@extends('layouts.app')
@section('titulo', $p->nombre)
@section('contenido')
<div class="cabecera">
    <div><div class="migas"><a href="{{ route('formatos.index') }}">Formatos</a> /</div><h1>{{ $p->nombre }}</h1>
        <p>@include('partials.tipo', ['tipo' => $p->tipo]) · versión {{ $p->version }} · {{ $p->muestras_analizadas }} muestra(s) · {{ $p->documentos_count }} documentos generados @if($p->activa)<span class="chip verde">activo</span>@endif</p></div>
    <div class="acciones">
        @unless ($p->activa)<form class="inline" method="post" action="{{ route('formatos.activar', $p) }}">@csrf<button class="btn">Activar esta versión</button></form>@endunless
        <a class="btn" href="{{ route('formatos.descargar', [$p, 'plantilla']) }}">⇩ Plantilla .docx</a>
        <a class="btn" href="{{ route('formatos.blanco', $p) }}">⇩ Formato en blanco</a>
        <a class="btn btn-primario" href="{{ route('formatos.usar', $p) }}">Completar formato →</a>
    </div>
</div>
<div class="rejilla r-1-2">
    <form class="tarjeta" method="post" action="{{ route('formatos.campos', $p) }}">@csrf @method('PUT')
        <div class="tarjeta-titulo"><h2>Espacios en blanco ({{ $p->campos->count() }})</h2><button class="btn btn-chico">Guardar</button></div>
        <table><thead><tr><th>Etiqueta</th><th>Tipo</th><th title="Obligatorio">Oblig.</th></tr></thead><tbody>
        @foreach ($p->campos as $c)
            <tr><td><input type="text" name="campos[{{ $c->id }}][etiqueta]" value="{{ $c->etiqueta }}"><small class="mono suave">{{ $c->clave }} · ej.: {{ \Illuminate\Support\Str::limit($c->valor_ejemplo, 40) }}</small></td>
                <td><select name="campos[{{ $c->id }}][tipo_dato]">@foreach ($tiposCampo as $v => $l)<option value="{{ $v }}" @selected($c->tipo_dato->value === $v)>{{ $l }}</option>@endforeach</select></td>
                <td><input type="checkbox" name="campos[{{ $c->id }}][obligatorio]" value="1" @checked($c->obligatorio)><input type="hidden" name="campos[{{ $c->id }}][orden]" value="{{ $c->orden }}"></td></tr>
        @endforeach
        </tbody></table>
        <p class="ayuda">Original: <a href="{{ route('formatos.descargar', [$p, 'original']) }}">{{ $p->archivo_original_nombre }}</a></p>
    </form>
    <div class="tarjeta">
        <h2>Vista del formato</h2>
        <div class="documento">{!! preg_replace('/_{10,}/', '<span class="blanco">______</span>', e($vista)) !!}</div>
    </div>
</div>
<form method="post" action="{{ route('formatos.destroy', $p) }}" data-confirmar="¿Eliminar este formato y sus documentos generados?">@csrf @method('DELETE')<button class="btn btn-peligro btn-chico">Eliminar formato</button></form>
@endsection
