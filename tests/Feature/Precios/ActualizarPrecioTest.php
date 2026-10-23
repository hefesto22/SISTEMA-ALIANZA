<?php

declare(strict_types=1);

use App\Domain\Precios\ActualizarPrecioProducto;
use App\Domain\Precios\CambiarMargenCategoria;
use App\Enums\MotivoCambioPrecio;
use App\Models\Bodega;
use App\Models\CambioPrecio;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\User;

// El factory deja cada producto con costo 50.00 y precio 59.5238 (16%).

test('el costo nuevo recalcula el precio con el margen de la categoría y deja historial', function (): void {
    $bodega = Bodega::factory()->create();
    $usuario = User::factory()->create(['bodega_id' => $bodega->id]);
    $producto = Producto::factory()->for(Categoria::factory()->state(['margen' => 0.16]))->create();

    $cambio = app(ActualizarPrecioProducto::class)->ejecutar($producto, 60.0, MotivoCambioPrecio::Compra, $usuario);

    expect($cambio)->toBeInstanceOf(CambioPrecio::class)
        ->and($producto->fresh()?->precio_unidad)->toBe(71.4286)
        ->and($producto->fresh()?->costo_unidad)->toBe(60.0)
        ->and($cambio?->precio_anterior)->toBe(59.5238)
        ->and($cambio?->costo_anterior)->toBe(50.0)
        ->and($cambio?->user_id)->toBe($usuario->id)
        ->and($cambio?->bodega_id)->toBe($bodega->id)
        ->and($cambio?->motivo)->toBe(MotivoCambioPrecio::Compra);
});

test('si el costo y el precio no cambian no se escribe historial', function (): void {
    $producto = Producto::factory()->create();

    $cambio = app(ActualizarPrecioProducto::class)->ejecutar($producto, 50.0, MotivoCambioPrecio::Compra);

    expect($cambio)->toBeNull()
        ->and(CambioPrecio::query()->count())->toBe(0);
});

test('el precio puesto a mano se respeta y guarda el margen real', function (): void {
    $producto = Producto::factory()->create();

    $cambio = app(ActualizarPrecioProducto::class)->ejecutar($producto, 50.0, MotivoCambioPrecio::Manual, precioUnidad: 60.0);

    expect($producto->fresh()?->precio_unidad)->toBe(60.0)
        ->and($cambio?->margen)->toBe(0.1667)
        ->and($cambio?->motivo)->toBe(MotivoCambioPrecio::Manual);
});

test('el margen propio del producto manda sobre el de la categoría', function (): void {
    $producto = Producto::factory()->create(['margen' => 0.20]);

    app(ActualizarPrecioProducto::class)->ejecutar($producto, 80.0, MotivoCambioPrecio::Compra);

    expect($producto->fresh()?->precio_unidad)->toBe(100.0);
});

test('cambiar el margen de la categoría recalcula sus productos, menos los de margen propio', function (): void {
    $categoria = Categoria::factory()->create(['margen' => 0.16]);
    $normal = Producto::factory()->for($categoria)->create();
    $conMargenPropio = Producto::factory()->for($categoria)->precio(50.0, 55.5556)->create(['margen' => 0.10]);

    $cambiados = app(CambiarMargenCategoria::class)->ejecutar($categoria, 0.20);

    expect($cambiados)->toBe(1)
        ->and($categoria->fresh()?->margen)->toBe(0.2)
        ->and($normal->fresh()?->precio_unidad)->toBe(62.5)
        ->and($conMargenPropio->fresh()?->precio_unidad)->toBe(55.5556)
        ->and(CambioPrecio::query()->where('producto_id', $normal->id)->first()?->motivo)->toBe(MotivoCambioPrecio::Margen);
});
