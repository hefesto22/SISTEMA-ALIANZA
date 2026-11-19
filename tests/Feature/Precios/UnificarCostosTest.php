<?php

declare(strict_types=1);

use App\Domain\Inventario\AjustarExistencia;
use App\Domain\Precios\UnificarCostos;
use App\Enums\MotivoCambioPrecio;
use App\Models\Bodega;
use App\Models\CambioPrecio;
use App\Models\Producto;
use App\Models\User;

/**
 * UnificarCostos (migración 2026_11_20_100000): los productos que traían un
 * costo por presentación quedan con uno por unidad, el promedio de lo que
 * hay pesado por unidades, y sus precios "Con %" salen de ahí.
 */
test('el costo por unidad sale de lo que hay en todas las bodegas y cada presentación lo cobra con su %', function (): void {
    $admin = User::factory()->create();
    $cop = Bodega::factory()->create(['codigo' => 'COP']);
    $oco = Bodega::factory()->create(['codigo' => 'OCO']);

    // De antes: la UN a 9 y la CJ12 a 100 (8.33 cada una). Hay 10 UN en COP y 5 CJ12 en OCO.
    $viejo = Producto::factory()->create(['margen' => 0.20]);
    presentacion($viejo)->forceFill(['costo' => 9.0])->save();
    presentacion($viejo, 12)->forceFill(['costo' => 100.0])->save();
    app(AjustarExistencia::class)->ejecutar($cop->id, presentacion($viejo), 10, $admin);
    app(AjustarExistencia::class)->ejecutar($oco->id, presentacion($viejo, 12), 5, $admin);

    // Este ya cuadra: 50 la UN y 600 la CJ12.
    $nuevo = Producto::factory()->create();

    expect(app(UnificarCostos::class)->ejecutar())->toBe(1);

    // (10 × 9 + 5 × 100) ÷ (10 + 60) = 8.4286 la unidad; con 20 %. La UN da
    // 8.4286 ÷ 0.8 = 10.53575 justo en la mitad: el float de PHP lo deja en 10.5357.
    expect(presentacion($viejo)->costo)->toBe(8.4286)
        ->and(presentacion($viejo, 12)->costo)->toBe(101.1429)
        ->and(presentacion($viejo)->precio)->toBe(10.5357)
        ->and(presentacion($viejo, 12)->precio)->toBe(126.4286)
        ->and(presentacion($nuevo)->costo)->toBe(50.0)
        ->and(presentacion($nuevo, 12)->costo)->toBe(600.0)
        // En el historial, las dos del viejo como "Costo por unidad".
        ->and(CambioPrecio::query()->count())->toBe(2)
        ->and(CambioPrecio::query()->where('motivo', MotivoCambioPrecio::CostoUnico)->where('nota', UnificarCostos::NOTA)->count())->toBe(2);

    // Otra vez: ya está todo en un costo por unidad.
    expect(app(UnificarCostos::class)->ejecutar())->toBe(0);
});

test('sin existencia toma el de la base; si la base no tiene, el más alto por unidad de las otras', function (): void {
    $conBase = Producto::factory()->create(['margen' => 0.20]);
    presentacion($conBase, 12)->forceFill(['costo' => 660.0])->save();

    $sinBase = Producto::factory()->create(['margen' => 0.20]);
    presentacion($sinBase)->forceFill(['costo' => 0.0])->save();
    presentacion($sinBase, 12)->forceFill(['costo' => 720.0])->save();

    app(UnificarCostos::class)->ejecutar();

    expect(presentacion($conBase)->costo)->toBe(50.0)
        ->and(presentacion($conBase, 12)->costo)->toBe(600.0)
        ->and(presentacion($sinBase)->costo)->toBe(60.0)
        ->and(presentacion($sinBase, 12)->costo)->toBe(720.0);
});
