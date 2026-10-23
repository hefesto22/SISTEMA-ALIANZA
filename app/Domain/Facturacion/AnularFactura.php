<?php

declare(strict_types=1);

namespace App\Domain\Facturacion;

use App\Enums\EstadoFactura;
use App\Models\Factura;
use App\Models\User;
use App\Support\Texto;

/**
 * Anula una factura ya impresa (el pedido se corrigió). La hoja que se
 * entregó hay que retirarla y marcarla ANULADA; original y copias se
 * guardan (Art. 41, Acuerdo 481-2017).
 */
final class AnularFactura
{
    public function ejecutar(Factura $factura, User $usuario, string $motivo): Factura
    {
        if ($factura->estaAnulada()) {
            return $factura;
        }

        $factura->forceFill([
            'estado'           => EstadoFactura::Anulada,
            'anulada_at'       => now(),
            'anulada_por'      => $usuario->id,
            'motivo_anulacion' => mb_substr(Texto::mayusculas($motivo), 0, 255),
        ])->save();

        return $factura;
    }
}
