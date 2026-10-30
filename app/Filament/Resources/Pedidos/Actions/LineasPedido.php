<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Enums\UnidadVenta;
use App\Filament\Schemas\Components\SalidaDePedido;
use App\Models\Configuracion;
use App\Models\Existencia;
use App\Models\Pedido;
use App\Models\PedidoItem;
use Filament\Schemas\Components\Component;

/**
 * Las líneas del pedido en el modal de Validar y de Corregir: cuánto sale
 * de cada producto (SalidaDePedido). Lo que no hay se marca "No hay" (sale
 * 0). "Hay" es la existencia de la bodega del pedido.
 */
final class LineasPedido
{
    /**
     * @return array<int, Component>
     */
    public static function schema(bool $paraValidar = false): array
    {
        return [
            SalidaDePedido::make('items')->paraValidar($paraValidar),
        ];
    }

    /**
     * Para llenar el modal: lo que se pidió, lo que hay en la bodega y lo que
     * sale ahora.
     *
     * Al validar con "controlar existencias" encendido, "Sale" ya viene con
     * lo que alcanza (repartiendo la existencia entre la caja y las unidades
     * del mismo producto, en orden).
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public static function datos(Pedido $pedido, bool $segunExistencia = false): array
    {
        $items = $pedido->items()->with('producto')->orderBy('id')->get();

        /** @var array<int, int> $hay */
        $hay = Existencia::query()
            ->where('bodega_id', $pedido->bodega_id)
            ->whereIn('producto_id', $items->pluck('producto_id'))
            ->pluck('cantidad', 'producto_id')
            ->map(fn (mixed $cantidad): int => (int) $cantidad)
            ->all();

        $ajustar = $segunExistencia && Configuracion::actual()->controlar_existencias;
        $quedan = $hay;
        $filas = [];

        /** @var PedidoItem $item */
        foreach ($items as $item) {
            $sale = $item->cantidadFacturable();

            if ($ajustar) {
                $porUnidad = $item->producto->unidadesEn($item->unidad);
                $sale = min($sale, intdiv(max(0, $quedan[$item->producto_id] ?? 0), $porUnidad));
                $quedan[$item->producto_id] = ($quedan[$item->producto_id] ?? 0) - $sale * $porUnidad;
            }

            $filas[] = [
                'id'           => $item->id,
                'producto'     => $item->producto->etiqueta(),
                'codigo'       => $item->producto->codigo,
                'descripcion'  => $item->producto->descripcion,
                'unidad'       => $item->unidad->getLabel(),
                'sigla'        => $item->unidad === UnidadVenta::Caja ? 'CJ' : 'UN',
                'por_unidad'   => $item->producto->unidadesEn($item->unidad),
                'cantidad'     => $item->cantidad,
                'hay'          => Existencia::enCajas($hay[$item->producto_id] ?? 0, $item->producto->unidades_por_caja),
                'hay_unidades' => $hay[$item->producto_id] ?? 0,
                'sale'         => $sale,
            ];
        }

        return ['items' => $filas];
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
