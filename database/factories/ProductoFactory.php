<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Crea el producto con costo y precio ya cargados (margen de la categoría).
 *
 * @extends Factory<Producto>
 */
class ProductoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'categoria_id'          => Categoria::factory(),
            'proveedor_id'          => fn (array $attributes): int => (int) Categoria::query()->whereKey($attributes['categoria_id'])->value('proveedor_id'),
            'codigo'                => (string) fake()->unique()->numerify('##########'),
            'descripcion'           => mb_strtoupper(fake()->word().' '.fake()->word()).' 12X100G',
            'unidades_por_caja'     => 12,
            'gravado'               => true,
            'margen'                => null,
            'costo_unidad'          => 50.0,
            'precio_unidad'         => 59.5238,
            'precio_actualizado_at' => now(),
            'activo'                => true,
        ];
    }

    public function exento(): static
    {
        return $this->state(fn (array $attributes): array => ['gravado' => false]);
    }

    public function precio(float $costo, float $precio): static
    {
        return $this->state(fn (array $attributes): array => [
            'costo_unidad'  => $costo,
            'precio_unidad' => $precio,
        ]);
    }
}
