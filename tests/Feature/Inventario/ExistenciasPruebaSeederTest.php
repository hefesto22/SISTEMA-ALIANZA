<?php

declare(strict_types=1);

use App\Domain\Inventario\AjustarExistencia;
use App\Enums\TipoMovimiento;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\MovimientoInventario;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\User;
use Database\Seeders\ExistenciasPruebaSeeder;

/**
 * ExistenciasPruebaSeeder: cada presentación activa queda con al menos 100
 * en cada bodega activa, con su ajuste en el kárdex; lo que ya tenía 100 o
 * más, lo inactivo y las bodegas apagadas no se tocan.
 */
test('sube a 100 cada presentación en cada bodega y deja lo que ya tenía más', function (): void {
    $admin = User::factory()->create();
    $cop = Bodega::factory()->create(['codigo' => 'COP']);
    $oco = Bodega::factory()->create(['codigo' => 'OCO']);
    $apagada = Bodega::factory()->create(['codigo' => 'LUC', 'activa' => false]);

    $jabon = Producto::factory()->conCaja(48)->create();
    $un = presentacion($jabon);
    $cj48 = presentacion($jabon, 48);
    $viejo = presentacion(Producto::factory()->create(['activo' => false]));

    // En COP ya había 150 UN y 30 CJ48.
    app(AjustarExistencia::class)->ejecutar($cop->id, $un, 150, $admin);
    app(AjustarExistencia::class)->ejecutar($cop->id, $cj48, 30, $admin);

    $this->seed(ExistenciasPruebaSeeder::class);

    $existencia = fn (Bodega $bodega, Presentacion $p): int => Existencia::de($bodega->id, $p->id);

    expect([$existencia($cop, $un), $existencia($cop, $cj48), $existencia($oco, $un), $existencia($oco, $cj48)])->toBe([150, 100, 100, 100])
        ->and([$existencia($apagada, $un), $existencia($cop, $viejo)])->toBe([0, 0]);

    $ajustes = MovimientoInventario::query()->where('nota', ExistenciasPruebaSeeder::NOTA)->orderBy('id')->get();

    expect($ajustes->map(fn (MovimientoInventario $m): array => [$m->bodega_id, $m->presentacion_id, $m->tipo, $m->cantidad, $m->existencia])->all())->toBe([
        [$cop->id, $cj48->id, TipoMovimiento::Ajuste, 70, 100],
        [$oco->id, $un->id, TipoMovimiento::Ajuste, 100, 100],
        [$oco->id, $cj48->id, TipoMovimiento::Ajuste, 100, 100],
    ]);

    // Otra vez: ya está todo en 100, no agrega nada.
    $this->seed(ExistenciasPruebaSeeder::class);

    expect(MovimientoInventario::query()->where('nota', ExistenciasPruebaSeeder::NOTA)->count())->toBe(3);
});
