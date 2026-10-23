<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UnidadVenta;
use App\Models\Producto;
use App\Models\Promocion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Por defecto: compra 1 unidad del producto condición → 1 unidad del de regalo gratis.
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
            'producto_condicion_id' => Producto::factory(),
            'cantidad_condicion'    => 1,
            'condicion_unidad'      => UnidadVenta::Unidad,
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
    public function para(Producto $condicion, Producto $beneficio, int $cantidad = 1, int $regalo = 1, float $porcentaje = 100): static
    {
        return $this->state(fn (array $attributes): array => [
            'producto_condicion_id' => $condicion->id,
            'cantidad_condicion'    => $cantidad,
            'producto_beneficio_id' => $beneficio->id,
            'cantidad_beneficio'    => $regalo,
            'descuento_porcentaje'  => $porcentaje,
        ]);
    }

    public function inactiva(): static
    {
        return $this->state(fn (array $attributes): array => ['activa' => false]);
    }
}
