<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\EstadoPedido;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Bodega confirma qué sale de cada línea. Lo que no hay se baja (hasta 0)
 * y el pedido se recalcula con el precio vigente: eso es lo que le llega
 * al cliente. Después de esto el pedido queda cerrado (Despachado).
 *
 * Las promociones se vuelven a calcular con lo que de verdad sale: si ya no
 * se cumple la condición, el regalo se cobra. Se respetan las promociones con
 * que se levantó el pedido aunque se hayan desactivado después.
 */
final class ValidarDespacho
{
    public function __construct(private readonly RecalcularPedido $recalcular) {}

    /**
     * @param array<int, int|string|null> $despachado [id de línea => cantidad que sale]; si falta una línea sale completa
     */
    public function ejecutar(Pedido $pedido, array $despachado, User $usuario): Pedido
    {
        return DB::transaction(function () use ($pedido, $despachado, $usuario): Pedido {
            $pedido = Pedido::query()->lockForUpdate()->findOrFail($pedido->id);

            if (! $pedido->estaPendiente()) {
                throw new ReglaNegocioException("El pedido {$pedido->numero} ya está {$pedido->estado->getLabel()}; no se puede validar otra vez.");
            }

            /** @var PedidoItem $item */
            foreach ($pedido->items as $item) {
                $cantidad = array_key_exists($item->id, $despachado) && $despachado[$item->id] !== null
                    ? (int) $despachado[$item->id]
                    : $item->cantidad;

                $item->cantidad_despachada = max(0, min($cantidad, $item->cantidad));
                $item->save();
            }

            $this->recalcular->ejecutar($pedido, preciosVigentes: true, respetarPromocionesDelPedido: true);

            $pedido->forceFill([
                'estado'       => EstadoPedido::Despachado,
                'validado_por' => $usuario->id,
                'validado_at'  => now(),
            ])->save();

            return $pedido;
        });
    }
}
