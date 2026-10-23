<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Enums\EstadoPedido;
use App\Filament\Support\Acceso;
use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Imprimir la hoja del pedido ahí mismo: se abre el diálogo de imprimir del
 * navegador, sin otra pestaña. Todas mandan el evento "imprimir-hoja" con la
 * URL de la hoja; lo atiende resources/views/filament/imprimir-hoja.blade.php
 * (la carga en un iframe oculto e imprime).
 *
 *  - make(): "Imprimir" / "Reimprimir" para los que están Por imprimir; al
 *    imprimirla bodega, el pedido pasa a Impreso.
 *  - hoja(): "Imprimir copia" de uno impreso (cuenta como reimpresión) o
 *    "Vista previa" para quien no imprime (vendedor, pedido pendiente).
 *  - seleccionados(): los marcados en la lista, uno por página.
 */
final class ImprimirPedidoAction
{
    public const EVENTO = 'imprimir-hoja';

    public static function make(): Action
    {
        return Action::make('imprimir')
            ->label(fn (Pedido $record): string => $record->esReimpresion() ? 'Reimprimir' : 'Imprimir')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->authorize('imprimir')
            ->visible(fn (Pedido $record): bool => $record->estado === EstadoPedido::PorImprimir)
            ->dispatch(self::EVENTO, fn (Pedido $record): array => self::datos(route('pedidos.imprimir', $record)));
    }

    public static function hoja(): Action
    {
        return Action::make('hoja')
            ->label(fn (Pedido $record): string => $record->estado === EstadoPedido::Impreso && Gate::allows('imprimir', $record) ? 'Imprimir copia' : 'Vista previa')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('gray')
            ->authorize('view')
            ->visible(fn (Pedido $record): bool => ! ($record->estado === EstadoPedido::PorImprimir && Gate::allows('imprimir', $record)))
            ->dispatch(self::EVENTO, fn (Pedido $record): array => self::datos(route('pedidos.imprimir', $record)));
    }

    /**
     * Botón "Imprimir" de la notificación al validar o corregir.
     */
    public static function enNotificacion(Pedido $pedido): Action
    {
        return Action::make('imprimir')
            ->label($pedido->esReimpresion() ? 'Reimprimir' : 'Imprimir')
            ->dispatch(self::EVENTO, self::datos(route('pedidos.imprimir', $pedido)))
            ->close();
    }

    /**
     * Los pedidos marcados en la lista, de un solo (uno por página). Los que
     * no están validados o no son de su bodega se saltan.
     */
    public static function seleccionados(): BulkAction
    {
        return BulkAction::make('imprimirSeleccionados')
            ->label('Imprimir seleccionados')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            // En Pendiente y Anulado no hay nada que imprimir: ni se marcan.
            ->visible(fn (Component $livewire): bool => Acceso::puede('Validar:Pedido')
                && ! in_array(data_get($livewire, 'activeTab'), [EstadoPedido::Pendiente->value, EstadoPedido::Anulado->value], true))
            ->action(function (Collection $records, Component $livewire): void {
                self::imprimirSeleccionados($records, $livewire);
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * La URL de la hoja de varios pedidos (uno por página).
     *
     * @param list<int> $ids
     */
    public static function urlDeVarios(array $ids): string
    {
        return route('pedidos.imprimir-lote', ['ids' => implode(',', $ids)]);
    }

    /**
     * @return array{url: string}
     */
    public static function datos(string $url): array
    {
        return ['url' => $url];
    }

    /**
     * @param Collection<array-key, mixed> $records
     */
    private static function imprimirSeleccionados(Collection $records, Component $livewire): void
    {
        $ids = [];

        foreach ($records as $pedido) {
            if ($pedido instanceof Pedido && Gate::allows('imprimir', $pedido)) {
                $ids[] = $pedido->id;
            }
        }

        sort($ids);

        if ($ids === []) {
            Notification::make()
                ->title('Ninguno de los seleccionados se puede imprimir')
                ->body('Solo se imprimen pedidos validados (por imprimir o impresos) de su bodega.')
                ->warning()
                ->send();

            return;
        }

        $saltados = $records->count() - count($ids);

        if ($saltados > 0) {
            Notification::make()
                ->title('Se imprimen '.count($ids).' de '.$records->count())
                ->body("Se saltaron {$saltados}: no están validados o no son de su bodega.")
                ->warning()
                ->send();
        }

        $livewire->dispatch(self::EVENTO, url: self::urlDeVarios($ids));
    }
}
