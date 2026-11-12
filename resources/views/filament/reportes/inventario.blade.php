{{--
    Pantalla del inventario valorizado (App\Filament\Pages\ReporteInventario):
    los filtros arriba y el reporte abajo; se recalcula al cambiar un filtro.
--}}
<x-filament-panels::page>
    @include('filament.reportes.pantalla')

    {{ $this->form }}

    @php($d = $this->datos())

    <div wire:loading.delay.class="rp-cargando">
        <div class="rp-encabezado">{{ $d['bodega'] }} · al {{ $d['fecha'] }}{{ $d['filtros'] ? ' · '.$d['filtros'] : '' }}</div>
        @include('reportes.partes.inventario', ['d' => $d])
    </div>
</x-filament-panels::page>
