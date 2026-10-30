<?php

declare(strict_types=1);

namespace App\Domain\Facturacion;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Models\Cai;
use App\Models\DatosFiscales;
use App\Models\Factura;
use App\Models\Pedido;
use App\Models\User;

/**
 * Emite la factura de un pedido con el siguiente número del CAI de su
 * bodega (el propio o, si no tiene, el global).
 *
 * Se llama dentro de la transacción de ImprimirFacturas: el CAI queda tomado
 * (lockForUpdate) hasta terminar, así dos impresiones al mismo tiempo no
 * sacan el mismo número.
 *
 * Si el pedido tenía una factura anulada (se corrigió después de impreso),
 * la nueva queda como la que la sustituye.
 */
final class EmitirFactura
{
    public function ejecutar(Pedido $pedido, User $usuario): Factura
    {
        if (! DatosFiscales::actual()->estanCompletos()) {
            throw new ReglaNegocioException('Faltan los datos fiscales (razón social, RTN y dirección). Llénelos en Administración > CAI y datos fiscales.');
        }

        $cai = Cai::paraBodega($pedido->bodega_id, bloquear: true);

        if ($cai === null) {
            throw new ReglaNegocioException("La bodega {$pedido->bodega->nombre} no tiene un CAI vigente (sin configurar, vencido o sin números disponibles). Configúrelo en Administración > CAI y datos fiscales.");
        }

        $correlativo = $cai->siguiente;

        // Última defensa: nunca un número fuera del rango autorizado por el SAR.
        if (! $cai->enRango($correlativo)) {
            throw new ReglaNegocioException("El CAI {$cai->cai} tiene el siguiente número ({$correlativo}) fuera de su rango autorizado. Revíselo en Administración > CAI y datos fiscales.");
        }

        $cai->siguiente = $correlativo + 1;
        $cai->save();

        [$exento, $gravado] = self::importes($pedido);

        return Factura::query()->create([
            'pedido_id'       => $pedido->id,
            'bodega_id'       => $pedido->bodega_id,
            'cai_id'          => $cai->id,
            'numero'          => $cai->numero($correlativo),
            'correlativo'     => $correlativo,
            'cai'             => $cai->cai,
            'rango_desde'     => $cai->numero($cai->rango_desde),
            'rango_hasta'     => $cai->numero($cai->rango_hasta),
            'fecha_limite'    => $cai->fecha_limite,
            'fecha_emision'   => now(),
            'cliente_nombre'  => $pedido->cliente->nombre,
            'cliente_rtn'     => $pedido->cliente->rtn,
            'subtotal'        => $pedido->subtotal,
            'descuento'       => $pedido->descuento,
            'importe_exento'  => $exento,
            'importe_gravado' => $gravado,
            'isv'             => $pedido->isv,
            'total'           => $pedido->total,
            'reemplaza_a_id'  => $pedido->facturaPorSustituir()?->id,
            'emitida_por'     => $usuario->id,
        ]);
    }

    /**
     * Lo exento y lo gravado (15%), ya con el descuento de promociones.
     *
     * @return array{0: float, 1: float}
     */
    public static function importes(Pedido $pedido): array
    {
        $exento = 0.0;
        $gravado = 0.0;

        foreach ($pedido->items as $item) {
            $neto = round($item->subtotal - $item->descuento, 2);

            if ($item->gravado) {
                $gravado += $neto;
            } else {
                $exento += $neto;
            }
        }

        return [round($exento, 2), round($gravado, 2)];
    }
}
