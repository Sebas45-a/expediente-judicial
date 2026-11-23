@if ($paginator->hasPages())
    <nav class="paginacion">
        @if ($paginator->onFirstPage())<span class="suave">‹</span>@else<a href="{{ $paginator->previousPageUrl() }}">‹</a>@endif
        @foreach ($elements as $element)
            @if (is_string($element))<span>{{ $element }}</span>@endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())<span class="actual">{{ $page }}</span>@else<a href="{{ $url }}">{{ $page }}</a>@endif
                @endforeach
            @endif
        @endforeach
        @if ($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}">›</a>@else<span class="suave">›</span>@endif
    </nav>
    <p class="suave" style="text-align:center;font-size:.8rem">Mostrando {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}</p>
@endif
