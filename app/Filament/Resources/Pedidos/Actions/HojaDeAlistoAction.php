<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Enums\EstadoPedido;
use App\Filament\Support\Acceso;
use App\Filament\Support\VistaImpresion;
use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component as Livewire;

/**
 * "Hoja de alisto" de la pestaña Pendiente: lo que se saca de la bodega para
 * esos pedidos (App\Domain\Pedidos\HojaDeAlisto), para apartarlo antes de
 * validar. Se abre en un modal como vista de impresión, con "Imprimir o
 * guardar PDF" abajo:
 *  - de un día (ListPedidos::alistoDiaAction);
 *  - de los marcados en "Uno por uno" (seleccionados()); quedan marcados para
 *    darles "Validar seleccionados" después.
 * Lo que faltó se anota al validar ("¿Faltó algo al alistar?").
 */
final class HojaDeAlistoAction
{
    /**
     * El modal; quien lo usa pone el título y modalContent(vista()).
     */
    public static function modal(Action $action): Action
    {
        return $action
            ->modalDescription('Para ir sacando y apartando lo de estos pedidos. Marque «Apartado» y anote en «Faltó» lo que no encontró; al validar lo pone en «¿Faltó algo al alistar?». Para el PDF, en la ventana de imprimir escoja «Guardar como PDF».')
            ->modalIcon(Heroicon::OutlinedClipboardDocumentList)
            ->modalWidth(Width::SevenExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            ->extraModalFooterActions([
                Action::make('imprimirAlisto')
                    ->label('Imprimir o guardar PDF')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->alpineClickHandler("document.getElementById('".VistaImpresion::MARCO."')?.contentWindow?.print()"),
            ]);
    }

    /**
     * La hoja de esos pedidos, como vista de impresión.
     *
     * @param list<int> $ids
     */
    public static function vista(array $ids): View
    {
        return view('filament.reportes.vista-impresion', [
            'url'   => route('pedidos.alisto', ['ids' => implode(',', $ids), 'embebido' => 1]),
            'marco' => VistaImpresion::MARCO,
        ]);
    }

    /**
     * "Hoja de alisto" de los marcados en "Uno por uno" de la pestaña Pendiente.
     */
    public static function seleccionados(): BulkAction
    {
        $action = BulkAction::make('alistoSeleccionados')
            ->label('Hoja de alisto')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->color('gray')
            ->visible(fn (Livewire $livewire): bool => Acceso::puede('Validar:Pedido')
                && data_get($livewire, 'activeTab') === EstadoPedido::Pendiente->value)
            ->modalHeading(fn (Collection $records): string => $records->count() === 1 ? 'Hoja de alisto del pedido marcado' : 'Hoja de alisto de los '.$records->count().' pedidos marcados')
            ->modalContent(fn (Collection $records): View => self::vista(self::idsDe($records)));

        self::modal($action);

        return $action;
    }

    /**
     * @param Collection<array-key, mixed> $records
     *
     * @return list<int>
     */
    private static function idsDe(Collection $records): array
    {
        $ids = [];

        foreach ($records as $pedido) {
            if ($pedido instanceof Pedido) {
                $ids[] = $pedido->id;
            }
        }

        return $ids;
    }
}
