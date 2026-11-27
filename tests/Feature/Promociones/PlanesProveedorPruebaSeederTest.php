<?php

declare(strict_types=1);

use App\Enums\Rol;
use App\Models\PlanPromocion;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\Proveedor;
use App\Models\User;
use Database\Seeders\PlanesProveedorPruebaSeeder;

test('el plan de prueba de MERHONSA se arma una sola vez, con sus cinco líneas y sus topes, para todas las bodegas', function (): void {
    User::factory()->create()->assignRole(Rol::Administrador->value);
    $proveedor = Proveedor::factory()->create(['codigo' => PlanesProveedorPruebaSeeder::PROVEEDOR]);

    $codigos = collect(PlanesProveedorPruebaSeeder::LINEAS)->flatMap(fn (array $linea): array => [...$linea['productos'], $linea['regalo']])->unique();

    foreach ($codigos as $codigo) {
        Producto::factory()->conCaja(12)->create(['codigo' => $codigo, 'proveedor_id' => $proveedor->id]);
    }

    // Correrlo otra vez no lo repite.
    $this->seed(PlanesProveedorPruebaSeeder::class);
    $this->seed(PlanesProveedorPruebaSeeder::class);

    $plan = PlanPromocion::query()->with(['promociones', 'bodegas'])->sole();

    expect($plan->nombre)->toBe(PlanesProveedorPruebaSeeder::NOMBRE)
        ->and($plan->vigente_desde->toDateString())->toBe(today()->startOfMonth()->toDateString())
        ->and($plan->vigente_hasta->toDateString())->toBe(today()->addMonthNoOverflow()->endOfMonth()->toDateString())
        ->and($plan->bodegas)->toBeEmpty()
        ->and($plan->promociones)->toHaveCount(5)
        ->and($plan->promociones->sum('tope_unidades'))->toBe(960)
        ->and($plan->promociones->map(fn (Promocion $p): string => $p->compra_unidades.'+'.$p->cantidad_beneficio)->sort()->values()->all())
        ->toBe(['10+1', '12+1', '20+1', '48+2', '8+1']);
});
