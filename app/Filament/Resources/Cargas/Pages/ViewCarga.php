<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cargas\Pages;

use App\Domain\Facturacion\ResumenDelDia;
use App\Filament\Resources\Cargas\CargaResource;
use App\Filament\Support\VistaImpresion;
use App\Http\Controllers\ImprimirCargaController;
use App\Models\Carga;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * La carga: en ruta, lo que lleva (facturas y productos); liquidada, el
 * cuadre. Arriba: Liquidar (en ruta), Imprimir (hoja de carga, de cobro y
 * la liquidación), Editar y Borrar (en ruta).
 */
class ViewCarga extends ViewRecord
{
    protected static string $resource = CargaResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('liquidar')
                ->label('Liquidar')
                ->icon(Heroicon::OutlinedBanknotes)
                ->visible(fn (): bool => Gate::allows('liquidar', $this->carga()))
                ->url(fn (): string => CargaResource::getUrl('liquidar', ['record' => $this->carga()])),
            ActionGroup::make([
                $this->hoja('hojaCarga', 'carga', Heroicon::OutlinedTruck),
                $this->hoja('hojaCobro', 'cobro', Heroicon::OutlinedPencilSquare),
                $this->hoja('hojaLiquidacion', 'liquidacion', Heroicon::OutlinedBanknotes)
                    ->visible(fn (): bool => ! $this->carga()->enRuta()),
            ])
                ->label('Imprimir')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->button(),
            EditAction::make()->label('Cambiar'),
            DeleteAction::make()
                ->modalDescription('La carga se borra y sus facturas quedan libres para otra carga. Las facturas no se anulan.'),
        ];
    }

    public function getSubheading(): string
    {
        $carga = $this->carga();

        return mb_ucfirst(ResumenDelDia::fechaLarga($carga->fecha->toDateString())).' · '.$carga->bodega->nombre.' · '.$carga->estado->getLabel();
    }

    /**
     * Una hoja de la carga en la vista de impresión (modal).
     */
    private function hoja(string $nombre, string $hoja, Heroicon $icono): Action
    {
        $titulo = ImprimirCargaController::HOJAS[$hoja];

        return VistaImpresion::accion($titulo.' · '.$this->carga()->numero(), fn (): string => route('cargas.imprimir', [
            'carga'    => $this->carga(),
            'hoja'     => $hoja,
            'embebido' => 1,
        ]))
            ->name($nombre)
            ->label($titulo)
            ->icon($icono);
    }

    private function carga(): Carga
    {
        $carga = $this->getRecord();
        abort_unless($carga instanceof Carga, 404);

        return $carga;
    }
}
