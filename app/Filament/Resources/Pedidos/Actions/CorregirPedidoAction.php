<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Pedidos\CorregirPedido;
use App\Enums\EstadoPedido;
use App\Filament\Support\Acceso;
use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * Corregir un pedido ya validado (por imprimir o impreso) cuando avisan de un
 * error: quitar lo que no va o cambiar cantidades. Vuelve a Por imprimir; si
 * ya se había impreso, queda para reimprimir.
 */
final class CorregirPedidoAction
{
    public static function make(): Action
    {
        return Action::make('corregir')
            ->label('Corregir')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->authorize('corregir')
            ->modalHeading(fn (Pedido $record): string => "Corregir · {$record->numero} · {$record->cliente->nombre}")
            ->modalDescription(fn (Pedido $record): string => $record->estado === EstadoPedido::Impreso
                ? 'Ya se imprimió. Cambie lo que sale (basurero o 0 = no sale); al guardar queda para reimprimir.'
                : 'Cambie lo que sale (basurero o 0 = no sale). Sigue por imprimir.')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Guardar corrección')
            ->fillForm(fn (Pedido $record): array => LineasPedido::datos($record))
            ->schema(LineasPedido::schema())
            ->action(function (array $data, Pedido $record): void {
                $usuario = Acceso::usuario();

                if ($usuario === null) {
                    return;
                }

                try {
                    $pedido = app(CorregirPedido::class)->ejecutar($record, LineasPedido::cantidades($data, $record), $usuario);
                } catch (ReglaNegocioException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title($pedido->esReimpresion()
                        ? "Pedido {$pedido->numero} corregido: queda para reimprimir"
                        : "Pedido {$pedido->numero} corregido")
                    ->body('Total para el cliente: '.lempiras($pedido->total))
                    ->success()
                    ->actions([ImprimirPedidoAction::enNotificacion($pedido)])
                    ->send();

                $record->refresh();
            });
    }
}
