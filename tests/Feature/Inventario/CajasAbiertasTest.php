<?php

declare(strict_types=1);

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Inventario\AbrirCaja;
use App\Domain\Inventario\Disponible;
use App\Domain\Inventario\RegistrarCompra;
use App\Domain\Pedidos\CorregirPedido;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarDespacho;
use App\Domain\Precios\CalculadoraPrecio;
use App\Enums\MetodoCosto;
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
use App\Models\Promocion;
use App\Models\User;

/**
 * Cada presentación con su existencia: la CJ48 no se mezcla con la CJ32 ni
 * con las unidades sueltas, y al abrir una caja sus unidades pasan a las
 * sueltas. El costo es uno por unidad: cada presentación cuesta ese × lo
 * que trae, así que abrir una caja no cambia costos ni precios.
 */
beforeEach(function (): void {
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

test('10 CJ48 a 312, 10 CJ32 a 315 y 5 UN a 9 en la misma compra: un costo por unidad para las tres y cada una con su precio', function (): void {
    $compra = app(RegistrarCompra::class)->ejecutar($this->bodega, $this->jabon->proveedor, [
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->cj48->id, 'cantidad' => 10, 'costo' => 312.0],
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->cj32->id, 'cantidad' => 10, 'costo' => 315.0],
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->un->id, 'cantidad' => 5, 'costo' => 9.0],
    ], $this->admin);

    // Las 805 unidades costaron 6,315: 7.8447 cada una, salgan de la caja que salgan.
    // Cada presentación cuesta eso × lo que trae y su precio sale de ahí con el 20 %.
    expect($this->un->fresh()?->costo)->toBe(7.8447)
        ->and($this->un->fresh()?->precio)->toBe(9.8059)
        ->and($this->cj48->fresh()?->costo)->toBe(376.5465)
        ->and($this->cj48->fresh()?->precio)->toBe(470.6831)
        ->and($this->cj32->fresh()?->costo)->toBe(251.031)
        ->and($this->cj32->fresh()?->precio)->toBe(313.7888)
        ->and(Existencia::de($this->bodega->id, $this->cj48->id))->toBe(10)
        ->and(Existencia::de($this->bodega->id, $this->cj32->id))->toBe(10)
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe(5)
        ->and(Existencia::en($this->bodega->id, $this->jabon->id))->toBe(805)
        ->and($compra->total)->toBe(6315.0)
        ->and($this->jabon->fresh()?->existenciaPorPresentacion(Existencia::porPresentacion($this->bodega->id, $this->jabon->id)))->toBe('10 CJ48 · 10 CJ32 · 5 UN');
});

test('al despachar sueltas que no alcanzan se abre la caja que dio el modal: sus unidades pasan a las sueltas y nada cambia de costo', function (): void {
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

    // Hay 5 sueltas y salen 8: el modal da por abierta 1 CJ48.
    $historial = CambioPrecio::query()->count();
    app(ValidarDespacho::class)->ejecutar($pedido, [], $this->encargado, abrir: [$this->cj48->id => 1]);

    // La unidad cuesta 7.8447 en cualquier presentación: abrir la caja no cambia costos ni precios.
    $costo = 7.8447;
    $kardex = MovimientoInventario::query()->where('pedido_id', $pedido->id)->orderBy('id')->get();

    expect(Existencia::de($this->bodega->id, $this->cj48->id))->toBe(9)
        ->and(Existencia::de($this->bodega->id, $this->cj32->id))->toBe(10)
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe(45)
        ->and($this->un->fresh()?->costo)->toBe($costo)
        ->and($this->un->fresh()?->precio)->toBe(CalculadoraPrecio::precioVenta($costo, 0.20))
        ->and($this->cj48->fresh()?->costo)->toBe(376.5465)
        ->and($this->cj32->fresh()?->costo)->toBe(251.031)
        ->and(CambioPrecio::query()->count())->toBe($historial)
        // Al cliente se le cobra el precio que tenía la UN al validar.
        ->and($pedido->items()->sole()->precio)->toBe(9.8059)
        ->and($kardex->map(fn (MovimientoInventario $m): array => [$m->tipo, $m->presentacion_id, $m->cantidad, $m->costo_unidad])->all())->toBe([
            [TipoMovimiento::Apertura, $this->cj48->id, -1, 376.5465],
            [TipoMovimiento::Apertura, $this->un->id, 48, $costo],
            [TipoMovimiento::Despacho, $this->un->id, -8, $costo],
        ]);
});

