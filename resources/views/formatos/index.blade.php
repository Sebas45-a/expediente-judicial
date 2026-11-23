@extends('layouts.app')
@section('titulo', 'Formatos aprendidos')
@section('contenido')
<div class="cabecera">
    <div><h1>Aprendizaje de formato de expedientes</h1>
        <p>Cargue un expediente real de cada tipo: el sistema aprende su formato y deja en blanco solo los datos importantes.</p></div>
    <a class="btn btn-primario" href="{{ route('formatos.create') }}">＋ Aprender un formato</a>
</div>
<form method="get" class="acciones" style="margin-bottom:14px">
    <select name="tipo" style="max-width:280px" onchange="this.form.submit()"><option value="">Todos los tipos</option>
        @foreach ($tipos as $t)<option value="{{ $t->id }}" @selected($tipoFiltro == $t->id)>{{ $t->nombre }}</option>@endforeach</select>
</form>
@if ($plantillas->isEmpty())
    <div class="tarjeta vacio"><span class="grande">▦</span>Todavía no hay formatos. <a href="{{ route('formatos.create') }}">Cargue un documento de ejemplo</a> (Word recomendado: se conserva el diseño original).</div>
@else
<div class="rejilla r3">
    @foreach ($plantillas as $p)
    <div class="tarjeta">
        <div class="tarjeta-titulo"><h3><a href="{{ route('formatos.show', $p) }}">{{ $p->nombre }}</a></h3>@if($p->activa)<span class="chip verde">activo</span>@else<span class="chip gris">v{{ $p->version }}</span>@endif</div>
        <p style="margin:0 0 8px">@include('partials.tipo', ['tipo' => $p->tipo])</p>
        <p class="suave" style="margin:0 0 12px;font-size:.85rem">Versión {{ $p->version }} · {{ $p->campos_count }} espacios en blanco · {{ $p->muestras_analizadas }} muestra(s) · origen {{ strtoupper($p->formato_origen) }}@if($p->uso_ia) · con IA @endif<br>{{ $p->documentos_count }} documentos generados</p>
        <div class="acciones">
            <a class="btn btn-primario btn-chico" href="{{ route('formatos.usar', $p) }}">Completar</a>
            <a class="btn btn-chico" href="{{ route('formatos.blanco', $p) }}">⇩ En blanco</a>
            <a class="btn btn-chico" href="{{ route('formatos.show', $p) }}">Detalle</a>
        </div>
    </div>
    @endforeach
</div>
@endif
@endsection
