<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlanPromocion;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Plan del mes de hoy, sin líneas (se arman con GuardarPlanPromocion o con
 * PromocionFactory y plan_id).
 *
 * @extends Factory<PlanPromocion>
 */
class PlanPromocionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proveedor_id'  => Proveedor::factory(),
            'nombre'        => 'Plan OPL '.fake()->monthName(),
            'vigente_desde' => today()->startOfMonth()->toDateString(),
            'vigente_hasta' => today()->endOfMonth()->toDateString(),
        ];
    }
}
