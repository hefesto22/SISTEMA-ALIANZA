<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bodega;
use App\Models\Municipio;
use App\Models\Ruta;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ruta>
 */
class RutaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bodega_id' => Bodega::factory(),
            'nombre'    => 'Ruta '.fake()->unique()->numerify('###'),
            'activa'    => true,
        ];
    }

    /**
     * Municipios que cubre la ruta.
     */
    public function cubre(Municipio ...$municipios): static
    {
        return $this->afterCreating(function (Ruta $ruta) use ($municipios): void {
            $ruta->municipios()->attach(array_map(fn (Municipio $m): int => $m->id, $municipios));
        });
    }

    /**
     * Vendedores que la trabajan.
     */
    public function para(User ...$vendedores): static
    {
        return $this->afterCreating(function (Ruta $ruta) use ($vendedores): void {
            $ruta->vendedores()->attach(array_map(fn (User $u): int => $u->id, $vendedores));
        });
    }

    public function inactiva(): static
    {
        return $this->state(['activa' => false]);
    }
}
