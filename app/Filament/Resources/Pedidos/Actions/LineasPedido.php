<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Models\Pedido;
use App\Models\PedidoItem;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Las líneas del pedido en el modal de Validar y de Corregir: cuánto sale
 * de cada producto. Lo que no hay se quita con el basurero (o se pone 0).
 */
final class LineasPedido
{
    /**
     * @return array<int, Component>
     */
    public static function schema(): array
    {
        $mensaje = 'Tiene que salir al menos un producto. Si no hay nada, anule el pedido.';

        return [
            Repeater::make('items')
                ->hiddenLabel()
                ->table([
                    TableColumn::make('Producto'),
                    TableColumn::make('En')->width('110px'),
                    TableColumn::make('Pidió')->width('90px'),
                    TableColumn::make('Sale')->markAsRequired()->width('110px'),
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
                ->deletable()
                ->deleteAction(fn (Action $action): Action => $action->tooltip('No hay: quitar'))
                ->reorderable(false)
                ->minItems(1)
                ->validationMessages(['min' => $mensaje])
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($mensaje): void {
                    $sale = collect(is_array($value) ? $value : [])
                        ->contains(fn (mixed $fila): bool => is_array($fila) && is_numeric($fila['sale'] ?? null) && (int) $fila['sale'] > 0);

                    if (! $sale) {
                        $fail($mensaje);
                    }
                }),
        ];
    }

    /**
     * Para llenar el modal: lo que se pidió y lo que sale ahora.
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public static function datos(Pedido $pedido): array
    {
        $items = $pedido->items()
            ->with('producto')
            ->orderBy('id')
            ->get()
            ->map(fn (PedidoItem $item): array => [
                'id'       => $item->id,
                'producto' => $item->producto->etiqueta(),
                'unidad'   => $item->unidad->getLabel(),
                'cantidad' => $item->cantidad,
                'sale'     => $item->cantidadFacturable(),
            ])
            ->all();

        return ['items' => array_values($items)];
    }

    /**
     * Lo que sale de cada línea según el modal; las que se quitaron, 0.
     *
     * @param array<string, mixed> $data
     *
     * @return array<int, int|null>
     */
    public static function cantidades(array $data, Pedido $pedido): array
    {
        $sale = [];

        foreach ((array) ($data['items'] ?? []) as $fila) {
            if (is_array($fila) && isset($fila['id'])) {
                $sale[(int) $fila['id']] = is_numeric($fila['sale'] ?? null) ? (int) $fila['sale'] : null;
            }
        }

        $cantidades = [];

        foreach ($pedido->items()->pluck('id') as $id) {
            $cantidades[(int) $id] = array_key_exists((int) $id, $sale) ? $sale[(int) $id] : 0;
        }

        return $cantidades;
    }
}
