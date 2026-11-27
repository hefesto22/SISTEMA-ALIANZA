<?php

declare(strict_types=1);

use App\Enums\Rol;
use App\Models\PlanPromocion;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\Proveedor;
use App\Models\User;
use Database\Seeders\PlanesProveedorPruebaSeeder;

test('el plan de prueba de MERHONSA se arma como la hoja OPL: once líneas con sus regalos y topes, para todas las bodegas', function (): void {
    User::factory()->create()->assignRole(Rol::Administrador->value);
    $proveedor = Proveedor::factory()->create(['codigo' => PlanesProveedorPruebaSeeder::PROVEEDOR]);

    $codigos = collect(PlanesProveedorPruebaSeeder::LINEAS)->flatMap(fn (array $linea): array => [...$linea['productos'], ...($linea['regalo'] !== null ? [$linea['regalo']] : [])])->unique();

    foreach ($codigos as $codigo) {
        Producto::factory()->conCaja(12)->create(['codigo' => $codigo, 'proveedor_id' => $proveedor->id]);
    }

    // Correrlo otra vez (sin usarlo) lo vuelve a armar: no lo repite.
    $this->seed(PlanesProveedorPruebaSeeder::class);
    $this->seed(PlanesProveedorPruebaSeeder::class);

    $plan = PlanPromocion::query()->with(['promociones.productoBeneficio', 'bodegas'])->sole();
    $ideal = $plan->promociones->first(fn (Promocion $p): bool => $p->compra_unidades === 12 && $p->productoBeneficio->codigo === '40520');

    expect($plan->nombre)->toBe(PlanesProveedorPruebaSeeder::NOMBRE)
        ->and($plan->vigente_desde->toDateString())->toBe(today()->startOfMonth()->toDateString())
        ->and($plan->vigente_hasta->toDateString())->toBe(today()->addMonthNoOverflow()->endOfMonth()->toDateString())
        ->and($plan->bodegas)->toBeEmpty()
        ->and($plan->promociones)->toHaveCount(11)
        ->and($plan->promociones->sum('tope_unidades'))->toBe(1480)
        ->and($plan->promociones->where('regala_el_mismo', true))->toHaveCount(3)
        ->and($plan->promociones->where('se_mezclan', true))->toHaveCount(1)
        ->and($ideal)->not->toBeNull()
        ->and($ideal?->regala_el_mismo)->toBeFalse()
        ->and($plan->promociones->map(fn (Promocion $p): string => $p->compra_unidades.'+'.$p->cantidad_beneficio)->sort()->values()->all())
        ->toBe(['10+1', '12+1', '12+1', '12+1', '18+1', '20+1', '20+1', '20+1', '24+1', '48+2', '8+1']);
});
