<?php

declare(strict_types=1);

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Inventario\AbrirCaja;
use App\Domain\Inventario\Disponible;
use App\Domain\Inventario\RegistrarCompra;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarDespacho;
use App\Domain\Precios\CalculadoraPrecio;
use App\Enums\MetodoCosto;
use App\Enums\MotivoCambioPrecio;
use App\Enums\Rol;
use App\Enums\TipoMovimiento;
use App\Models\Bodega;
use App\Models\CambioPrecio;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Existencia;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Database\Seeders\RolesSistemaSeeder;

/**
 * Cada presentación con su existencia y su costo: la CJ48 no se mezcla con
 * la CJ32 ni con las unidades sueltas. Al abrir una caja sus unidades pasan
 * a las sueltas con el costo de esa caja y se promedian ahí.
 */
beforeEach(function (): void {
    $this->seed(RolesSistemaSeeder::class);

    $this->bodega = Bodega::factory()->create(['codigo' => 'COP']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Rol::Administrador->value);
    $this->encargado = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->encargado->assignRole(Rol::Bodega->value);
    $this->vendedor = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->vendedor->assignRole(Rol::Vendedor->value);

    // Exento (lo de la factura es el costo) y 20 % de utilidad: UN, CJ48 y CJ32.
    $this->jabon = Producto::factory()->exento()->precio(6.0, 7.5)->conCaja(48)->conPresentacion('CJ', 32)->create(['margen' => 0.20]);
    $this->un = presentacion($this->jabon);
    $this->cj48 = presentacion($this->jabon, 48);
    $this->cj32 = presentacion($this->jabon, 32);
});

test('10 CJ48 a 312, 10 CJ32 a 315 y 5 UN a 9 en la misma compra: cada una queda con su costo y su precio', function (): void {
    $compra = app(RegistrarCompra::class)->ejecutar($this->bodega, $this->jabon->proveedor, [
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->cj48->id, 'cantidad' => 10, 'costo' => 312.0],
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->cj32->id, 'cantidad' => 10, 'costo' => 315.0],
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->un->id, 'cantidad' => 5, 'costo' => 9.0],
    ], $this->admin);

    // Sin existencia antes, cada una toma el costo de su compra y su precio sale de él con el 20 %.
    expect($this->cj48->fresh()?->costo)->toBe(312.0)
        ->and($this->cj48->fresh()?->precio)->toBe(390.0)
        ->and($this->cj32->fresh()?->costo)->toBe(315.0)
        ->and($this->cj32->fresh()?->precio)->toBe(393.75)
        ->and($this->un->fresh()?->costo)->toBe(9.0)
        ->and($this->un->fresh()?->precio)->toBe(11.25)
        ->and(Existencia::de($this->bodega->id, $this->cj48->id))->toBe(10)
        ->and(Existencia::de($this->bodega->id, $this->cj32->id))->toBe(10)
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe(5)
        ->and(Existencia::en($this->bodega->id, $this->jabon->id))->toBe(805)
        ->and($compra->total)->toBe(6315.0)
        ->and($this->jabon->fresh()?->existenciaPorPresentacion(Existencia::porPresentacion($this->bodega->id, $this->jabon->id)))->toBe('10 CJ48 · 10 CJ32 · 5 UN');
});

