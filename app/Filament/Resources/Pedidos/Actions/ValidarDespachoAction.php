<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Pedidos\ValidarDespacho;
use App\Filament\Support\Acceso;
use App\Models\Pedido;
use App\Models\PedidoItem;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * Bodega revisa el pedido línea por línea: cuánto sale de verdad.
 * Lo que no hay se baja (hasta 0). Al confirmar, el pedido se recalcula
 * con el precio de HOY y queda Despachado: eso es lo que le llega al cliente.
 */
final class ValidarDespachoAction
{
    public static function make(): Action
    {
        return Action::make('validarDespacho')
            ->label('Validar despacho')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('success')
            ->authorize('validar')
            ->modalHeading(fn (Pedido $record): string => "Validar despacho · {$record->numero} · {$record->cliente->nombre}")
            ->modalDescription('Ponga cuánto sale de cada producto. Lo que no hay, bájelo (0 = no sale). Al guardar se aplican los precios de hoy y se recalculan las promociones con lo que sale.')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Confirmar despacho')
            ->fillForm(fn (Pedido $record): array => [
                'items' => $record->items()->with('producto')->get()->map(fn (PedidoItem $item): array => [
                    'id'       => $item->id,
                    'producto' => $item->producto->etiqueta(),
                    'unidad'   => $item->unidad->getLabel(),
                    'cantidad' => $item->cantidad,
                    'sale'     => $item->cantidad,
                ])->all(),
            ])
            ->schema([
                Repeater::make('items')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('Producto'),
                        TableColumn::make('En'),
                        TableColumn::make('Pidió'),
                        TableColumn::make('Sale')->markAsRequired(),
                    ])
                    ->schema([
                        Hidden::make('id'),
                        TextInput::make('producto')->hiddenLabel()->disabled(),
                        TextInput::make('unidad')->hiddenLabel()->disabled(),
                        TextInput::make('cantidad')->hiddenLabel()->disabled(),
                        TextInput::make('sale')
                            ->hiddenLabel()
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(fn (Get $get): int => (int) $get('cantidad'))
                            ->required(),
                    ])
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false),
            ])
            ->action(function (array $data, Pedido $record): void {
                $usuario = Acceso::usuario();

                if ($usuario === null) {
                    return;
                }

                $despachado = [];

                foreach ((array) ($data['items'] ?? []) as $fila) {
                    if (is_array($fila) && isset($fila['id'])) {
                        $despachado[(int) $fila['id']] = is_numeric($fila['sale'] ?? null) ? (int) $fila['sale'] : null;
                    }
                }

                try {
                    $pedido = app(ValidarDespacho::class)->ejecutar($record, $despachado, $usuario);
                } catch (ReglaNegocioException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                $faltantes = $pedido->items->filter(fn (PedidoItem $item): bool => $item->faltante() > 0)->count();

                Notification::make()
                    ->title("Pedido {$pedido->numero} despachado")
                    ->body('Total para el cliente: '.lempiras($pedido->total).($faltantes > 0 ? " · {$faltantes} línea(s) con menos de lo pedido." : ' · Salió completo.'))
                    ->success()
                    ->actions([
                        Action::make('imprimir')
                            ->label('Imprimir')
                            ->url(route('pedidos.imprimir', $pedido))
                            ->openUrlInNewTab(),
                    ])
                    ->persistent()
                    ->send();

                $record->refresh();
            });
    }
}
