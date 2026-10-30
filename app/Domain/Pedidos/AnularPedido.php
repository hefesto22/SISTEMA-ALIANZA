<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\EstadoPedido;
use App\Models\Pedido;
use Illuminate\Support\Facades\DB;

/**
 * Cancela un pedido que todavía no se despachó.
 *
 * Bloquea el pedido y revisa el estado ahí mismo: si bodega lo validó un
 * instante antes, no se anula (no queda anulado un pedido ya facturado).
 */
final class AnularPedido
{
    public function ejecutar(Pedido $pedido, ?string $motivo = null): Pedido
    {
        return DB::transaction(function () use ($pedido, $motivo): Pedido {
            $actual = Pedido::query()->lockForUpdate()->findOrFail($pedido->id);

            if (! $actual->estaPendiente()) {
                throw new ReglaNegocioException("Solo se pueden anular pedidos pendientes; {$actual->numero} ya está {$actual->estado->getLabel()}.");
            }

            $notas = trim(($actual->notas ?? '')."\nAnulado".($motivo ? ": {$motivo}" : '.'));

            $actual->forceFill([
                'estado' => EstadoPedido::Anulado,
                'notas'  => $notas,
            ])->save();

            $pedido->setRawAttributes($actual->getAttributes(), true);

            return $actual;
        });
    }
}
