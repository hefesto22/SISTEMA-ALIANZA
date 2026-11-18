{{--
    KPIs de ventas (App\Filament\Pages\KpisVentas): filtros arriba y el
    tablero abajo (filament/comisiones/partes/kpis). Se recalcula al cambiar
    un filtro.
--}}
<x-filament-panels::page>
    @include('filament.reportes.pantalla')
    @include('filament.comisiones.estilos')

    {{ $this->form }}

    @include('filament.comisiones.partes.kpis', ['d' => $this->datos()])
</x-filament-panels::page>
