<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Departamento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Departamento de prueba (los reales los carga DivisionPoliticaSeeder).
 *
 * @extends Factory<Departamento>
 */
class DepartamentoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo' => null,
            'nombre' => 'Departamento '.fake()->unique()->numerify('#####'),
        ];
    }
}
