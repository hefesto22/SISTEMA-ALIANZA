<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Rol;
use App\Models\Bodega;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Vendedores y facturación (encargados de bodega) de la lista de vendedores
 * del sistema actual: database/seeders/data/personal.json.
 *
 * Entran con su código (HO01…) y la contraseña de PERSONAL_PASSWORD
 * (en local, 12345678 si no está definida). En producción es obligatoria.
 * Los usuarios que ya existen no cambian de contraseña.
 */
class PersonalSeeder extends Seeder
{
    private const DEFAULT_PASSWORD = '12345678';

    public function run(): void
    {
        $password = (string) env('PERSONAL_PASSWORD', '');

        if ($password === '') {
            if (app()->environment('production')) {
                throw new RuntimeException('PERSONAL_PASSWORD no está definido. Defina la contraseña inicial del personal en .env antes de correr este seeder en producción.');
            }

            $password = self::DEFAULT_PASSWORD;
        }

        /** @var list<array{codigo: string, nombre: string, bodega: string, rol: string}> $personal */
        $personal = json_decode(File::get(database_path('seeders/data/personal.json')), true, flags: JSON_THROW_ON_ERROR);
        $bodegas = Bodega::query()->pluck('id', 'codigo');
        $hash = Hash::make($password);

        foreach ($personal as $p) {
            $usuario = User::query()->firstOrNew(['codigo' => $p['codigo']]);

            if (! $usuario->exists) {
                $usuario->forceFill([
                    'email'             => mb_strtolower($p['codigo']).'@alianzahozana.local',
                    'password'          => $hash,
                    'email_verified_at' => now(),
                ]);
            }

            $usuario->forceFill([
                'name'      => $p['nombre'],
                'bodega_id' => $bodegas->get($p['bodega']),
                'is_active' => true,
            ])->save();

            $usuario->syncRoles([Rol::from($p['rol'])->value]);
        }

        $this->command?->info('✓ Personal: '.count($personal).' usuarios (entran con su código, ej. HO01).');
    }
}
