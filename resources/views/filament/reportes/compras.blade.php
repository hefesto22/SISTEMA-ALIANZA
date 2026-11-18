{{--
    Pantalla del reporte de compras (App\Filament\Pages\ReporteCompras): los
    filtros arriba y el reporte abajo; se recalcula al cambiar un filtro.
--}}
<x-filament-panels::page>
    @include('filament.reportes.pantalla')

    {{ $this->form }}

    @php($d = $this->datos())

    <div wire:loading.delay.class="rp-cargando">
        <div class="rp-encabezado">{{ $d['periodo'] }} · {{ $d['alcance'] }}</div>
        @include('reportes.partes.compras', ['d' => $d])
    </div>
</x-filament-panels::page>
