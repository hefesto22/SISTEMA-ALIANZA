<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Pedidos\ValidarDespacho;
use App\Filament\Support\Acceso;
use App\Models\Pedido;
use App\Models\PedidoItem;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * Bodega revisa el pedido línea por línea: lo que no hay se quita (botón de
 * basurero) y lo que no alcanzó se baja a lo que había. Al confirmar, el
 * pedido se recalcula con el precio de HOY y queda Por imprimir.
 *
 * Las líneas quitadas no se borran del pedido: quedan con "sale 0" para que
 * se sepa qué faltó (en la impresión salen tachadas).
 */
final class ValidarDespachoAction
{
    public static function make(): Action
    {
        return Action::make('validarDespacho')
            ->label('Validar')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('success')
            ->authorize('validar')
            ->modalHeading(fn (Pedido $record): string => "Validar · {$record->numero} · {$record->cliente->nombre}")
            ->modalDescription('Quite con el basurero lo que no hay y baje la cantidad de lo que no alcanzó. Al validar se cobran los precios de hoy y el pedido pasa a Por imprimir.')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Validar')
            ->fillForm(fn (Pedido $record): array => LineasPedido::datos($record))
            ->schema(LineasPedido::schema())
            ->action(function (array $data, Pedido $record): void {
                $usuario = Acceso::usuario();

                if ($usuario === null) {
                    return;
                }

                try {
                    $pedido = app(ValidarDespacho::class)->ejecutar($record, LineasPedido::cantidades($data, $record), $usuario);
                } catch (ReglaNegocioException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                $faltantes = $pedido->items->filter(fn (PedidoItem $item): bool => $item->faltante() > 0)->count();

                Notification::make()
                    ->title("Pedido {$pedido->numero} validado: queda por imprimir")
                    ->body('Total para el cliente: '.lempiras($pedido->total).($faltantes > 0 ? " · {$faltantes} línea(s) con menos de lo pedido." : ' · Sale completo.'))
                    ->success()
                    ->actions([ImprimirPedidoAction::enNotificacion($pedido)])
                    ->send();

                $record->refresh();
            });
    }
}
