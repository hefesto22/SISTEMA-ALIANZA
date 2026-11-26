<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bodega;
use App\Models\Carga;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Carga en ruta de hoy, sin facturas (se arman con App\Domain\Cargas\ArmarCarga).
 *
 * @extends Factory<Carga>
 */
class CargaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bodega_id'     => Bodega::factory(),
            'fecha'         => today()->toDateString(),
            'repartidor_id' => User::factory(),
            'estado'        => 'en_ruta',
            'total_carga'   => 0,
        ];
    }
}
