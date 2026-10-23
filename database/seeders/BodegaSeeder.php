<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Bodega;
use App\Models\Municipio;
use App\Support\Texto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;

/**
 * Bodegas iniciales, sacadas de los prefijos de la lista de vendedores
 * (COP, OCO, SB) y del personal de Lucerna. Se editan desde el panel.
 *
 * Si la bodega todavía no tiene municipio se le pone el suyo (necesita
 * DivisionPoliticaSeeder antes). No cambia lo que ya se editó en el panel.
 */
class BodegaSeeder extends Seeder
{
    public function run(): void
    {
        // código => [nombre, código de departamento, municipio]
        $bodegas = [
            'COP' => ['Santa Rosa de Copán', '04', 'Santa Rosa de Copán'],
            'OCO' => ['Ocotepeque', '14', 'Ocotepeque'],
            'LUC' => ['Lucerna', '14', 'Lucerna'],
            'SB'  => ['Santa Bárbara', '16', 'Santa Bárbara'],
        ];

        foreach ($bodegas as $codigo => [$nombre, $departamento, $municipio]) {
            $bodega = Bodega::query()->firstOrCreate(['codigo' => $codigo], ['nombre' => $nombre, 'activa' => true]);

            if ($bodega->municipio_id !== null) {
                continue;
            }

            $municipioId = Municipio::query()
                ->where('nombre', Texto::mayusculas($municipio))
                ->whereHas('departamento', fn (Builder $query): Builder => $query->where('codigo', $departamento))
                ->value('id');

            if ($municipioId !== null) {
                $bodega->update(['municipio_id' => $municipioId]);
            }
        }

        $this->command?->info('✓ Bodegas: '.implode(', ', array_keys($bodegas)));
    }
}
