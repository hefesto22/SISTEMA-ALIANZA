<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Departamento;
use App\Models\Municipio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Municipio>
 */
class MunicipioFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'departamento_id' => Departamento::factory(),
            'nombre'          => 'Municipio '.fake()->unique()->numerify('#####'),
        ];
    }
}
