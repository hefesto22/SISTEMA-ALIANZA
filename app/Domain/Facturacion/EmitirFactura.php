<?php

declare(strict_types=1);

namespace App\Domain\Facturacion;

use App\Domain\Cargas\ArmarCarga;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Models\Cai;
use App\Models\DatosFiscales;
use App\Models\Factura;
use App\Models\Pedido;
use App\Models\Ruta;
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

        $factura = Factura::query()->create(self::datos($pedido, $cai, $correlativo) + ['emitida_por' => $usuario->id]);

        // La ruta del pedido queda fija desde su primera factura.
        if ($pedido->ruta_id === null) {
            $pedido->forceFill(['ruta_id' => Ruta::delPedido($pedido)?->id])->saveQuietly();
        }

        // Si sustituye a una que iba en un camión, sube a la misma carga.
        ArmarCarga::subirSustituta($factura);

        return $factura;
    }

    /**
     * La factura como saldría si se imprime ahora, con el siguiente número
     * del CAI, sin guardarla ni gastar el número: para la vista previa del
     * modal de Imprimir. Con $despues, la que sale después de otras tantas
     * del mismo CAI (varias de un solo). Null si la bodega no tiene CAI vigente.
     */
    public static function borrador(Pedido $pedido, int $despues = 0): ?Factura
    {
        $cai = Cai::paraBodega($pedido->bodega_id);

        if ($cai === null) {
            return null;
        }

        return (new Factura)
            ->forceFill(self::datos($pedido, $cai, $cai->siguiente + $despues))
            ->setRelation('reemplazaA', $pedido->facturaPorSustituir());
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

    /**
     * Lo que se guarda de la factura: el número del CAI y los importes del pedido.
     *
     * @return array<string, mixed>
     */
    private static function datos(Pedido $pedido, Cai $cai, int $correlativo): array
    {
        [$exento, $gravado] = self::importes($pedido);

        return [
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
        ];
    }
}
