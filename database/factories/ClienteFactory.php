<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bodega;
use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cliente>
 */
class ClienteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre'    => 'Pulpería '.fake()->lastName(),
            'telefono'  => fake()->numerify('9#######'),
            'direccion' => fake()->streetAddress(),
            'bodega_id' => Bodega::factory(),
            'activo'    => true,
        ];
    }
}
