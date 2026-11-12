<?php

declare(strict_types=1);

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Precios\ActualizarPrecioProducto;
use App\Domain\Precios\CambiarMargenCategoria;
use App\Enums\MotivoCambioPrecio;
use App\Models\Bodega;
use App\Models\CambioPrecio;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\User;

// El factory deja cada producto con costo 50.00 por unidad, la UN a 59.5238
// (16%) y una CJ de 12 a 714.2856 (12 × la UN).

test('el costo nuevo recalcula cada presentación con el margen y deja historial de cada una', function (): void {
    $bodega = Bodega::factory()->create();
    $usuario = User::factory()->create(['bodega_id' => $bodega->id]);
    $producto = Producto::factory()->for(Categoria::factory()->state(['margen' => 0.16]))->create();

    $cambios = app(ActualizarPrecioProducto::class)->ejecutar($producto, 60.0, MotivoCambioPrecio::Compra, $usuario);

    expect($cambios)->toHaveCount(2)
        ->and(presentacion($producto)->precio)->toBe(71.4286)
        ->and(presentacion($producto, 12)->precio)->toBe(857.1429)
        ->and($producto->fresh()?->costo_unidad)->toBe(60.0)
        ->and($cambios[0]->presentacion_id)->toBe(presentacion($producto)->id)
        ->and($cambios[0]->precio_anterior)->toBe(59.5238)
        ->and($cambios[0]->costo_anterior)->toBe(50.0)
        ->and($cambios[1]->presentacion_id)->toBe(presentacion($producto, 12)->id)
        ->and($cambios[1]->costo_anterior)->toBe(600.0)
        ->and($cambios[1]->costo_nuevo)->toBe(720.0)
        ->and($cambios[1]->precio_nuevo)->toBe(857.1429)
        ->and($cambios[0]->user_id)->toBe($usuario->id)
        ->and($cambios[0]->bodega_id)->toBe($bodega->id)
        ->and($cambios[0]->motivo)->toBe(MotivoCambioPrecio::Compra);
});

test('si el costo y el precio no cambian (o solo por redondeo) no se escribe historial', function (): void {
    // La CJ12 está en 714.2856 (12 × la UN) y calculada da 714.2857: es solo redondeo.
    $producto = Producto::factory()->create();

    $cambios = app(ActualizarPrecioProducto::class)->ejecutar($producto, 50.0, MotivoCambioPrecio::Compra);

    expect($cambios)->toBe([])
        ->and(presentacion($producto, 12)->precio)->toBe(714.2856)
        ->and(CambioPrecio::query()->count())->toBe(0);
});

test('el precio puesto a mano en una presentación se respeta y guarda el margen real', function (): void {
    $producto = Producto::factory()->create();
    $caja = presentacion($producto, 12);

    $cambios = app(ActualizarPrecioProducto::class)->ejecutar($producto, 50.0, MotivoCambioPrecio::Manual, precios: [$caja->id => ['precio' => 700.0]]);

    // La CJ12 cuesta 600 y se vende a 700: deja 14.29 %. La UN sigue calculada (no cambia).
    expect($caja->fresh()?->precio)->toBe(700.0)
        ->and(presentacion($producto)->precio)->toBe(59.5238)
        ->and($cambios)->toHaveCount(1)
        ->and($cambios[0]->presentacion_id)->toBe($caja->id)
        ->and($cambios[0]->margen)->toBe(0.1429)
        ->and($cambios[0]->motivo)->toBe(MotivoCambioPrecio::Manual);
});

test('el margen propio del producto manda sobre el de la categoría', function (): void {
    $producto = Producto::factory()->create(['margen' => 0.20]);

    app(ActualizarPrecioProducto::class)->ejecutar($producto, 80.0, MotivoCambioPrecio::Compra);

    expect(presentacion($producto)->precio)->toBe(100.0)
        ->and(presentacion($producto, 12)->precio)->toBe(1200.0);
});

test('cambiar el margen de la categoría recalcula sus productos, menos los de margen propio', function (): void {
    $categoria = Categoria::factory()->create(['margen' => 0.16]);
    $normal = Producto::factory()->for($categoria)->create();
    $conMargenPropio = Producto::factory()->for($categoria)->precio(50.0, 55.5556)->create(['margen' => 0.10]);

    $cambiados = app(CambiarMargenCategoria::class)->ejecutar($categoria, 0.20);

    expect($cambiados)->toBe(1)
        ->and($categoria->fresh()?->margen)->toBe(0.2)
        ->and(presentacion($normal)->precio)->toBe(62.5)
        ->and(presentacion($normal, 12)->precio)->toBe(750.0)
        ->and(presentacion($conMargenPropio)->precio)->toBe(55.5556)
        ->and(CambioPrecio::query()->where('producto_id', $normal->id)->first()?->motivo)->toBe(MotivoCambioPrecio::Margen);
});

test('con precio fijo, el costo nuevo se guarda pero el precio se queda', function (): void {
    $producto = Producto::factory()->precioFijo()->create();

    $cambios = app(ActualizarPrecioProducto::class)->ejecutar($producto, 60.0, MotivoCambioPrecio::Compra, nota: 'COMPRA COM-000001');

    // Cuesta 60 y se sigue vendiendo a 59.5238: deja −0.8 %.
    expect($producto->fresh()?->costo_unidad)->toBe(60.0)
        ->and(presentacion($producto)->precio)->toBe(59.5238)
        ->and(presentacion($producto)->precio_fijo)->toBeTrue()
        ->and(presentacion($producto, 12)->precio)->toBe(714.2856)
        ->and($cambios[0]->precio_nuevo)->toBe(59.5238)
        ->and($cambios[0]->margen)->toBe(-0.008)
        ->and($cambios[0]->nota)->toBe('COMPRA COM-000001 · PRECIO FIJO');
});

