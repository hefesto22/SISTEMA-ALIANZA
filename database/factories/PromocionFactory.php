<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\Promocion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/**
 * Por defecto solo trae el producto de regalo (gratis, en su UN). Las
 * condiciones se agregan con para() y con yCompra() (en la UN o, con
 * $enCaja, en su presentación más grande):
 *
 *   Promocion::factory()->para($toffee, $nutrine)->yCompra($otro, 2, enCaja: true)->create();
 *
 * @extends Factory<Promocion>
 */
class PromocionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre'                => 'Promo '.fake()->word(),
            'producto_beneficio_id' => Producto::factory(),
            'cantidad_beneficio'    => 1,
            'descuento_porcentaje'  => 100,
            'activa'                => true,
        ];
    }

    /**
     * Si compra $cantidad de $condicion, $regalo de $beneficio sale con $porcentaje de descuento.
     */
    public function para(
        Producto $condicion,
        Producto $beneficio,
        int $cantidad = 1,
        int $regalo = 1,
        float $porcentaje = 100,
        bool $enCaja = false,
        bool $regaloEnCaja = false,
    ): static {
        return $this
            ->state(fn (array $attributes): array => [
                'producto_beneficio_id'     => $beneficio->id,
                'beneficio_presentacion_id' => self::presentacion($beneficio, $regaloEnCaja)->id,
                'cantidad_beneficio'        => $regalo,
                'descuento_porcentaje'      => $porcentaje,
            ])
            ->yCompra($condicion, $cantidad, $enCaja);
    }

    /**
     * Agrega otro producto a la condición.
     */
    public function yCompra(Producto $producto, int $cantidad = 1, bool $enCaja = false): static
    {
        return $this->afterCreating(function (Promocion $promocion) use ($producto, $cantidad, $enCaja): void {
            $promocion->condiciones()->create([
                'producto_id'     => $producto->id,
                'presentacion_id' => self::presentacion($producto, $enCaja)->id,
                'cantidad'        => $cantidad,
            ]);
        });
    }

    public function inactiva(): static
    {
        return $this->state(fn (array $attributes): array => ['activa' => false]);
    }

    /**
     * La UN o (en caja) la presentación más grande del producto.
     */
    private static function presentacion(Producto $producto, bool $enCaja): Presentacion
    {
        $presentacion = Presentacion::query()
            ->where('producto_id', $producto->id)
            ->when($enCaja, fn (Builder $query): Builder => $query->orderByDesc('contenido'), fn (Builder $query): Builder => $query->where('es_base', true))
            ->first();

        return $presentacion ?? throw new LogicException("{$producto->codigo} no tiene presentación.");
    }
}
