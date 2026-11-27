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
 * Un plan de PRUEBA de MERHONSA (Mercantil de Honduras) armado como la hoja
 * OPL que manda el proveedor cada mes, con productos que ya están en el
 * catálogo: por línea, los productos que vende el cliente (cada uno cuenta
 * aparte, no se mezclan), la mecánica, el producto a bonificar y las
 * unidades autorizadas. Hay de todo lo que trae la hoja:
 *  - regala del mismo producto (Ultraklin 400 g, cada fuerza la suya; Unox);
 *  - regala otra presentación (Ultraklin 1.1 kg → Ultraklin 400 g; Terso
 *    doypack 2 L → Terso doypack 400 ml; Aceite Ideal 1.5 L → 445 ml);
 *  - varios aromas que regalan uno fijo (Surf, Xtra, Zagaz);
 *  - una que deja mezclar (Terso 850 ml: 6 de un aroma + 6 del otro = 12);
 *  - una línea con 0 autorizadas (no da regalo).
 * Vale del primer día de este mes al último del siguiente, en todas las
 * bodegas. Necesita CatalogoSeeder y un usuario. No corre en producción ni
 * está en DatabaseSeeder.
 *
 * Si el plan ya existe y no se ha usado, lo vuelve a armar con estas
 * líneas; si ya se dio algún regalo, no lo toca.
 *
 *   php artisan db:seed --class=PlanesProveedorPruebaSeeder
 */
class PlanesProveedorPruebaSeeder extends Seeder
{
    public const NOMBRE = 'PLAN DE PRUEBA MERHONSA';

    public const PROVEEDOR = '500008';

    /**
     * Las líneas de la hoja con los códigos del catálogo de MERHONSA
     * ("regalo" null = regala del mismo producto que compra).
     *
     * @var list<array{productos: list<string>, compra: int, regala: int, mezclar: bool, regalo: string|null, tope: int, porcentaje: float}>
     */
    public const LINEAS = [
        // Ultraklin 400 g, las cuatro fuerzas: cada una regala de sí misma.
        ['productos' => ['212040OH1', '2120497B1', '212040ML1', '2120498C1'], 'compra' => 20, 'regala' => 1, 'mezclar' => false, 'regalo' => null, 'tope' => 300, 'porcentaje' => 5.0],
        // Ultraklin 1.1 kg → 1 Ultraklin Fuerza Extrema 400 g.
        ['productos' => ['223099', '223095', '223096', '212040M61'], 'compra' => 10, 'regala' => 1, 'mezclar' => false, 'regalo' => '212040OH1', 'tope' => 200, 'porcentaje' => 5.46],
        // Terso 850 ml: los dos aromas se mezclan (6 + 6 = 12) → Terso Delicada Ternura 850 ml.
        ['productos' => ['7401001661253', '7401001685815'], 'compra' => 12, 'regala' => 1, 'mezclar' => true, 'regalo' => '7401001661253', 'tope' => 120, 'porcentaje' => 8.33],
        // Terso doypack 2 L → Terso Delicada Ternura doypack 400 ml.
        ['productos' => ['7401001679036', '7401001685167'], 'compra' => 8, 'regala' => 1, 'mezclar' => false, 'regalo' => '7401001687154', 'tope' => 80, 'porcentaje' => 3.32],
        // Unox limón 90 g: 48+2 del mismo.
        ['productos' => ['2150K9N11'], 'compra' => 48, 'regala' => 2, 'mezclar' => false, 'regalo' => null, 'tope' => 400, 'porcentaje' => 4.17],
        // Unox multiusos 340 g → 1 Unox limón 90 g.
        ['productos' => ['2150K9L01'], 'compra' => 18, 'regala' => 1, 'mezclar' => false, 'regalo' => '2150K9N11', 'tope' => 100, 'porcentaje' => 1.59],
        // Surf 1200 g (cuatro aromas) → Surf Rosas y Lilas.
        ['productos' => ['260I9G41', '7401001686096', '17401001680442', '260I9F31'], 'compra' => 24, 'regala' => 1, 'mezclar' => false, 'regalo' => '260I9G41', 'tope' => 80, 'porcentaje' => 4.17],
        // Xtra 1600 g (cinco) → Xtra Todo Tipo de Ropa.
        ['productos' => ['2260H8SB1', '2260H8Z81', '2260H9991', '2260H8UB1', '2260H9BB1'], 'compra' => 20, 'regala' => 1, 'mezclar' => false, 'regalo' => '2260H8SB1', 'tope' => 60, 'porcentaje' => 5.0],
        // Lavaplatos Zagaz 800 g (tres) → Zagaz Limoncello 400 g.
        ['productos' => ['21334HL11', '213343991', '21334HP11'], 'compra' => 12, 'regala' => 1, 'mezclar' => false, 'regalo' => '21334HP01', 'tope' => 90, 'porcentaje' => 4.23],
        // Aceite Ideal 1.5 L → Aceite Ideal 445 ml.
        ['productos' => ['40034'], 'compra' => 12, 'regala' => 1, 'mezclar' => false, 'regalo' => '40520', 'tope' => 50, 'porcentaje' => 2.4],
        // Aceite El Dorado 750 ml: este mes sin unidades autorizadas.
        ['productos' => ['40494'], 'compra' => 20, 'regala' => 1, 'mezclar' => false, 'regalo' => null, 'tope' => 0, 'porcentaje' => 5.0],
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

        $plan = PlanPromocion::query()->where('proveedor_id', $proveedor->id)->where('nombre', self::NOMBRE)->first();

        if ($plan !== null && $plan->seUso()) {
            $this->command?->info('El plan de prueba de MERHONSA ya se usó en pedidos: no se toca.');

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
            $regalo = $linea['regalo'] !== null ? ($productos[$linea['regalo']] ?? null) : null;

            if ($compra === [] || ($linea['regalo'] !== null && $regalo === null)) {
                $this->command?->warn("No están los productos de la línea {$linea['compra']}+{$linea['regala']}: corra CatalogoSeeder.");

                continue;
            }

            $lineas[] = [
                'productos'  => $compra,
                'compra'     => $linea['compra'],
                'regala'     => $linea['regala'],
                'mezclar'    => $linea['mezclar'],
                'regalo_id'  => $regalo,
                'tope'       => $linea['tope'],
                'porcentaje' => $linea['porcentaje'],
            ];
        }

        if ($lineas === []) {
            return;
        }

        $plan = app(GuardarPlanPromocion::class)->ejecutar($plan, [
            'proveedor_id'  => $proveedor->id,
            'nombre'        => self::NOMBRE,
            'vigente_desde' => Carbon::today()->startOfMonth()->toDateString(),
            'vigente_hasta' => Carbon::today()->addMonthNoOverflow()->endOfMonth()->toDateString(),
            'notas'         => 'Plan ficticio, armado como la hoja OPL del proveedor, para probar la preventa con regalos y el tope autorizado.',
            'bodegas'       => [],
            'lineas'        => $lineas,
        ], $usuario);

        $this->command?->info("✓ {$plan->nombre}: ".count($lineas).' líneas, del '.$plan->vigente_desde->format('d/m/Y').' al '.$plan->vigente_hasta->format('d/m/Y').'.');
    }
}