test('cada presentación es fija o calculada por separado', function (): void {
    $producto = Producto::factory()->create();
    presentacion($producto, 12)->forceFill(['precio' => 700.0, 'precio_fijo' => true])->save();

    app(ActualizarPrecioProducto::class)->ejecutar($producto->fresh() ?? $producto, 60.0, MotivoCambioPrecio::Compra);

    expect(presentacion($producto)->precio)->toBe(71.4286)
        ->and(presentacion($producto, 12)->precio)->toBe(700.0)
        ->and(presentacion($producto, 12)->precio_fijo)->toBeTrue();
});

test('marcar el precio como fijo sin cambiar montos lo guarda sin historial', function (): void {
    $producto = Producto::factory()->conCaja(1)->create();
    $base = presentacion($producto);

    $cambios = app(ActualizarPrecioProducto::class)->ejecutar($producto, 50.0, MotivoCambioPrecio::Manual, precios: [$base->id => ['precio' => 59.5238, 'fijo' => true]]);

    expect($cambios)->toBe([])
        ->and($base->fresh()?->precio_fijo)->toBeTrue()
        ->and(CambioPrecio::query()->count())->toBe(0);
});

test('al quitar el precio fijo vuelve a salir del costo con el margen', function (): void {
    $producto = Producto::factory()->precio(50.0, 70.0)->precioFijo()->conCaja(1)->create();
    $base = presentacion($producto);

    app(ActualizarPrecioProducto::class)->ejecutar($producto, 50.0, MotivoCambioPrecio::Manual, precios: [$base->id => ['fijo' => false]]);

    expect($base->fresh()?->precio)->toBe(59.5238)
        ->and($base->fresh()?->precio_fijo)->toBeFalse();
});

test('cambiar el margen de la categoría no toca los de precio fijo', function (): void {
    $categoria = Categoria::factory()->create(['margen' => 0.16]);
    $normal = Producto::factory()->for($categoria)->create();
    $fijo = Producto::factory()->for($categoria)->precio(50.0, 57.0)->precioFijo()->create();

    $cambiados = app(CambiarMargenCategoria::class)->ejecutar($categoria, 0.20);

    expect($cambiados)->toBe(1)
        ->and(presentacion($normal)->precio)->toBe(62.5)
        ->and(presentacion($fijo)->precio)->toBe(57.0)
        ->and(CambioPrecio::query()->where('producto_id', $fijo->id)->exists())->toBeFalse();
});

test('un precio en cero o menos no se acepta', function (): void {
    $producto = Producto::factory()->create();

    expect(fn () => app(ActualizarPrecioProducto::class)->ejecutar($producto, 50.0, MotivoCambioPrecio::Manual, precios: [presentacion($producto)->id => ['precio' => 0.0]]))
        ->toThrow(ReglaNegocioException::class);
});

test('cada presentación puede tener su propio %: la caja deja menos y su precio sigue al costo en cada compra', function (): void {
    $producto = Producto::factory()->create();
    $caja = presentacion($producto, 12);

    app(ActualizarPrecioProducto::class)->ejecutar($producto, 50.0, MotivoCambioPrecio::Manual, precios: [$caja->id => ['margen' => 0.10]]);

    // CJ12: 600 ÷ 0.90 = 666.6667; la UN sigue con el 16 % de la categoría.
    expect($caja->fresh()?->precio)->toBe(666.6667)
        ->and($caja->fresh()?->margen)->toBe(0.1)
        ->and(presentacion($producto)->precio)->toBe(59.5238)
        ->and(presentacion($producto)->margen)->toBeNull();

    // Compran más barato: el costo baja a 45 y cada una baja con su %.
    app(ActualizarPrecioProducto::class)->ejecutar($producto->fresh() ?? $producto, 45.0, MotivoCambioPrecio::Compra);

    expect($caja->fresh()?->precio)->toBe(600.0)
        ->and(presentacion($producto)->precio)->toBe(53.5714)
        ->and(CambioPrecio::query()->where('presentacion_id', $caja->id)->latest('id')->first()?->margen)->toBe(0.1);

    // Sin % propio (null) vuelve al del producto: 540 ÷ 0.84.
    app(ActualizarPrecioProducto::class)->ejecutar($producto->fresh() ?? $producto, 45.0, MotivoCambioPrecio::Manual, precios: [$caja->id => ['margen' => null]]);

    expect($caja->fresh()?->margen)->toBeNull()
        ->and($caja->fresh()?->precio)->toBe(642.8571);
});

test('cambiar el margen de la categoría no toca el % propio de una presentación', function (): void {
    $categoria = Categoria::factory()->create(['margen' => 0.16]);
    $producto = Producto::factory()->for($categoria)->create();
    presentacion($producto, 12)->forceFill(['margen' => 0.10, 'precio' => 666.6667])->save();

    $cambiados = app(CambiarMargenCategoria::class)->ejecutar($categoria, 0.20);

    expect($cambiados)->toBe(1)
        ->and(presentacion($producto)->precio)->toBe(62.5)
        ->and(presentacion($producto, 12)->precio)->toBe(666.6667)
        ->and(presentacion($producto, 12)->margen)->toBe(0.1);
});

test('un % de utilidad fuera de 0 a 99.99 no se acepta', function (): void {
    $producto = Producto::factory()->create();

    expect(fn () => app(ActualizarPrecioProducto::class)->ejecutar($producto, 50.0, MotivoCambioPrecio::Manual, precios: [presentacion($producto, 12)->id => ['margen' => 1.0]]))
        ->toThrow(ReglaNegocioException::class, 'debe ser de 0 % a 99.99 %');
});
