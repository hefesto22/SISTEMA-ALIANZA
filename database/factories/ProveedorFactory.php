<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proveedor>
 */
class ProveedorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo' => (string) fake()->unique()->numberBetween(600000, 699999),
            'nombre' => strtoupper(fake()->company()),
            'activo' => true,
        ];
    }
}
