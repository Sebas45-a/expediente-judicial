@extends('layouts.app')
@section('titulo', 'Base de datos')
@section('contenido')
<div class="cabecera">
    <div><h1>Base de datos</h1><p>Información, explorador de tablas, respaldo y mantenimiento.</p></div>
    <div class="acciones">
        <a class="btn btn-primario" href="{{ route('bd.respaldo') }}">⇩ Descargar respaldo</a>
        <form class="inline" method="post" action="{{ route('bd.optimizar') }}" data-carga="Optimizando…">@csrf<button class="btn">⚙ Optimizar</button></form>
    </div>
</div>
<div class="rejilla r4">
    <div class="tarjeta cifra"><div class="n" style="font-size:1.4rem">{{ strtoupper($info['driver']) }}</div><div class="l">Motor</div></div>
    <div class="tarjeta cifra"><div class="n" style="font-size:1.1rem;word-break:break-all">{{ $info['base_datos'] }}</div><div class="l">Base de datos · {{ $info['host'] }}</div></div>
    <div class="tarjeta cifra"><div class="n">{{ $info['tamano'] !== null ? number_format($info['tamano'] / 1048576, 2).' MB' : '—' }}</div><div class="l">Tamaño</div></div>
    <div class="tarjeta cifra"><div class="n">{{ number_format(collect($info['tablas'])->sum('registros')) }}</div><div class="l">Registros totales</div></div>
</div>
<div class="rejilla r-2-1">
    <div class="tarjeta">
        <h2>Tablas</h2>
        <table><thead><tr><th>Tabla</th><th>Descripción</th><th>Registros</th><th>Columnas</th><th></th></tr></thead><tbody>
        @foreach ($info['tablas'] as $t)
            <tr><td class="mono">{{ $t['tabla'] }}</td><td>{{ $t['nombre'] }}</td><td><b>{{ number_format($t['registros']) }}</b></td><td>{{ $t['columnas'] }}</td>
                <td><a class="btn btn-chico" href="{{ route('bd.tabla', $t['tabla']) }}">Explorar</a></td></tr>
        @endforeach</tbody></table>
        <p class="ayuda">Versión: {{ $info['version'] }}</p>
    </div>
    <div>
        <div class="tarjeta">
            <h2>Conexión</h2>
            <p style="margin-top:0">Se configura en el archivo <span class="mono">.env</span>:</p>
<pre class="mono" style="background:#f4f5f7;padding:10px;border-radius:6px;white-space:pre-wrap"># SQLite (por defecto, sin instalar nada)
DB_CONNECTION=sqlite

# MySQL / MariaDB
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=expedientes
DB_USERNAME=root
DB_PASSWORD=</pre>
            <p class="ayuda">Luego ejecute <span class="mono">php artisan migrate</span>.</p>
        </div>
        <div class="tarjeta">
            <h2>Migraciones</h2>
            @foreach ($migraciones as $m)<div style="font-size:.8rem;margin-bottom:4px">{!! $m['ejecutada'] ? '<span class="chip verde">✓ lote '.$m['lote'].'</span>' : '<span class="chip rojo">pendiente</span>' !!} <span class="mono">{{ $m['migracion'] }}</span></div>@endforeach
        </div>
        <div class="tarjeta">
            <h2>Índice de la IA</h2>
            <p class="suave" style="margin-top:0">Si cambia el modelo de embeddings, vacíe el índice para regenerarlo.</p>
            <form method="post" action="{{ route('bd.vaciar') }}" data-confirmar="¿Vaciar el índice de la IA? Se regenera automáticamente.">@csrf<button class="btn btn-peligro btn-chico">Vaciar índice</button></form>
        </div>
    </div>
</div>
@endsection
