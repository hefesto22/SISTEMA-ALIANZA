<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UnidadVenta;
use App\Models\Producto;
use App\Models\Promocion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Por defecto solo trae el producto de regalo (gratis). Las condiciones
 * se agregan con para() y con yCompra():
 *
 *   Promocion::factory()->para($toffee, $nutrine)->yCompra($otro, 2)->create();
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
            'beneficio_unidad'      => UnidadVenta::Unidad,
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
        UnidadVenta $unidadCondicion = UnidadVenta::Unidad,
    ): static {
        return $this
            ->state(fn (array $attributes): array => [
                'producto_beneficio_id' => $beneficio->id,
                'cantidad_beneficio'    => $regalo,
                'descuento_porcentaje'  => $porcentaje,
            ])
            ->yCompra($condicion, $cantidad, $unidadCondicion);
    }

    /**
     * Agrega otro producto a la condición.
     */
    public function yCompra(Producto $producto, int $cantidad = 1, UnidadVenta $unidad = UnidadVenta::Unidad): static
    {
        return $this->afterCreating(function (Promocion $promocion) use ($producto, $cantidad, $unidad): void {
            $promocion->condiciones()->create([
                'producto_id' => $producto->id,
                'cantidad'    => $cantidad,
                'unidad'      => $unidad,
            ]);
        });
    }

    public function inactiva(): static
    {
        return $this->state(fn (array $attributes): array => ['activa' => false]);
    }
}
