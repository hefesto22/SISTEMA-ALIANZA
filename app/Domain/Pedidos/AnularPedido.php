<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\EstadoPedido;
use App\Models\Pedido;

/**
 * Cancela un pedido que todavía no se despachó.
 */
final class AnularPedido
{
    public function ejecutar(Pedido $pedido, ?string $motivo = null): Pedido
    {
        if (! $pedido->estaPendiente()) {
            throw new ReglaNegocioException("Solo se pueden anular pedidos pendientes; {$pedido->numero} ya está {$pedido->estado->getLabel()}.");
        }

        $notas = trim(($pedido->notas ?? '')."\nAnulado".($motivo ? ": {$motivo}" : '.'));

        $pedido->forceFill([
            'estado' => EstadoPedido::Anulado,
            'notas'  => $notas,
        ])->save();

        return $pedido;
    }
}
