@extends('layouts.app')
@section('titulo', $expediente->exists ? 'Editar expediente' : 'Nuevo expediente')
@section('contenido')
<div class="cabecera">
    <div><div class="migas"><a href="{{ route('expedientes.index') }}">Expedientes</a> /</div>
        <h1>{{ $expediente->exists ? 'Editar expediente' : 'Nuevo expediente' }}</h1>
        <p>Puede adjuntar el documento (Word, PDF o Excel): el sistema extrae el texto, completa número, juzgado y partes, y sugiere el tipo.</p></div>
</div>
<form method="post" enctype="multipart/form-data" action="{{ $expediente->exists ? route('expedientes.update', $expediente) : route('expedientes.store') }}" data-carga="Guardando y analizando el documento…">
    @csrf @if ($expediente->exists) @method('PUT') @endif
    <div class="rejilla r-2-1">
        <div class="tarjeta">
            <div class="rejilla r2">
                <div class="campo"><label>Número de expediente</label><input type="text" name="numero_expediente" value="{{ old('numero_expediente', $expediente->numero_expediente) }}" placeholder="01043-2024-00321"></div>
                <div class="campo"><label>Fecha de inicio</label><input type="date" name="fecha_inicio" value="{{ old('fecha_inicio', $expediente->fecha_inicio?->format('Y-m-d')) }}"></div>
            </div>
            <div class="campo"><label>Título / asunto *</label><input type="text" name="titulo" value="{{ old('titulo', $expediente->titulo) }}" placeholder="Juicio ordinario de daños y perjuicios"></div>
            <div class="campo"><label>Juzgado / tribunal</label><input type="text" name="juzgado" value="{{ old('juzgado', $expediente->juzgado) }}"></div>
            <div class="rejilla r2">
                <div class="campo"><label>Demandante / actor / agraviado</label><input type="text" name="demandante" value="{{ old('demandante', $expediente->demandante) }}"></div>
                <div class="campo"><label>Demandado / sindicado</label><input type="text" name="demandado" value="{{ old('demandado', $expediente->demandado) }}"></div>
            </div>
            <div class="campo"><label>Descripción / resumen</label><textarea name="descripcion">{{ old('descripcion', $expediente->descripcion) }}</textarea></div>
            <details @if(old('contenido_texto', $expediente->contenido_texto)) open @endif>
                <summary>Contenido completo del expediente (texto)</summary>
                <textarea name="contenido_texto" style="min-height: 240px; margin-top: 8px">{{ old('contenido_texto', $expediente->contenido_texto) }}</textarea>
                <div class="ayuda">Se llena automáticamente si adjunta un documento.</div>
            </details>
        </div>
        <div>
            <div class="tarjeta">
                <div class="campo"><label>Tipo de expediente</label>
                    <select name="tipo_expediente_id"><option value="">— Detectar automáticamente —</option>
                        @foreach ($tipos as $t)<option value="{{ $t->id }}" @selected(old('tipo_expediente_id', $expediente->tipo_expediente_id) == $t->id)>{{ $t->nombre }}</option>@endforeach</select>
                    <div class="ayuda">Si lo deja vacío, el sistema lo clasifica con lo aprendido.</div></div>
                <div class="campo"><label>…o crear un tipo nuevo</label><input type="text" name="tipo_nuevo" value="{{ old('tipo_nuevo') }}" placeholder="Ej.: Amparo"></div>
                <div class="campo"><label>Estado *</label><select name="estado">
                    @foreach ($estados as $v => $l)<option value="{{ $v }}" @selected(old('estado', $expediente->estado?->value) === $v)>{{ $l }}</option>@endforeach</select></div>
            </div>
            <div class="tarjeta">
                <div class="campo"><label>Documento del expediente</label><input type="file" name="archivo" accept=".docx,.pdf,.xlsx,.xls,.csv,.txt">
                    <div class="ayuda">Word (.docx), PDF, Excel o TXT · máx. 30 MB.@if($expediente->archivo_nombre)<br>Actual: <b>{{ $expediente->archivo_nombre }}</b> (se reemplaza si sube otro).@endif</div></div>
                @unless ($expediente->exists)
                <label class="check"><input type="checkbox" name="usar_ia" value="1"> Si el aprendizaje no está seguro del tipo, consultar a la IA (Ollama)</label>
                @endunless
            </div>
            <div class="acciones"><button class="btn btn-primario">Guardar expediente</button><a class="btn" href="{{ url()->previous() }}">Cancelar</a></div>
        </div>
    </div>
</form>
@endsection
