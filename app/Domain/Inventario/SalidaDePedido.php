<?php

declare(strict_types=1);

namespace App\Domain\Inventario;

use App\Enums\TipoMovimiento;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Producto;
use App\Models\User;

/**
 * Lo que sale de la bodega con un pedido: al validar se saca lo que se
 * despacha; al corregir, solo la diferencia (si sale menos, regresa).
 *
 * Un movimiento por producto aunque venga en caja y en unidad. Los
 * productos se procesan en orden de id para que dos despachos al mismo
 * tiempo bloqueen las existencias en el mismo orden.
 */
final class SalidaDePedido
{
    public function __construct(private readonly MoverExistencia $mover) {}

    /**
     * @param array<int, int> $antes unidades por producto que ya habían salido (al corregir)
     */
    public function ejecutar(Pedido $pedido, TipoMovimiento $tipo, User $usuario, array $antes = []): void
    {
        $ahora = self::unidadesPorProducto($pedido);
        $ids = array_unique([...array_keys($antes), ...array_keys($ahora)]);
        sort($ids);

        /** @var array<int, Producto> $productos */
        $productos = $pedido->items->mapWithKeys(fn (PedidoItem $item): array => [$item->producto_id => $item->producto])->all();

        foreach ($ids as $id) {
            $sale = ($ahora[$id] ?? 0) - ($antes[$id] ?? 0);

            if ($sale === 0 || ! isset($productos[$id])) {
                continue;
            }

            $this->mover->ejecutar(
                $pedido->bodega_id,
                $productos[$id],
                -$sale,
                $tipo,
                $usuario,
                pedidoId: $pedido->id,
                nota: "PEDIDO {$pedido->numero}",
            );
        }
    }

    /**
     * Unidades que salen de cada producto (cajas × unidades por caja + sueltas).
     *
     * @return array<int, int>
     */
    public static function unidadesPorProducto(Pedido $pedido): array
    {
        $unidades = [];

        /** @var PedidoItem $item */
        foreach ($pedido->items as $item) {
            $unidades[$item->producto_id] = ($unidades[$item->producto_id] ?? 0)
                + $item->cantidadFacturable() * $item->producto->unidadesEn($item->unidad);
        }

        return $unidades;
    }

    /**
     * Lo que de verdad ha salido con el pedido según el kárdex (despacho y
     * correcciones), por producto. Se usa al corregir: no depende de las
     * unidades por caja de hoy.
     *
     * @return array<int, int>
     */
    public static function yaSalido(Pedido $pedido): array
    {
        /** @var array<int, int> $salido */
        $salido = MovimientoInventario::query()
            ->where('pedido_id', $pedido->id)
            ->whereIn('tipo', [TipoMovimiento::Despacho, TipoMovimiento::Correccion])
            ->groupBy('producto_id')
            ->selectRaw('producto_id, -SUM(cantidad) as salido')
            ->pluck('salido', 'producto_id')
            ->map(fn (mixed $unidades): int => (int) $unidades)
            ->all();

        return $salido;
    }

    /**
     * Si al pedido ya se le sacó de la bodega (los validados antes de que
     * hubiera inventario no tienen salida, y al corregirlos no se devuelve nada).
     */
    public static function yaSalio(Pedido $pedido): bool
    {
        return MovimientoInventario::query()
            ->where('pedido_id', $pedido->id)
            ->where('tipo', TipoMovimiento::Despacho)
            ->exists();
    }
}
