<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bodega;
use App\Models\Cai;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * CAI de prueba: global (sin bodegas), del 1 al 5000, vence en un año.
 *
 * @extends Factory<Cai>
 */
class CaiFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $bloque = fn (int $largo): string => strtoupper(fake()->regexify('[0-9A-F]{'.$largo.'}'));

        return [
            'cai'             => implode('-', [$bloque(6), $bloque(6), $bloque(6), $bloque(6), $bloque(6), $bloque(2)]),
            'establecimiento' => '000',
            'punto_emision'   => str_pad((string) fake()->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'tipo_documento'  => Cai::FACTURA,
            'rango_desde'     => 1,
            'rango_hasta'     => 5000,
            'fecha_limite'    => today()->addYear(),
            'activo'          => true,
        ];
    }

    /**
     * Solo para estas bodegas.
     */
    public function para(Bodega ...$bodegas): static
    {
        return $this->afterCreating(function (Cai $cai) use ($bodegas): void {
            $cai->bodegas()->attach(array_map(fn (Bodega $bodega): int => $bodega->id, $bodegas));
        });
    }

    public function vencido(): static
    {
        return $this->state(['fecha_limite' => today()->subDay()]);
    }

    /**
     * Rango corto (para probar cuando se acaba).
     */
    public function rango(int $desde, int $hasta): static
    {
        return $this->state(['rango_desde' => $desde, 'rango_hasta' => $hasta]);
    }
}
