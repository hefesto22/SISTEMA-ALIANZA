<?php

declare(strict_types=1);

namespace App\Domain\Inventario;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\TipoMovimiento;
use App\Models\Configuracion;
use App\Models\Existencia;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\User;

/**
 * ÚNICA puerta para cambiar la existencia de un producto en una bodega:
 * suma o resta UNIDADES y deja el renglón en el kárdex con lo que quedó.
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
     * @param int $unidades positivo entra, negativo sale
     */
    public function ejecutar(
        int $bodegaId,
        Producto $producto,
        int $unidades,
        TipoMovimiento $tipo,
        ?User $usuario = null,
        ?int $compraId = null,
        ?int $pedidoId = null,
        ?float $costoUnidad = null,
        ?string $nota = null,
    ): MovimientoInventario {
        $existencia = self::bloquear($bodegaId, $producto->id);
        $queda = $existencia->cantidad + $unidades;

        if ($unidades < 0 && $queda < 0 && Configuracion::actual()->controlar_existencias) {
            throw new ReglaNegocioException(sprintf(
                'No alcanza %s en la bodega: hay %s y salen %s.',
                $producto->etiqueta(),
                Existencia::enCajas($existencia->cantidad, $producto->unidades_por_caja),
                Existencia::enCajas(-$unidades, $producto->unidades_por_caja),
            ));
        }

        $existencia->cantidad = $queda;
        $existencia->save();

        return MovimientoInventario::query()->create([
            'bodega_id'    => $bodegaId,
            'producto_id'  => $producto->id,
            'tipo'         => $tipo,
            'cantidad'     => $unidades,
            'existencia'   => $queda,
            'costo_unidad' => round($costoUnidad ?? $producto->costo_unidad, 4),
            'compra_id'    => $compraId,
            'pedido_id'    => $pedidoId,
            'user_id'      => $usuario?->id,
            'nota'         => $nota,
        ]);
    }

    /**
     * La fila de existencia bloqueada hasta que termine la transacción (la
     * crea en 0 si el producto nunca ha estado en esa bodega).
     */
    public static function bloquear(int $bodegaId, int $productoId): Existencia
    {
        Existencia::query()->insertOrIgnore([
            'bodega_id'   => $bodegaId,
            'producto_id' => $productoId,
            'cantidad'    => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return Existencia::query()
            ->where('bodega_id', $bodegaId)
            ->where('producto_id', $productoId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
