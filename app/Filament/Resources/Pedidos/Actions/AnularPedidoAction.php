<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Pedidos\AnularPedido;
use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class AnularPedidoAction
{
    public static function make(): Action
    {
        return Action::make('anular')
            ->label('Anular')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('delete')
            ->requiresConfirmation()
            ->modalHeading(fn (Pedido $record): string => "¿Anular el pedido {$record->numero}?")
            ->modalDescription('El pedido queda anulado (no se borra).')
            ->schema([
                TextInput::make('motivo')
                    ->label('Motivo (opcional)')
                    ->maxLength(200),
            ])
            ->action(function (array $data, Pedido $record): void {
                try {
                    app(AnularPedido::class)->ejecutar($record, filled($data['motivo'] ?? null) ? (string) $data['motivo'] : null);
                } catch (ReglaNegocioException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title("Pedido {$record->numero} anulado")->success()->send();
            });
    }
}
