<?php

declare(strict_types=1);

namespace App\Domain\Comisiones;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Models\ComisionFactura;
use App\Models\ComisionLiquidacion;
use App\Models\ComisionPeriodo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cierra (paga) un periodo de comisiones: guarda lo de cada vendedor en
 * cada grupo, con la escala de ese día, y qué facturas entraron. Después
 * ya no cambia aunque se corrijan facturas o cambien las escalas.
 *
 *  - Se cierra el último día del periodo o después.
 *  - No se cierra uno que ya está cerrado ni uno anterior a otro cerrado
 *    (el orden importa: lo rezagado y lo anulado pasan al siguiente).
 *  - La segunda quincena, después de la primera: la meta es del mes y se
 *    paga lo del mes menos el adelanto (salvo que la primera sea de antes
 *    del primer cierre: esa no cuenta).
 *  - De uno en uno (dos cierres al mismo tiempo no pagan doble).
 *  - Las devoluciones con crédito que se descuentan quedan anotadas (tipo
 *    devolución, en negativo): no se vuelven a descontar.
 */
final class CerrarPeriodoComision
{
    /** pg_advisory_xact_lock: un solo cierre o reapertura a la vez. */
    public const CANDADO = 470_118_001;

    public function ejecutar(PeriodoComision $periodo, User $usuario, ?string $nota = null, ?CarbonImmutable $hoy = null): ComisionPeriodo
    {
        if (! $periodo->sePuedeCerrar($hoy)) {
            throw new ReglaNegocioException('El periodo del '.$periodo->texto().' termina el '.$periodo->hasta->format('d/m/Y').': se cierra ese día o después.');
        }

        return DB::transaction(function () use ($periodo, $usuario, $nota): ComisionPeriodo {
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::CANDADO]);

            if (ComisionPeriodo::query()->whereDate('desde', '<=', $periodo->hasta->toDateString())->whereDate('hasta', '>=', $periodo->desde->toDateString())->exists()) {
                throw new ReglaNegocioException('El periodo del '.$periodo->texto().' ya está cerrado (o se cruza con uno cerrado).');
            }

            if (ComisionPeriodo::query()->whereDate('desde', '>', $periodo->desde->toDateString())->exists()) {
                throw new ReglaNegocioException('Ya se cerró un periodo posterior: no se puede cerrar uno anterior.');
            }

            $calculo = new ComisionesDelPeriodo($periodo);
            $anterior = $calculo->anteriorDelMes();

            if ($anterior !== null && $anterior['estado'] === ComisionesDelPeriodo::ANTERIOR_POR_CERRAR) {
                throw new ReglaNegocioException('Primero cierre la quincena del '.$anterior['periodo']->texto().': la meta es del mes y lo de esa quincena se suma aquí.');
            }

            $detalle = $calculo->detalle();
            $vendedores = $calculo->vendedores();
            $totales = $calculo->totales();
            $ahora = now();

            $cierre = ComisionPeriodo::query()->create([
                'desde'       => $periodo->desde->toDateString(),
                'hasta'       => $periodo->hasta->toDateString(),
                'frecuencia'  => $periodo->frecuencia,
                'venta'       => $totales['venta'],
                'comision'    => $totales['comision'],
                'facturas'    => count(array_unique(array_column($detalle['ventas'], 'factura_id'))),
                'cerrado_at'  => $ahora,
                'cerrado_por' => $usuario->id,
                'nota'        => filled($nota) ? trim((string) $nota) : null,
            ]);

            foreach ($vendedores as $vendedor) {
                foreach ($vendedor->filas as $fila) {
                    if ($fila->venta == 0.0 && $fila->anulado == 0.0 && $fila->devuelto == 0.0 && $fila->comision == 0.0) {
                        continue;
                    }

                    ComisionLiquidacion::query()->create([
                        'comision_periodo_id' => $cierre->id,
                        'vendedor_id'         => $vendedor->id,
                        'comision_grupo_id'   => $fila->grupoId,
                        'grupo'               => $fila->grupo,
                        'venta'               => $fila->venta,
                        'anulado'             => $fila->anulado,
                        'devuelto'            => $fila->devuelto,
                        'base'                => $fila->base,
                        'base_antes'          => $fila->baseAntes,
                        'pagado_antes'        => $fila->pagadoAntes,
                        'porcentaje'          => $fila->porcentaje,
                        'comision'            => $fila->comision,
                        'meta'                => $fila->meta,
                        'escalones'           => $fila->escala->paraGuardar(),
                        'escala_propia'       => $fila->propia,
                        'escala_bodega'       => $fila->deBodega,
                        'facturas'            => $fila->facturas,
                    ]);
                }
            }

            $filas = [
                ...array_map(fn (array $f): array => [
                    'comision_periodo_id' => $cierre->id,
                    'factura_id'          => $f['factura_id'],
                    'devolucion_id'       => null,
                    'vendedor_id'         => $f['vendedor_id'],
                    'comision_grupo_id'   => $f['grupo_id'],
                    'tipo'                => ComisionFactura::VENTA,
                    'neta'                => $f['neta'],
                    'created_at'          => $ahora,
                    'updated_at'          => $ahora,
                ], $detalle['ventas']),
                ...array_map(fn (array $f): array => [
                    'comision_periodo_id' => $cierre->id,
                    'factura_id'          => $f['factura_id'],
                    'devolucion_id'       => null,
                    'vendedor_id'         => $f['vendedor_id'],
                    'comision_grupo_id'   => $f['grupo_id'],
                    'tipo'                => ComisionFactura::ANULADA,
                    'neta'                => -$f['neta'],
                    'created_at'          => $ahora,
                    'updated_at'          => $ahora,
                ], $detalle['anuladas']),
                ...array_map(fn (array $f): array => [
                    'comision_periodo_id' => $cierre->id,
                    'factura_id'          => $f['factura_id'],
                    'devolucion_id'       => $f['devolucion_id'],
                    'vendedor_id'         => $f['vendedor_id'],
                    'comision_grupo_id'   => $f['grupo_id'],
                    'tipo'                => ComisionFactura::DEVOLUCION,
                    'neta'                => -$f['neta'],
                    'created_at'          => $ahora,
                    'updated_at'          => $ahora,
                ], $detalle['devoluciones']),
            ];

            foreach (array_chunk($filas, 500) as $lote) {
                ComisionFactura::query()->insert($lote);
            }

            return $cierre;
        });
    }
}
