<?php

declare(strict_types=1);

namespace App\Domain\Precios;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\MotivoCambioPrecio;
use App\Models\CambioPrecio;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ÚNICA puerta para cambiar el costo o los precios de un producto.
 *
 * El costo es uno solo, por unidad base y sin ISV. Cada presentación (UN,
 * CJ de 12, FD de 6…) tiene su precio, de la presentación completa y sin
 * ISV, que sale del costo × lo que trae con el margen que le toca, salvo
 * que se mande un precio a mano o que la presentación tenga precio fijo
 * (ahí se queda el que tiene: una compra o un cambio de margen no lo
 * mueven). Sin costo todavía (producto nuevo sin compras) un precio
 * calculado se queda como está.
 *
 * Lo guarda en el producto y en sus presentaciones —que son los mismos
 * para todas las bodegas— y deja en el historial un renglón por cada
 * presentación que se vende y cambió (o todas, si cambió el costo): quién,
 * cuándo, desde qué bodega y de cuánto a cuánto.
 *
 * Si no cambió nada no escribe historial y devuelve [] (si solo cambió lo
 * de precio fijo, lo guarda sin historial).
 */
final class ActualizarPrecioProducto
{
    private const TOLERANCIA = 0.00005;

    /** Medio centavo: lo que se mueve un precio calculado solo por redondeo. */
    private const REDONDEO = 0.005;

    /**
     * @param array<int, array{precio?: float|null, fijo?: bool|null}> $precios por presentación (id): precio sin ISV de la presentación completa (null = el que le toca) y si queda fijo (null = como está)
     *
     * @return list<CambioPrecio> lo que quedó en el historial
     */
    public function ejecutar(
        Producto $producto,
        float $costoUnidad,
        MotivoCambioPrecio $motivo,
        ?User $usuario = null,
        array $precios = [],
        ?string $nota = null,
    ): array {
        $producto->loadMissing(['categoria', 'presentaciones.unidadMedida']);

        $margen = $producto->margenAplicado();
        $costoUnidad = round($costoUnidad, 4);
        $primera = $producto->precio_actualizado_at === null;
        $cambiaCosto = abs($costoUnidad - $producto->costo_unidad) >= self::TOLERANCIA;

        if ($costoUnidad < 0) {
            throw new ReglaNegocioException("El costo de {$producto->codigo} no puede ser negativo.");
        }

        $planes = [];

        foreach ($producto->presentaciones as $presentacion) {
            $pide = $precios[$presentacion->id] ?? [];
            $dado = isset($pide['precio']) ? round($pide['precio'], 4) : null;
            $fijo = $pide['fijo'] ?? $presentacion->precio_fijo;

            // Fijo y sin precio nuevo: se queda el que tiene.
            $mantiene = $dado === null && $fijo && $presentacion->precio > 0;

            $nuevo = match (true) {
                $dado !== null   => $dado,
                $mantiene        => $presentacion->precio,
                $costoUnidad > 0 => CalculadoraPrecio::precioVenta(round($costoUnidad * $presentacion->contenido, 4), $margen),
                default          => $presentacion->precio,
            };

            // Calculado que se mueve menos de medio centavo: es solo redondeo
            // (p. ej. la CJ12 guardada como 12 × la UN contra el costo × 12
            // con el %); se queda el que tiene y no deja historial.
            if ($dado === null && ! $mantiene && $presentacion->precio > 0 && abs($nuevo - $presentacion->precio) < self::REDONDEO) {
                $nuevo = $presentacion->precio;
            }

            if ($dado !== null && $dado <= 0) {
                throw new ReglaNegocioException("El precio de {$presentacion->sigla()} de {$producto->codigo} debe ser mayor que cero.");
            }

            $planes[] = [
                'presentacion' => $presentacion,
                'precio'       => $nuevo,
                'fijo'         => $fijo,
                'aMano'        => $dado !== null || $mantiene || $costoUnidad <= 0,
                'mantiene'     => $mantiene,
                'cambia'       => abs($nuevo - $presentacion->precio) >= self::TOLERANCIA,
            ];
        }

        $cambiaAlgo = $cambiaCosto || collect($planes)->contains(fn (array $plan): bool => $plan['cambia']);

        return DB::transaction(function () use ($producto, $costoUnidad, $margen, $motivo, $usuario, $nota, $planes, $primera, $cambiaCosto, $cambiaAlgo): array {
            $cambios = [];

            foreach ($planes as $plan) {
                /** @var Presentacion $presentacion */
                $presentacion = $plan['presentacion'];

                if ($presentacion->activo && ($primera || $cambiaCosto || $plan['cambia'])) {
                    $costoNuevo = round($costoUnidad * $presentacion->contenido, 4);

                    // Precio a mano (o el fijo que se quedó): se guarda el margen que de verdad deja.
                    // (Topado a lo que cabe en el historial: muy por debajo del costo da un margen muy negativo.)
                    $margenGuardado = $plan['aMano'] ? max(-99.9999, CalculadoraPrecio::margenReal($costoNuevo, $plan['precio'])) : $margen;

                    $cambios[] = CambioPrecio::query()->create([
                        'producto_id'     => $producto->id,
                        'presentacion_id' => $presentacion->id,
                        'user_id'         => $usuario?->id,
                        'bodega_id'       => $usuario?->bodega_id,
                        'motivo'          => $motivo,
                        'costo_anterior'  => $primera ? null : round($producto->costo_unidad * $presentacion->contenido, 4),
                        'costo_nuevo'     => $costoNuevo,
                        'precio_anterior' => $primera ? null : $presentacion->precio,
                        'precio_nuevo'    => $plan['precio'],
                        'margen'          => $margenGuardado,
                        'nota'            => $plan['mantiene'] ? ($nota === null ? 'PRECIO FIJO' : "{$nota} · PRECIO FIJO") : $nota,
                    ]);
                }

                if ($plan['cambia'] || $plan['fijo'] !== $presentacion->precio_fijo) {
                    $presentacion->forceFill(['precio' => $plan['precio'], 'precio_fijo' => $plan['fijo']])->save();
                }
            }

            if ($cambiaAlgo || $primera) {
                $producto->forceFill([
                    'costo_unidad'           => $costoUnidad,
                    'precio_actualizado_at'  => now(),
                    'precio_actualizado_por' => $usuario?->id,
                ])->save();
            }

            $producto->unsetRelation('presentaciones');

            return $cambios;
        });
    }
}
