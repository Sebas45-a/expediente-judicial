@if ($p['tipo'])
    <div class="alerta {{ $p['confiable'] ? 'ok' : 'aviso' }}"><b>{{ $p['tipo'] }}</b> · {{ $p['confianza'] }}% ({{ $p['metodo'] }})</div>
    @foreach ($p['alternativas'] as $a)<div class="fila-barra"><span>{{ $a['tipo'] }}</span><div class="barra"><span style="width: {{ $a['probabilidad'] }}%"></span></div><b>{{ round($a['probabilidad']) }}%</b></div>@endforeach
@else
    <div class="alerta aviso">No hay suficiente aprendizaje para clasificar este texto.</div>
@endif
