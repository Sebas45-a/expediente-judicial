@extends('layouts.app')
@section('titulo', 'Aprender formato')
@section('contenido')
<div class="cabecera"><div><div class="migas"><a href="{{ route('formatos.index') }}">Formatos</a> /</div><h1>Aprender un formato</h1>
    <p>Suba un expediente ya elaborado. El texto fijo se conserva; los datos que cambian (partes, fechas, números, montos…) se convierten en espacios en blanco.</p></div></div>
<div class="pasos"><div class="actual">Cargar documento</div><div>Revisar espacios en blanco</div><div>Formato listo</div></div>
<div class="rejilla r-2-1">
    <form class="tarjeta" method="post" action="{{ route('formatos.analizar') }}" enctype="multipart/form-data" data-carga="Analizando el formato del documento…">
        @csrf
        <div class="campo"><label>Documento de ejemplo *</label><input type="file" name="documento" accept=".docx,.pdf,.xlsx,.xls,.csv,.txt" required>
            <div class="ayuda"><b>Word (.docx)</b> conserva fuentes, márgenes, encabezados y tablas. PDF/Excel/TXT se reconstruyen como Word.</div></div>
        <div class="rejilla r2">
            <div class="campo"><label>Tipo de expediente</label><select name="tipo_expediente_id"><option value="">— Detectar automáticamente —</option>
                @foreach ($tipos as $t)<option value="{{ $t->id }}" @selected($tipoId == $t->id)>{{ $t->nombre }}</option>@endforeach</select></div>
            <div class="campo"><label>…o tipo nuevo</label><input type="text" name="tipo_nuevo" placeholder="Ej.: Sentencia penal"></div>
        </div>
        <label class="check"><input type="hidden" name="usar_ia" value="0"><input type="checkbox" name="usar_ia" value="1" checked> Usar también la IA (Ollama) para encontrar datos que las reglas no detecten</label>
        <p class="ayuda">Los valores que proponga la IA se verifican contra el documento: si no aparecen exactamente, se descartan.</p>
        <button class="btn btn-primario" style="margin-top:8px">Analizar documento →</button>
    </form>
    <div class="tarjeta">
        <h3>¿Qué se deja en blanco?</h3>
        <ul style="padding-left:18px;margin:0;font-size:.9rem">
            <li>Número de expediente / causa</li><li>Juzgado o tribunal</li><li>Nombres de demandante, demandado, sindicado, agraviado, juez, abogado…</li>
            <li>Fechas y horas (en números o en letras)</li><li>DPI, NIT, número de colegiado</li><li>Montos (Q., $) en números y en letras</li>
            <li>Direcciones, teléfonos y correos</li><li>Líneas tipo formulario «Etiqueta: valor»</li>
        </ul>
        <p class="ayuda">Al subir otra muestra del mismo tipo, el sistema compara: si un dato es igual al de la muestra anterior lo propone como texto fijo.</p>
    </div>
</div>
@endsection
