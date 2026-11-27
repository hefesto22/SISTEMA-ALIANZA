{{--
    La carga en el panel (App\Filament\Resources\Cargas\Pages\ViewCarga): en
    ruta, lo que lleva (resources/views/reportes/partes/carga-carga); liquidada,
    el cuadre (carga-liquidacion). Son las mismas hojas de imprimir, sin
    casillas ni firmas.
    En ruta, cada factura trae el botón Devolución (quien puede registrarlas).
    Datos: $d = App\Domain\Cargas\HojaDeCarga::datos().
--}}
@php
    /** @var array<string, mixed> $d */
    $devolver = ! $d['liquidada'] && (\App\Filament\Support\Acceso::usuario()?->can('create', \App\Models\Devolucion::class) ?? false);
@endphp

<div>
    @include('filament.reportes.pantalla')

    @if ($d['liquidada'])
        @include('reportes.partes.carga-liquidacion', ['d' => $d, 'pantalla' => true])
    @else
        @include('reportes.partes.carga-carga', ['d' => $d, 'pantalla' => true, 'devolver' => $devolver])
    @endif
</div>
