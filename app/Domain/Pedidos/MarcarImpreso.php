<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Enums\EstadoPedido;
use App\Models\Pedido;
use App\Models\User;

/**
 * Bodega imprimió el pedido: pasa a Impreso y se cuenta la impresión
 * (de la segunda en adelante la hoja sale como REIMPRESIÓN).
 *
 * Solo cuenta para pedidos validados; la hoja de un pendiente es una vista
 * previa y no cambia nada.
 */
final class MarcarImpreso
{
    public function ejecutar(Pedido $pedido, User $usuario): Pedido
    {
        if (! $pedido->estaValidado()) {
            return $pedido;
        }

        $pedido->forceFill([
            'estado'      => EstadoPedido::Impreso,
            'impresiones' => $pedido->impresiones + 1,
            'impreso_at'  => now(),
            'impreso_por' => $usuario->id,
        ])->save();

        return $pedido;
    }
}
