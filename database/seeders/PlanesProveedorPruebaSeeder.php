<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Promociones\GuardarPlanPromocion;
use App\Models\PlanPromocion;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Un plan de PRUEBA de MERHONSA (Mercantil de Honduras), como los que manda
 * el proveedor cada mes: cinco líneas con su mecánica (20+1, 12+1, 48+2,
 * 8+1, 10+1) y las unidades autorizadas. Cada producto cuenta aparte y se
 * regala del mismo. Vale del primer día de este mes al último del
 * siguiente, en todas las bodegas, para probar la preventa con regalos, el
 * tope y el avance del plan.
 *
 * Necesita CatalogoSeeder y un usuario. Si el plan ya existe no hace nada
 * (para empezarlo de cero, bórrelo desde Planes de proveedores). No corre en
 * producción ni está en DatabaseSeeder.
 *
 *   php artisan db:seed --class=PlanesProveedorPruebaSeeder
 */
class PlanesProveedorPruebaSeeder extends Seeder
{
    public const NOMBRE = 'PLAN DE PRUEBA MERHONSA';

    public const PROVEEDOR = '500008';

    /**
     * Las líneas de la hoja, con los códigos del catálogo de MERHONSA.
     *
     * @var list<array{productos: list<string>, compra: int, regala: int, tope: int}>
     */
    public const LINEAS = [
        // Detergente Ultraklin 400 g (cada fuerza aparte): 20+1.
        ['productos' => ['212040OH1', '2120497B1', '212040ML1', '2120498C1'], 'compra' => 20, 'regala' => 1, 'tope' => 300],
        // Suavizante Terso 850 ml: 12+1.
        ['productos' => ['7401001661253', '7401001685815'], 'compra' => 12, 'regala' => 1, 'tope' => 120],
        // Barra Unox limón 90 g: 48+2.
        ['productos' => ['2150K9N11'], 'compra' => 48, 'regala' => 2, 'tope' => 400],
        // Jabón Surf 1200 g: 8+1.
        ['productos' => ['260I9G41', '7401001686096', '17401001680442'], 'compra' => 8, 'regala' => 1, 'tope' => 80],
        // Jabón Xtra 1600 g: 10+1.
        ['productos' => ['2260H8SB1', '2260H8Z81', '2260H9991', '2260H8UB1', '2260H9BB1'], 'compra' => 10, 'regala' => 1, 'tope' => 60],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('PlanesProveedorPruebaSeeder no se corre en producción.');

            return;
        }

        $usuario = User::query()
            ->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', Utils::getSuperAdminName()))
            ->orderBy('id')
            ->first() ?? User::query()->orderBy('id')->first();
        $proveedor = Proveedor::query()->where('codigo', self::PROVEEDOR)->first();

        if ($usuario === null || $proveedor === null) {
            $this->command?->warn('Falta el usuario o el proveedor MERHONSA: corra AdminUserSeeder y CatalogoSeeder.');

            return;
        }

        if (PlanPromocion::query()->where('proveedor_id', $proveedor->id)->where('nombre', self::NOMBRE)->exists()) {
            $this->command?->info('El plan de prueba de MERHONSA ya existe: no se toca.');

            return;
        }

        /** @var array<string, int> $productos */
        $productos = Producto::query()
            ->where('proveedor_id', $proveedor->id)
            ->pluck('id', 'codigo')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $lineas = [];

        foreach (self::LINEAS as $linea) {
            $compra = array_values(array_filter(array_map(fn (string $codigo): ?int => $productos[$codigo] ?? null, $linea['productos'])));

            if ($compra === []) {
                $this->command?->warn("No están los productos de la línea {$linea['compra']}+{$linea['regala']}: corra CatalogoSeeder.");

                continue;
            }

            $lineas[] = [
                'productos' => $compra,
                'compra'    => $linea['compra'],
                'regala'    => $linea['regala'],
                'tope'      => $linea['tope'],
            ];
        }

        if ($lineas === []) {
            return;
        }

        $plan = app(GuardarPlanPromocion::class)->ejecutar(null, [
            'proveedor_id'  => $proveedor->id,
            'nombre'        => self::NOMBRE,
            'vigente_desde' => Carbon::today()->startOfMonth()->toDateString(),
            'vigente_hasta' => Carbon::today()->addMonthNoOverflow()->endOfMonth()->toDateString(),
            'notas'         => 'Plan ficticio para probar la preventa con regalos y el tope autorizado.',
            'bodegas'       => [],
            'lineas'        => $lineas,
        ], $usuario);

        $this->command?->info("✓ {$plan->nombre}: ".count($lineas).' líneas, del '.$plan->vigente_desde->format('d/m/Y').' al '.$plan->vigente_hasta->format('d/m/Y').'.');
    }
}
