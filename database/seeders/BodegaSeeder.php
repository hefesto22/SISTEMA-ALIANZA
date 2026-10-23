<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Bodega;
use Illuminate\Database\Seeder;

/**
 * Bodegas iniciales, sacadas de los prefijos de la lista de vendedores
 * (COP, OCO, SB) y del personal de Lucerna. Se editan desde el panel.
 */
class BodegaSeeder extends Seeder
{
    public function run(): void
    {
        $bodegas = [
            'COP' => 'Santa Rosa de Copán',
            'OCO' => 'Ocotepeque',
            'LUC' => 'Lucerna',
            'SB'  => 'Santa Bárbara',
        ];

        foreach ($bodegas as $codigo => $nombre) {
            Bodega::query()->firstOrCreate(['codigo' => $codigo], ['nombre' => $nombre, 'activa' => true]);
        }

        $this->command?->info('✓ Bodegas: '.implode(', ', array_keys($bodegas)));
    }
}