test('hay 10 cajas y ninguna suelta: salen 6 unidades sin que nadie diga nada, se abre una caja y las demás quedan sueltas; al corregir, igual', function (): void {
    Configuracion::actual()->update(['controlar_existencias' => true]);
    app(RegistrarCompra::class)->ejecutar($this->bodega, $this->jabon->proveedor, [
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->cj48->id, 'cantidad' => 10, 'costo' => 312.0],
    ], $this->admin);

    $pedido = Pedido::query()->create([
        'bodega_id'   => $this->bodega->id,
        'cliente_id'  => Cliente::factory()->create(['bodega_id' => $this->bodega->id])->id,
        'vendedor_id' => $this->vendedor->id,
        'fecha'       => now(),
    ]);
    $linea = $pedido->items()->create(['producto_id' => $this->jabon->id, 'presentacion_id' => $this->un->id, 'cantidad' => 6]);
    app(RecalcularPedido::class)->ejecutar($pedido);

    app(ValidarDespacho::class)->ejecutar($pedido, [], $this->encargado);

    $aperturas = fn (): array => MovimientoInventario::query()->where('pedido_id', $pedido->id)->where('tipo', TipoMovimiento::Apertura)->orderBy('id')->get()
        ->map(fn (MovimientoInventario $m): array => [$m->presentacion_id, $m->cantidad])->all();

    // 9 cajas cerradas y 48 − 6 = 42 sueltas.
    expect([Existencia::de($this->bodega->id, $this->cj48->id), Existencia::de($this->bodega->id, $this->un->id)])->toBe([9, 42])
        ->and($aperturas())->toBe([[$this->cj48->id, -1], [$this->un->id, 48]]);

    // Se corrige a 50: salen 44 más y hay 42 sueltas; se abre otra caja sola.
    app(CorregirPedido::class)->ejecutar($pedido, [$linea->id => 50], $this->encargado, 'Pidió más');

    expect([Existencia::de($this->bodega->id, $this->cj48->id), Existencia::de($this->bodega->id, $this->un->id)])->toBe([8, 46])
        ->and($aperturas())->toHaveCount(4);
});

test('con "último costo" la compra de una caja deja la unidad a lo que costó ahí; abrirla no lo cambia', function (): void {
    Configuracion::actual()->update(['metodo_costo' => MetodoCosto::Ultimo]);
    app(RegistrarCompra::class)->ejecutar($this->bodega, $this->jabon->proveedor, [
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->cj32->id, 'cantidad' => 2, 'costo' => 320.0],
    ], $this->admin);

    app(AbrirCaja::class)->ejecutar($this->bodega->id, $this->cj32, 1, $this->encargado);

    expect($this->un->fresh()?->costo)->toBe(10.0)
        ->and($this->cj48->fresh()?->costo)->toBe(480.0)
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

test('el regalo de una promoción gratis en sueltas que no alcanzan abre solo la caja más chica que sobra', function (): void {
    // Hay 2 CJ48 y 1 CJ32, sin sueltas. Por cada UN del otro producto, 1 UN de jabón gratis.
    app(RegistrarCompra::class)->ejecutar($this->bodega, $this->jabon->proveedor, [
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->cj48->id, 'cantidad' => 2, 'costo' => 312.0],
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->cj32->id, 'cantidad' => 1, 'costo' => 208.0],
    ], $this->admin);
    $otro = Producto::factory()->create(['categoria_id' => $this->jabon->categoria_id]);
    Promocion::factory()->para($otro, $this->jabon)->create();

    $pedido = Pedido::query()->create([
        'bodega_id'   => $this->bodega->id,
        'cliente_id'  => Cliente::factory()->create(['bodega_id' => $this->bodega->id])->id,
        'vendedor_id' => $this->vendedor->id,
        'fecha'       => now(),
    ]);
    $pedido->items()->create(['producto_id' => $otro->id, 'presentacion_id' => presentacion($otro)->id, 'cantidad' => 3]);
    app(RecalcularPedido::class)->ejecutar($pedido);

    // Bodega no escribe el regalo ni abre nada: sale 3 UN de jabón de la CJ32 (la más chica).
    app(ValidarDespacho::class)->ejecutar($pedido, [], $this->encargado);

    expect($pedido->items()->where('bonificacion', true)->sole()->cantidad_despachada)->toBe(3)
        ->and(Existencia::de($this->bodega->id, $this->cj32->id))->toBe(0)
        ->and(Existencia::de($this->bodega->id, $this->cj48->id))->toBe(2)
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe(29)
        ->and(MovimientoInventario::query()->where('pedido_id', $pedido->id)->where('tipo', TipoMovimiento::Apertura)->orderBy('id')->pluck('cantidad', 'presentacion_id')->all())
        ->toBe([$this->cj32->id => -1, $this->un->id => 32]);
});
