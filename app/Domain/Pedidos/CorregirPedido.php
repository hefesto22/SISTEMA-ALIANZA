<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\EstadoPedido;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Corrige lo que sale de un pedido ya validado (por imprimir o impreso):
 * se quitan productos o se cambian cantidades. Los precios se quedan como
 * al validar; las promociones se recalculan con lo que sale.
 *
 * Queda Por imprimir: si ya se había impreso, es una reimpresión.
 */
final class CorregirPedido
{
    public function __construct(private readonly RecalcularPedido $recalcular) {}

    /**
     * @param array<int, int|string|null> $sale [id de línea => cantidad que sale]; si falta una línea se queda como estaba
     */
    public function ejecutar(Pedido $pedido, array $sale, User $usuario): Pedido
    {
        return DB::transaction(function () use ($pedido, $sale, $usuario): Pedido {
            $pedido = Pedido::query()->lockForUpdate()->findOrFail($pedido->id);

            if (! $pedido->estaValidado()) {
                throw new ReglaNegocioException("El pedido {$pedido->numero} está {$pedido->estado->getLabel()}: solo se corrigen pedidos ya validados.");
            }

            AjustarLineas::aplicar($pedido, $sale, faltaSaleCompleta: false);

            $this->recalcular->ejecutar($pedido, preciosVigentes: false, respetarPromocionesDelPedido: true);

            $pedido->forceFill([
                'estado'        => EstadoPedido::PorImprimir,
                'corregido_por' => $usuario->id,
                'corregido_at'  => now(),
            ])->save();

            return $pedido;
        });
    }
}
