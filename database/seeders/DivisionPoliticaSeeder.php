<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Departamento;
use App\Models\Municipio;
use App\Support\Texto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Los 18 departamentos y 298 municipios de Honduras:
 * database/seeders/data/honduras.json.
 *
 * Se guardan en MAYÚSCULAS. Se puede correr las veces que sea: no duplica
 * ni borra nada.
 */
class DivisionPoliticaSeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<array{codigo: string, nombre: string, municipios: list<string>}> $departamentos */
        $departamentos = json_decode(File::get(database_path('seeders/data/honduras.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($departamentos as $d) {
            $departamento = Departamento::query()->updateOrCreate(['codigo' => $d['codigo']], ['nombre' => $d['nombre']]);

            Municipio::query()->insertOrIgnore(array_map(
                fn (string $nombre): array => ['departamento_id' => $departamento->id, 'nombre' => Texto::mayusculas($nombre)],
                $d['municipios'],
            ));
        }

        $this->command?->info('✓ División política: '.Departamento::query()->count().' departamentos, '.Municipio::query()->count().' municipios.');
    }
}
