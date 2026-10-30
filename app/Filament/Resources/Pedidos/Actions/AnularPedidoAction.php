<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Pedidos\AnularPedido;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

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
            ->modalDescription('Sale de la lista del vendedor. No se borra: bodega y administración lo siguen viendo como Anulado.')
            ->schema([
                TextInput::make('motivo')
                    ->label('Motivo (opcional)')
                    ->maxLength(200),
            ])
            ->action(function (array $data, Pedido $record, Component $livewire): void {
                try {
                    app(AnularPedido::class)->ejecutar($record, filled($data['motivo'] ?? null) ? (string) $data['motivo'] : null);
                } catch (ReglaNegocioException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title("Pedido {$record->numero} anulado")->success()->send();

                // El vendedor ya no ve un pedido anulado: de la pantalla del pedido, a la lista.
                if ($livewire instanceof ViewRecord && Gate::denies('view', $record)) {
                    $livewire->redirect(PedidoResource::getUrl('index'));
                }
            });
    }
}
