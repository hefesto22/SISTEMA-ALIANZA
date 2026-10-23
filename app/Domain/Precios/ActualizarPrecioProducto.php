<?php

declare(strict_types=1);

namespace App\Domain\Precios;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\MotivoCambioPrecio;
use App\Models\CambioPrecio;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ÚNICA puerta para cambiar el costo o el precio de un producto.
 *
 * Calcula el precio con el margen que le toca (salvo que se mande un
 * precio a mano), lo guarda en el producto —que es el mismo para todas
 * las bodegas— y deja el renglón en el historial con quién, cuándo,
 * desde qué bodega y de cuánto a cuánto.
 *
 * Si no cambió nada, no escribe historial y devuelve null.
 */
final class ActualizarPrecioProducto
{
    private const TOLERANCIA = 0.00005;

    public function ejecutar(
        Producto $producto,
        float $costoUnidad,
        MotivoCambioPrecio $motivo,
        ?User $usuario = null,
        ?float $precioUnidad = null,
        ?string $nota = null,
    ): ?CambioPrecio {
        $margen = $producto->margenAplicado();
        $costoUnidad = round($costoUnidad, 4);

        $precioNuevo = $precioUnidad === null
            ? CalculadoraPrecio::precioVenta($costoUnidad, $margen)
            : round($precioUnidad, 4);

        if ($precioNuevo <= 0) {
            throw new ReglaNegocioException("El precio de {$producto->codigo} debe ser mayor que cero.");
        }

        $esPrimerPrecio = $producto->precio_actualizado_at === null && $motivo === MotivoCambioPrecio::CargaInicial;
        $sinCambios = abs($costoUnidad - $producto->costo_unidad) < self::TOLERANCIA
            && abs($precioNuevo - $producto->precio_unidad) < self::TOLERANCIA;

        if ($sinCambios && ! $esPrimerPrecio) {
            return null;
        }

        // Si el precio se puso a mano, se guarda el margen que realmente deja.
        $margenGuardado = $precioUnidad === null ? $margen : CalculadoraPrecio::margenReal($costoUnidad, $precioNuevo);

        return DB::transaction(function () use ($producto, $costoUnidad, $precioNuevo, $margenGuardado, $motivo, $usuario, $nota): CambioPrecio {
            $cambio = CambioPrecio::query()->create([
                'producto_id'     => $producto->id,
                'user_id'         => $usuario?->id,
                'bodega_id'       => $usuario?->bodega_id,
                'motivo'          => $motivo,
                'costo_anterior'  => $producto->precio_actualizado_at === null ? null : $producto->costo_unidad,
                'costo_nuevo'     => $costoUnidad,
                'precio_anterior' => $producto->precio_actualizado_at === null ? null : $producto->precio_unidad,
                'precio_nuevo'    => $precioNuevo,
                'margen'          => $margenGuardado,
                'nota'            => $nota,
            ]);

            $producto->forceFill([
                'costo_unidad'           => $costoUnidad,
                'precio_unidad'          => $precioNuevo,
                'precio_actualizado_at'  => now(),
                'precio_actualizado_por' => $usuario?->id,
            ])->save();

            return $cambio;
        });
    }
}
