<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Domain\Facturacion\EmitirFactura;
use App\Enums\EstadoPedido;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Bodega imprimió el pedido: pasa a Impreso y se cuenta la impresión.
 *
 * La primera vez se emite su factura con el número del CAI; las copias
 * usan la misma (de la segunda en adelante la hoja dice REIMPRESIÓN). Si
 * el pedido se corrigió, su factura se anuló y aquí se emite la nueva.
 *
 * Solo cuenta para pedidos validados; la hoja de un pendiente es una vista
 * previa y no cambia nada.
 */
final class MarcarImpreso
{
    public function __construct(private readonly EmitirFactura $emitir) {}

    public function ejecutar(Pedido $pedido, User $usuario): Pedido
    {
        if (! $pedido->estaValidado()) {
            return $pedido;
        }

        return DB::transaction(function () use ($pedido, $usuario): Pedido {
            // Uno a la vez por pedido: que dos impresiones juntas no emitan dos facturas.
            Pedido::query()->whereKey($pedido->id)->lockForUpdate()->first();

            $factura = $pedido->factura()->first() ?? $this->emitir->ejecutar($pedido, $usuario);

            $factura->forceFill(['impresiones' => $factura->impresiones + 1])->save();
            $factura->loadMissing('reemplazaA');

            $pedido->forceFill([
                'estado'      => EstadoPedido::Impreso,
                'impresiones' => $pedido->impresiones + 1,
                'impreso_at'  => now(),
                'impreso_por' => $usuario->id,
            ])->save();

            $pedido->setRelation('factura', $factura);

            return $pedido;
        });
    }
}
