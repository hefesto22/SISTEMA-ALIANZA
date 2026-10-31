{{--
    Marca del panel cuando no hay logo cargado en Configuración: las
    iniciales en un cuadrito del color del tema y el nombre.
--}}
@php
    $nombre = trim(strip_tags((string) filament()->getBrandName()));
    $iniciales = collect(preg_split('/\s+/u', $nombre) ?: [])
        ->filter()
        ->take(2)
        ->map(fn (string $palabra): string => mb_strtoupper(mb_substr($palabra, 0, 1)))
        ->implode('');
@endphp

<span class="mn-marca">
    <span class="mn-marca-sello" aria-hidden="true">{{ $iniciales }}</span>
    <span class="mn-marca-nombre">{{ $nombre }}</span>
</span>
