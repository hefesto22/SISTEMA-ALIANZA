<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Pedidos\CorregirPedido;
use App\Filament\Support\Acceso;
use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * Corregir un pedido ya validado (por imprimir o impreso) cuando avisan de un
 * error: quitar lo que no va o cambiar cantidades. Vuelve a Por imprimir.
 *
 * Si ya se imprimió, su factura queda ANULADA (con el motivo, que se pide
 * aquí) y al reimprimir sale una factura nueva que la sustituye.
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
            ->modalDescription(fn (Pedido $record): string => $record->factura !== null
                ? "Ya se imprimió con la factura N° {$record->factura->numero}. Al guardar, esa factura queda ANULADA (hay que retirarla al cliente) y al reimprimir sale una nueva. Cambie lo que sale con − y + (\"No hay\" = no sale)."
                : 'Cambie lo que sale con − y + ("No hay" = no sale). Sigue por imprimir.')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Guardar corrección')
            ->fillForm(fn (Pedido $record): array => LineasPedido::datos($record))
            ->schema(fn (Pedido $record): array => [
                ...LineasPedido::schema(),
                Textarea::make('motivo')
                    ->label('Motivo de la corrección')
                    ->placeholder('Ej. el cliente devolvió 2 cajas de aceite')
                    ->helperText('Queda en el historial como motivo de la anulación de la factura.')
                    ->rows(2)
                    ->maxLength(200)
                    ->required()
                    ->visible($record->factura !== null),
            ])
            ->action(function (array $data, Pedido $record): void {
                $usuario = Acceso::usuario();

                if ($usuario === null) {
                    return;
                }

                $anulada = $record->factura;
                $motivo = is_string($data['motivo'] ?? null) ? $data['motivo'] : null;

                try {
                    $pedido = app(CorregirPedido::class)->ejecutar($record, LineasPedido::cantidades($data, $record), $usuario, $motivo);
                } catch (ReglaNegocioException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title($anulada !== null
                        ? "Pedido {$pedido->numero} corregido: queda para reimprimir"
                        : "Pedido {$pedido->numero} corregido")
                    ->body(($anulada !== null ? "La factura N° {$anulada->numero} quedó ANULADA: retírela al cliente. " : '').'Total para el cliente: '.lempiras($pedido->total))
                    ->success()
                    ->actions([ImprimirPedidoAction::enNotificacion($pedido)])
                    ->send();

                $record->refresh();
                $record->unsetRelation('factura');
            });
    }
}
