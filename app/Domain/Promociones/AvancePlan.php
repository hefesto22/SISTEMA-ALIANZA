<?php

declare(strict_types=1);

namespace App\Domain\Promociones;

use App\Models\PlanPromocion;
use App\Models\Promocion;
use App\Models\PromocionCondicion;

/**
 * Cómo va un plan del proveedor, línea por línea: cuánto regalo autorizó,
 * cuánto ya se entregó (pedidos impresos), cuánto está comprometido
 * (pedidos sin imprimir) y cuánto queda; y lo mismo en lempiras, al costo de
 * hoy del regalo (lo que el proveedor le reconoce a la distribuidora).
 * También compara el % que da la mecánica con el autorizado en la hoja.
 */
final class AvancePlan
{
    /**
     * @return array{
     *     lineas: list<array{id: int, codigo: string, mecanica: string, productos: list<string>, regalo: string, sigla: string, activa: bool, tope: int, entregadas: int, comprometidas: int, quedan: int, usado: float, costo: float, autorizado: float, invertido: float, porcentaje: float|null, porcentaje_autorizado: float|null, excede: bool}>,
     *     totales: array{tope: int, entregadas: int, comprometidas: int, quedan: int, usado: float, autorizado: float, invertido: float, comprometido: float}
     * }
     */
    public static function de(PlanPromocion $plan): array
    {
        $promociones = $plan->promociones()
            ->with(['condiciones.producto', 'condiciones.presentacion', 'productoBeneficio', 'beneficioPresentacion.unidadMedida'])
            ->get();

        $uso = UsoDePromociones::detalle($promociones->modelKeys());
        $lineas = [];
        $totales = ['tope' => 0, 'entregadas' => 0, 'comprometidas' => 0, 'quedan' => 0, 'usado' => 0.0, 'autorizado' => 0.0, 'invertido' => 0.0, 'comprometido' => 0.0];

        foreach ($promociones as $promocion) {
            $linea = self::linea($promocion, $uso[$promocion->id] ?? ['entregadas' => 0, 'comprometidas' => 0]);
            $lineas[] = $linea;

            $totales['tope'] += $linea['tope'];
            $totales['entregadas'] += $linea['entregadas'];
            $totales['comprometidas'] += $linea['comprometidas'];
            $totales['quedan'] += $linea['quedan'];
            $totales['autorizado'] += $linea['autorizado'];
            $totales['invertido'] += $linea['invertido'];
            $totales['comprometido'] += round($linea['comprometidas'] * $linea['costo'], 2);
        }

        $totales['autorizado'] = round($totales['autorizado'], 2);
        $totales['invertido'] = round($totales['invertido'], 2);
        $totales['comprometido'] = round($totales['comprometido'], 2);
        $totales['usado'] = self::porcentajeUsado($totales['tope'], $totales['entregadas'] + $totales['comprometidas']);

        return ['lineas' => $lineas, 'totales' => $totales];
    }

    /**
     * @param array{entregadas: int, comprometidas: int} $uso
     *
     * @return array{id: int, codigo: string, mecanica: string, productos: list<string>, regalo: string, sigla: string, activa: bool, tope: int, entregadas: int, comprometidas: int, quedan: int, usado: float, costo: float, autorizado: float, invertido: float, porcentaje: float|null, porcentaje_autorizado: float|null, excede: bool}
     */
    private static function linea(Promocion $promocion, array $uso): array
    {
        $tope = (int) $promocion->tope_unidades;
        $costo = round($promocion->beneficioPresentacion->costo, 4);
        $primera = $promocion->condiciones->first();
        $porcentaje = GuardarPlanPromocion::porcentaje((int) $promocion->compra_unidades, $promocion->cantidad_beneficio, $primera?->presentacion, $promocion->beneficioPresentacion);
        $autorizado = $promocion->porcentaje_autorizado;

        return [
            'id'                    => $promocion->id,
            'codigo'                => (string) $promocion->codigo,
            'mecanica'              => $promocion->mecanica(),
            'productos'             => array_values($promocion->condiciones->map(fn (PromocionCondicion $c): string => $c->producto->descripcion)->unique()->all()),
            'regalo'                => $promocion->productoBeneficio->descripcion,
            'sigla'                 => $promocion->beneficioPresentacion->sigla(),
            'activa'                => $promocion->activa,
            'tope'                  => $tope,
            'entregadas'            => $uso['entregadas'],
            'comprometidas'         => $uso['comprometidas'],
            'quedan'                => max(0, $tope - $uso['entregadas'] - $uso['comprometidas']),
            'usado'                 => self::porcentajeUsado($tope, $uso['entregadas'] + $uso['comprometidas']),
            'costo'                 => $costo,
            'autorizado'            => round($tope * $costo, 2),
            'invertido'             => round($uso['entregadas'] * $costo, 2),
            'porcentaje'            => $porcentaje,
            'porcentaje_autorizado' => $autorizado,
            // La mecánica regala bastante más (al costo) que lo autorizado: revisar la hoja (24+1 escrito como 12+1).
            'excede' => $porcentaje !== null && $autorizado !== null && $porcentaje > $autorizado + 0.25,
        ];
    }

    private static function porcentajeUsado(int $tope, int $usadas): float
    {
        if ($tope <= 0) {
            return $usadas > 0 ? 100.0 : 0.0;
        }

        return round(min(100, $usadas / $tope * 100), 1);
    }
}
