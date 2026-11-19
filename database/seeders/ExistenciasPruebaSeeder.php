<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Inventario\AjustarExistencia;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Presentacion;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Existencia de PRUEBA: cada presentación activa de los productos activos
 * queda con al menos 100 en cada bodega activa (100 UN, 100 CJ48...). Lo que
 * tenía menos sube a 100 con un ajuste en el kárdex (nota STOCK DE PRUEBA)
 * al costo de la presentación, a nombre del super admin; lo que ya tenía
 * 100 o más no se toca.
 *
 * Se puede correr otra vez: solo rellena lo que bajó de 100. No corre en
 * producción ni está en DatabaseSeeder.
 *
 *   php artisan db:seed --class=ExistenciasPruebaSeeder
 */
class ExistenciasPruebaSeeder extends Seeder
{
    public const CANTIDAD = 100;

    public const NOTA = 'STOCK DE PRUEBA';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('ExistenciasPruebaSeeder no se corre en producción.');

            return;
        }

        $usuario = User::query()
            ->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', Utils::getSuperAdminName()))
            ->orderBy('id')
            ->first() ?? User::query()->orderBy('id')->first();

        if ($usuario === null) {
            $this->command?->warn('No hay usuarios: corra AdminUserSeeder.');

            return;
        }

        $presentaciones = Presentacion::query()
            ->activas()
            ->whereHas('producto', fn (Builder $productos): Builder => $productos->where('activo', true))
            ->orderBy('producto_id')
            ->orderBy('id')
            ->get();

        if ($presentaciones->isEmpty()) {
            $this->command?->warn('No hay productos activos: corra CatalogoSeeder.');

            return;
        }

        $ajustar = app(AjustarExistencia::class);

        foreach (Bodega::query()->activas()->orderBy('id')->get() as $bodega) {
            $hay = Existencia::query()->where('bodega_id', $bodega->id)->pluck('cantidad', 'presentacion_id');
            $faltan = $presentaciones->filter(fn (Presentacion $p): bool => (int) $hay->get($p->id, 0) < self::CANTIDAD);

            DB::transaction(function () use ($faltan, $ajustar, $bodega, $usuario): void {
                foreach ($faltan as $presentacion) {
                    $ajustar->ejecutar($bodega->id, $presentacion, self::CANTIDAD, $usuario, self::NOTA);
                }
            });

            $this->command?->info(sprintf(
                '✓ %s: %d presentaciones subieron a %d%s',
                $bodega->nombre,
                $faltan->count(),
                self::CANTIDAD,
                $faltan->count() < $presentaciones->count() ? ' ('.($presentaciones->count() - $faltan->count()).' ya tenían '.self::CANTIDAD.' o más)' : '',
            ));
        }
    }
}
