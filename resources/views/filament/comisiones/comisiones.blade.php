{{--
    Comisiones del periodo (App\Filament\Pages\Comisiones): el estado
    (en curso, por cerrar o cerrado), los totales y la tarjeta de cada
    vendedor con una fila por grupo. Se recalcula al cambiar un filtro.
--}}
<x-filament-panels::page>
    @include('filament.reportes.pantalla')
    @include('filament.comisiones.estilos')

    {{ $this->form }}

    @include('filament.comisiones.partes.liquidacion', ['d' => $this->datos()])
</x-filament-panels::page>
