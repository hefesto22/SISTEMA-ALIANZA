<?php

declare(strict_types=1);

namespace App\Domain\Cargas;

use App\Enums\TipoDevolucion;
use App\Models\Carga;
use App\Models\CargaFactura;

/**
 * El cuadre de una carga al regresar, con lo que se decidió de cada factura
 * y lo que trajo:
 *
 *   entregado   = lo de las facturas que se entregaron
 *   no entregado= lo de las que se anulan o salen en otra carga
 *   anuladas    = las que se anularon ese día al corregir el pedido (las
 *                 sustituye otra factura, que va en la misma carga)
 *   devuelto    = lo de las devoluciones con dinero devuelto (crédito)
 *                 registradas mientras iban en el camión
 *   debe traer  = entregado − devuelto
 *   trajo       = efectivo + cheques + transferencias
 *   diferencia  = trajo − debe traer (negativa: falta; positiva: sobra)
 *
 * Lo usa la pantalla de liquidar (en vivo) y LiquidarCarga (al guardar).
 */
final class CuentaCarga
{
    public const ENTREGADA = 'entregada';

    public const ANULAR = 'anular';

    public const OTRA_CARGA = 'otra_carga';

    /**
     * @param array<int, string> $entregas [factura_id => entregada|anular|otra_carga]; si falta, entregada
     * @param list<float> $cheques montos
     * @param list<float> $transferencias montos
     *
     * @return array{facturas: int, entregadas: int, salio: float, entregado: float, no_entregado: float, anuladas: float, devuelto: float, debe_traer: float, efectivo: float, cheques: float, transferencias: float, trajo: float, diferencia: float}
     */
    public static function de(Carga $carga, array $entregas, float $efectivo, array $cheques, array $transferencias): array
    {
        $carga->loadMissing('renglones.factura');

        $cuenta = ['facturas' => 0, 'entregadas' => 0, 'entregado' => 0.0, 'no_entregado' => 0.0, 'anuladas' => 0.0];

        /** @var CargaFactura $renglon */
        foreach ($carga->renglones as $renglon) {
            $factura = $renglon->factura;
            $cuenta['facturas']++;

            if ($factura->estaAnulada()) {
                $cuenta['anuladas'] += $factura->total;

                continue;
            }

            if (($entregas[$factura->id] ?? self::ENTREGADA) === self::ENTREGADA) {
                $cuenta['entregadas']++;
                $cuenta['entregado'] += $factura->total;
            } else {
                $cuenta['no_entregado'] += $factura->total;
            }
        }

        $devuelto = (float) $carga->devoluciones()->where('tipo', TipoDevolucion::Credito->value)->sum('total');
        $cheque = array_sum(array_map(fn (float $monto): float => round($monto, 2), $cheques));
        $transferencia = array_sum(array_map(fn (float $monto): float => round($monto, 2), $transferencias));
        $efectivo = round(max(0.0, $efectivo), 2);
        $debeTraer = round($cuenta['entregado'] - $devuelto, 2);
        $trajo = round($efectivo + $cheque + $transferencia, 2);

        return [
            'facturas'       => $cuenta['facturas'],
            'entregadas'     => $cuenta['entregadas'],
            'salio'          => round($carga->total_carga, 2),
            'entregado'      => round($cuenta['entregado'], 2),
            'no_entregado'   => round($cuenta['no_entregado'], 2),
            'anuladas'       => round($cuenta['anuladas'], 2),
            'devuelto'       => round($devuelto, 2),
            'debe_traer'     => $debeTraer,
            'efectivo'       => $efectivo,
            'cheques'        => round($cheque, 2),
            'transferencias' => round($transferencia, 2),
            'trajo'          => $trajo,
            'diferencia'     => round($trajo - $debeTraer, 2),
        ];
    }

    /**
     * "Cuadra", "Falta L 120.00", "Sobra L 15.00".
     */
    public static function texto(float $diferencia): string
    {
        return match (true) {
            abs($diferencia) < 0.005 => 'Cuadra',
            $diferencia < 0          => 'Falta '.lempiras(abs($diferencia)),
            default                  => 'Sobra '.lempiras($diferencia),
        };
    }
}
