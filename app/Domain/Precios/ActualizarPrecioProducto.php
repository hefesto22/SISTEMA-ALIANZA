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
 * ÚNICA puerta para cambiar los costos o los precios de un producto.
 *
 * Cada presentación (UN, CJ de 32, CJ de 48…) tiene su costo (promedio de
 * lo que se ha comprado de ella, de la presentación completa y sin ISV) y
 * su precio, también de la presentación completa y sin ISV, que sale de SU
 * costo con su % de utilidad (el propio o, si no tiene, el del producto o
 * su categoría), salvo que se mande un precio a mano o que tenga precio
 * fijo (ahí se queda el que tiene: una compra o un cambio de margen no lo
 * mueven). Sin costo todavía un precio calculado se queda como está.
 *
 * Los costos nuevos llegan en $costos (de una compra, de una caja que se
 * abrió o corregidos a mano); la que no viene se queda con el suyo.
 *
 * Lo guarda en las presentaciones —que son las mismas para todas las
 * bodegas— y deja en el historial un renglón por cada presentación que se
 * vende y le cambió el costo o el precio (todas la primera vez): quién,
 * cuándo, desde qué bodega y de cuánto a cuánto.
 *
 * Si no cambió nada no escribe historial y devuelve [] (si solo cambió lo
 * de precio fijo o un % que no mueve el precio, lo guarda sin historial).
 */
final class ActualizarPrecioProducto
{
    private const TOLERANCIA = 0.00005;

    /** Medio centavo: lo que se mueve un precio calculado solo por redondeo. */
    private const REDONDEO = 0.005;

    /**
     * @param array<int, array{precio?: float|null, fijo?: bool|null, margen?: float|null}> $precios por presentación (id): precio sin ISV de la presentación completa (null = el que le toca), si queda fijo (null = como está) y su % de utilidad propio (0.10 = 10 %; si viene null, el del producto; si no viene, el que tiene)
     * @param array<int, float> $costos por presentación (id): su costo nuevo sin ISV, de la presentación completa
     *
     * @return list<CambioPrecio> lo que quedó en el historial
     */
    public function ejecutar(
        Producto $producto,
        MotivoCambioPrecio $motivo,
        ?User $usuario = null,
        array $precios = [],
        ?string $nota = null,
        array $costos = [],
    ): array {
        $producto->loadMissing(['categoria', 'presentaciones.unidadMedida']);

        $margenProducto = $producto->margenAplicado();
        $primera = $producto->precio_actualizado_at === null;
        $planes = [];

        foreach ($producto->presentaciones as $presentacion) {
            $costo = round(array_key_exists($presentacion->id, $costos) ? $costos[$presentacion->id] : $presentacion->costo, 4);

            if ($costo < 0) {
                throw new ReglaNegocioException("El costo de {$presentacion->sigla()} de {$producto->codigo} no puede ser negativo.");
            }

            $pide = $precios[$presentacion->id] ?? [];
            $dado = isset($pide['precio']) ? round($pide['precio'], 4) : null;
            $fijo = $pide['fijo'] ?? $presentacion->precio_fijo;
            $propio = array_key_exists('margen', $pide) ? ($pide['margen'] === null ? null : round($pide['margen'], 4)) : $presentacion->margen;
            $margen = $propio ?? $margenProducto;

            if ($margen < 0 || $margen >= 1) {
                throw new ReglaNegocioException("La utilidad de {$presentacion->sigla()} de {$producto->codigo} debe ser de 0 % a 99.99 %.");
            }

            // Fijo y sin precio nuevo: se queda el que tiene.
            $mantiene = $dado === null && $fijo && $presentacion->precio > 0;

            $nuevo = match (true) {
                $dado !== null => $dado,
                $mantiene      => $presentacion->precio,
                $costo > 0     => CalculadoraPrecio::precioVenta($costo, $margen),
                default        => $presentacion->precio,
            };

            // Calculado que se mueve menos de medio centavo: es solo redondeo
            // (p. ej. la CJ12 guardada como 12 × la UN contra su costo con
            // el %); se queda el que tiene y no deja historial.
            if ($dado === null && ! $mantiene && $presentacion->precio > 0 && abs($nuevo - $presentacion->precio) < self::REDONDEO) {
                $nuevo = $presentacion->precio;
            }

            if ($dado !== null && $dado <= 0) {
                throw new ReglaNegocioException("El precio de {$presentacion->sigla()} de {$producto->codigo} debe ser mayor que cero.");
            }

            $planes[] = [
                'presentacion' => $presentacion,
                'costo'        => $costo,
                'precio'       => $nuevo,
                'fijo'         => $fijo,
                'propio'       => $propio,
                'margen'       => $margen,
                'aMano'        => $dado !== null || $mantiene || $costo <= 0,
                'mantiene'     => $mantiene,
                'cambiaCosto'  => abs($costo - $presentacion->costo) >= self::TOLERANCIA,
                'cambia'       => abs($nuevo - $presentacion->precio) >= self::TOLERANCIA,
            ];
        }

        $cambiaAlgo = collect($planes)->contains(fn (array $plan): bool => $plan['cambia'] || $plan['cambiaCosto']);

        return DB::transaction(function () use ($producto, $motivo, $usuario, $nota, $planes, $primera, $cambiaAlgo): array {
            $cambios = [];

            foreach ($planes as $plan) {
                /** @var Presentacion $presentacion */
                $presentacion = $plan['presentacion'];

                if ($presentacion->activo && ($primera || $plan['cambiaCosto'] || $plan['cambia'])) {
                    // Precio a mano (o el fijo que se quedó): se guarda el margen que de verdad deja.
                    // (Topado a lo que cabe en el historial: muy por debajo del costo da un margen muy negativo.)
                    $margenGuardado = $plan['aMano'] ? max(-99.9999, CalculadoraPrecio::margenReal($plan['costo'], $plan['precio'])) : $plan['margen'];

                    $cambios[] = CambioPrecio::query()->create([
                        'producto_id'     => $producto->id,
                        'presentacion_id' => $presentacion->id,
                        'user_id'         => $usuario?->id,
                        'bodega_id'       => $usuario?->bodega_id,
                        'motivo'          => $motivo,
                        'costo_anterior'  => $primera ? null : $presentacion->costo,
                        'costo_nuevo'     => $plan['costo'],
                        'precio_anterior' => $primera ? null : $presentacion->precio,
                        'precio_nuevo'    => $plan['precio'],
                        'margen'          => $margenGuardado,
                        'nota'            => $plan['mantiene'] ? ($nota === null ? 'PRECIO FIJO' : "{$nota} · PRECIO FIJO") : $nota,
                    ]);
                }

                if ($plan['cambia'] || $plan['cambiaCosto'] || $plan['fijo'] !== $presentacion->precio_fijo || $plan['propio'] !== $presentacion->margen) {
                    $presentacion->forceFill(['precio' => $plan['precio'], 'costo' => $plan['costo'], 'precio_fijo' => $plan['fijo'], 'margen' => $plan['propio']])->save();
                }
            }

            if ($cambiaAlgo || $primera) {
                $producto->forceFill([
                    'precio_actualizado_at'  => now(),
                    'precio_actualizado_por' => $usuario?->id,
                ])->save();
            }

            $producto->unsetRelation('presentaciones');

            return $cambios;
        });
    }
}
