@if ($tipo)
    <span class="tipo-chip"><i style="background: {{ $tipo->color }}"></i>{{ $tipo->nombre }}</span>
@else
    <span class="chip gris">Sin clasificar</span>
@endif
