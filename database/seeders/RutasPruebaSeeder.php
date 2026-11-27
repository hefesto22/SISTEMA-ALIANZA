<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Bodega;
use App\Models\Municipio;
use App\Models\Ruta;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;

/**
 * Rutas de PRUEBA de la bodega de Santa Rosa de Copán: dos rutas, con sus
 * municipios y sus vendedores (PersonalSeeder), para que en preventa cada
 * vendedor vea los clientes de prueba (ClientesPruebaSeeder) de su ruta.
 * Santa Rosa de Copán va en las dos. No corre en producción.
 *
 * Se puede correr las veces que sea: deja cada ruta con los municipios y
 * vendedores de aquí (no toca las demás rutas).
 */
class RutasPruebaSeeder extends Seeder
{
    /**
     * Ruta => municipios de Copán y códigos de los vendedores.
     *
     * @var array<string, array{municipios: list<string>, vendedores: list<string>}>
     */
    private const RUTAS = [
        'SANTA ROSA CENTRO' => [
            'municipios' => ['SANTA ROSA DE COPÁN', 'DOLORES', 'SAN JUAN DE OPOA', 'TRINIDAD DE COPÁN', 'SAN PEDRO', 'VERACRUZ'],
            'vendedores' => ['HO01', 'HO02', 'HO04', 'HO08'],
        ],
        'SANTA ROSA OCCIDENTE' => [
            'municipios' => ['SANTA ROSA DE COPÁN', 'CUCUYAGUA', 'CORQUÍN', 'NUEVA ARCADIA', 'COPÁN RUINAS'],
            'vendedores' => ['HO06', 'HO09', 'HO46'],
        ],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('RutasPruebaSeeder no se corre en producción.');

            return;
        }

        $bodega = Bodega::query()->where('codigo', 'COP')->first();

        if ($bodega === null) {
            $this->command?->warn('No existe la bodega COP: corra BodegaSeeder.');

            return;
        }

        foreach (self::RUTAS as $nombre => $datos) {
            $ruta = Ruta::query()->firstOrCreate(['bodega_id' => $bodega->id, 'nombre' => $nombre], ['activa' => true]);
            $ruta->update(['activa' => true]);

            $municipios = Municipio::query()
                ->whereIn('nombre', $datos['municipios'])
                ->whereHas('departamento', fn (Builder $query): Builder => $query->where('codigo', '04'))
                ->pluck('id');
            $vendedores = User::query()->whereIn('codigo', $datos['vendedores'])->pluck('id');

            $ruta->municipios()->sync($municipios);
            $ruta->vendedores()->sync($vendedores);

            $faltan = count($datos['vendedores']) - $vendedores->count();

            $this->command?->info("✓ {$ruta->codigo()} {$nombre}: {$municipios->count()} municipios, {$vendedores->count()} vendedores"
                .($faltan > 0 ? " (faltan {$faltan}: corra PersonalSeeder)" : '').'.');
        }
    }
}
