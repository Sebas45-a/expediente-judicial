<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titulo', 'Panel') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=1">
</head>
<body>
<div class="app">
    <aside class="lateral">
        <div class="marca">
            <div class="sello">⚖</div>
            <div><b>Expediente Judicial</b><span>Gestión · Aprendizaje · IA local</span></div>
        </div>
        @php($r = fn ($p) => request()->routeIs($p) ? 'activo' : '')
        <a class="item {{ $r('panel') }}" href="{{ route('panel') }}"><span class="ico">◧</span> Panel</a>

        <div class="menu-titulo">Expedientes</div>
        <a class="item {{ $r('expedientes.*') }}" href="{{ route('expedientes.index') }}"><span class="ico">▤</span> Expedientes</a>
        <a class="item {{ $r('importar.*') }}" href="{{ route('importar.index') }}"><span class="ico">⇪</span> Importar desde Excel</a>

        <div class="menu-titulo">Aprendizaje</div>
        <a class="item {{ $r('tipos.*') }}" href="{{ route('tipos.index') }}"><span class="ico">◈</span> Tipos de expediente</a>
        <a class="item {{ $r('formatos.*') }}" href="{{ route('formatos.index') }}"><span class="ico">▦</span> Formatos aprendidos</a>

        <div class="menu-titulo">Inteligencia artificial</div>
        <a class="item {{ $r('asistente.*') }}" href="{{ route('asistente.index') }}"><span class="ico">✦</span> Asistente IA (Ollama)</a>

        <div class="menu-titulo">Sistema</div>
        <a class="item {{ $r('bd.*') }}" href="{{ route('bd.index') }}"><span class="ico">⛁</span> Base de datos</a>

        <div class="estado-ia" id="estado-ia" data-url="{{ route('asistente.estado') }}">
            <span class="punto"></span><span class="txt">Verificando IA…</span>
        </div>
    </aside>

    <main class="contenido">
        @if (session('ok'))<div class="alerta ok">{{ session('ok') }}</div>@endif
        @if (session('error'))<div class="alerta error">{{ session('error') }}</div>@endif
        @if ($errors->any())
            <div class="alerta error"><b>Revise los datos:</b>
                <ul>@foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach</ul>
            </div>
        @endif
        @yield('contenido')
    </main>
</div>
<div class="cargando" id="cargando"><div><div class="giro"></div><p>Procesando…</p><small>No cierre esta ventana.</small></div></div>
<script src="{{ asset('js/app.js') }}?v=1"></script>
@stack('scripts')
</body>
</html>
