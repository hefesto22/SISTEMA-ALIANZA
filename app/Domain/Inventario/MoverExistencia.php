<?php

declare(strict_types=1);

namespace App\Domain\Inventario;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\TipoMovimiento;
use App\Models\Configuracion;
use App\Models\Existencia;
use App\Models\MovimientoInventario;
use App\Models\Presentacion;
use App\Models\User;

/**
 * ÚNICA puerta para cambiar la existencia de una presentación en una
 * bodega: suma o resta (en esa presentación: 2 = dos CJ48) y deja el
 * renglón en el kárdex con lo que quedó y su costo.
 *
 * Con "controlar existencias" encendido (Configuración) no deja que una
 * salida deje la bodega en negativo.
 *
 * Se llama dentro de una transacción: bloquea la fila de la existencia para
 * que dos despachos o compras al mismo tiempo no se pisen.
 */
final class MoverExistencia
{
    /**
     * @param int $cantidad en la presentación: positivo entra, negativo sale
     * @param float|null $costo de una de la presentación, sin ISV (null = su costo de hoy)
     */
    public function ejecutar(
        int $bodegaId,
        Presentacion $presentacion,
        int $cantidad,
        TipoMovimiento $tipo,
        ?User $usuario = null,
        ?int $compraId = null,
        ?int $pedidoId = null,
        ?float $costo = null,
        ?string $nota = null,
    ): MovimientoInventario {
        $existencia = self::bloquear($bodegaId, $presentacion);
        $queda = $existencia->cantidad + $cantidad;

        if ($cantidad < 0 && $queda < 0 && Configuracion::actual()->controlar_existencias) {
            throw new ReglaNegocioException(sprintf(
                'No alcanza %s en %s: hay %d y salen %d.',
                $presentacion->producto->etiqueta(),
                $presentacion->sigla(),
                $existencia->cantidad,
                -$cantidad,
            ));
        }

        $existencia->cantidad = $queda;
        $existencia->save();

        return MovimientoInventario::query()->create([
            'bodega_id'       => $bodegaId,
            'producto_id'     => $presentacion->producto_id,
            'presentacion_id' => $presentacion->id,
            'tipo'            => $tipo,
            'cantidad'        => $cantidad,
            'existencia'      => $queda,
            'costo_unidad'    => round($costo ?? $presentacion->costo, 4),
            'compra_id'       => $compraId,
            'pedido_id'       => $pedidoId,
            'user_id'         => $usuario?->id,
            'nota'            => $nota,
        ]);
    }

    /**
     * La fila de existencia bloqueada hasta que termine la transacción (la
     * crea en 0 si la presentación nunca ha estado en esa bodega).
     */
    public static function bloquear(int $bodegaId, Presentacion $presentacion): Existencia
    {
        Existencia::query()->insertOrIgnore([
            'bodega_id'       => $bodegaId,
            'producto_id'     => $presentacion->producto_id,
            'presentacion_id' => $presentacion->id,
            'cantidad'        => 0,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return Existencia::query()
            ->where('bodega_id', $bodegaId)
            ->where('presentacion_id', $presentacion->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
