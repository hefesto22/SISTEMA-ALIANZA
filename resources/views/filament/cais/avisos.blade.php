{{--
    Avisos arriba de los CAI, uno por línea, solo si algo está mal (CAI de
    prueba en uso, bodegas sin CAI vigente). Datos: $avisos, de
    App\Filament\Resources\Cais\Pages\ManageCais::avisos().
--}}
<div style="display: grid; gap: 0.5rem;">
    @foreach ($avisos as $aviso)
        <x-filament::callout
            :color="$aviso['color']"
            :icon="$aviso['color'] === 'warning' ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-no-symbol'"
            :heading="$aviso['texto']"
        />
    @endforeach
</div>
