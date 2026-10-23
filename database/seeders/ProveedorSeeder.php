<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Proveedor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Proveedores desde database/seeders/data/proveedores.json: solo los que
 * tienen Excel de cálculo de precios (Unilever, MERHONSA, DCASA, Intercosmo,
 * Surtidora y DiLImport). Los demás se agregan desde el panel cuando
 * empiecen a manejarse.
 */
class ProveedorSeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<array{codigo: string, nombre: string, razon_social: string|null, direccion: string|null, rtn: string|null, telefono: string|null}> $proveedores */
        $proveedores = json_decode(File::get(database_path('seeders/data/proveedores.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($proveedores as $p) {
            Proveedor::query()->updateOrCreate(
                ['codigo' => $p['codigo']],
                [
                    'nombre'       => $p['nombre'],
                    'razon_social' => $p['razon_social'],
                    'direccion'    => $p['direccion'],
                    'rtn'          => $p['rtn'],
                    'telefono'     => $p['telefono'],
                    'activo'       => true,
                ],
            );
        }

        $this->command?->info('✓ Proveedores: '.count($proveedores));
    }
}