test('al despachar sueltas que no alcanzan se abre la caja que dice bodega: sus unidades pasan a las sueltas con el costo de esa caja', function (): void {
    app(RegistrarCompra::class)->ejecutar($this->bodega, $this->jabon->proveedor, [
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->cj48->id, 'cantidad' => 10, 'costo' => 312.0],
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->cj32->id, 'cantidad' => 10, 'costo' => 315.0],
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->un->id, 'cantidad' => 5, 'costo' => 9.0],
    ], $this->admin);

    $pedido = Pedido::query()->create([
        'bodega_id'   => $this->bodega->id,
        'cliente_id'  => Cliente::factory()->create(['bodega_id' => $this->bodega->id])->id,
        'vendedor_id' => $this->vendedor->id,
        'fecha'       => now(),
    ]);
    $pedido->items()->create(['producto_id' => $this->jabon->id, 'presentacion_id' => $this->un->id, 'cantidad' => 8]);
    app(RecalcularPedido::class)->ejecutar($pedido);

    // Hay 5 sueltas y salen 8: bodega abre 1 CJ48 (48 a 6.50 cada una).
    app(ValidarDespacho::class)->ejecutar($pedido, [], $this->encargado, abrir: [$this->cj48->id => 1]);

    // Sueltas: (5 × 9 + 48 × 6.50) ÷ 53 = 6.7358, sin importar de qué caja salió cada una.
    $costo = 6.7358;
    $kardex = MovimientoInventario::query()->where('pedido_id', $pedido->id)->orderBy('id')->get();

    expect(Existencia::de($this->bodega->id, $this->cj48->id))->toBe(9)
        ->and(Existencia::de($this->bodega->id, $this->cj32->id))->toBe(10)
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe(45)
        ->and($this->un->fresh()?->costo)->toBe($costo)
        ->and($this->un->fresh()?->precio)->toBe(CalculadoraPrecio::precioVenta($costo, 0.20))
        ->and($this->cj48->fresh()?->costo)->toBe(312.0)
        ->and($this->cj32->fresh()?->costo)->toBe(315.0)
        // Al cliente se le cobra el precio que tenía la UN al validar.
        ->and($pedido->items()->sole()->precio)->toBe(11.25)
        ->and($kardex->map(fn (MovimientoInventario $m): array => [$m->tipo, $m->presentacion_id, $m->cantidad, $m->costo_unidad])->all())->toBe([
            [TipoMovimiento::Apertura, $this->cj48->id, -1, 312.0],
            [TipoMovimiento::Apertura, $this->un->id, 48, 6.5],
            [TipoMovimiento::Despacho, $this->un->id, -8, $costo],
        ])
        ->and(CambioPrecio::query()->where('motivo', MotivoCambioPrecio::CajaAbierta)->sole()->presentacion_id)->toBe($this->un->id);
});

test('abrir una caja con "último costo": las sueltas quedan con el costo por unidad de esa caja', function (): void {
    Configuracion::actual()->update(['metodo_costo' => MetodoCosto::Ultimo]);
    app(RegistrarCompra::class)->ejecutar($this->bodega, $this->jabon->proveedor, [
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->cj32->id, 'cantidad' => 2, 'costo' => 320.0],
    ], $this->admin);

    app(AbrirCaja::class)->ejecutar($this->bodega->id, $this->cj32, 1, $this->encargado);

    expect($this->un->fresh()?->costo)->toBe(10.0)
        ->and(Existencia::de($this->bodega->id, $this->cj32->id))->toBe(1)
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe(32);
});

test('no se abre la base ni cajas que no hay (controlando la existencia)', function (): void {
    expect(fn () => app(AbrirCaja::class)->ejecutar($this->bodega->id, $this->un, 1, $this->encargado))
        ->toThrow(ReglaNegocioException::class, 'no es una caja');

    Configuracion::actual()->update(['controlar_existencias' => true]);

    expect(fn () => app(AbrirCaja::class)->ejecutar($this->bodega->id, $this->cj48, 1, $this->encargado))
        ->toThrow(ReglaNegocioException::class, 'No alcanza');
});

test('lo que falta de lo que se pide: cada caja de sus cajas; las sueltas, de las sueltas y de las cajas que sobran', function (): void {
    $contenido = [$this->un->id => 1, $this->cj32->id => 32, $this->cj48->id => 48];
    $hay = [$this->un->id => 5, $this->cj32->id => 0, $this->cj48->id => 1];

    // 1 CJ32 que no hay; 1 CJ48 sí.
    expect(Disponible::faltantes([$this->cj32->id => 1], $hay, $contenido, $this->un->id))->toBe([$this->cj32->id => 1])
        ->and(Disponible::faltantes([$this->cj48->id => 1], $hay, $contenido, $this->un->id))->toBe([])
        // 20 sueltas: 5 sueltas + abrir la CJ48 que hay.
        ->and(Disponible::faltantes([$this->un->id => 20], $hay, $contenido, $this->un->id))->toBe([])
        // La CJ48 sale entera: ya no se puede abrir; faltan 15 sueltas.
        ->and(Disponible::faltantes([$this->cj48->id => 1, $this->un->id => 20], $hay, $contenido, $this->un->id))->toBe([$this->un->id => 15]);
});
