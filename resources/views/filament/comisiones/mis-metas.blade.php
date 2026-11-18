{{--
    Mis metas (App\Filament\Pages\MisMetas): el periodo y, abajo, las metas
    del vendedor. El cuerpo es filament/comisiones/partes/mis-metas.
--}}
<x-filament-panels::page>
    @include('filament.reportes.pantalla')
    @include('filament.comisiones.estilos')

    {{ $this->form }}

    @include('filament.comisiones.partes.mis-metas', ['d' => $this->datos()])
</x-filament-panels::page>
