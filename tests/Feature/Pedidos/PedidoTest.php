<?php

declare(strict_types=1);

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Pedidos\AnularPedido;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarDespacho;
use App\Domain\Precios\ActualizarPrecioProducto;
use App\Enums\EstadoPedido;
use App\Enums\MotivoCambioPrecio;
use App\Enums\Rol;
use App\Enums\UnidadVenta;
use App\Filament\Resources\Pedidos\Actions\LineasPedido;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Database\Seeders\RolesSistemaSeeder;

beforeEach(function (): void {
    $this->seed(RolesSistemaSeeder::class);

    $this->bodega = Bodega::factory()->create(['codigo' => 'COP']);
    $this->vendedor = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->vendedor->assignRole(Rol::Vendedor->value);

    // Gravado: L 100 la unidad. Exento: L 50 la unidad, caja de 10 = L 500.
    $this->gravado = Producto::factory()->precio(80.0, 100.0)->create(['unidades_por_caja' => 12]);
    $this->exento = Producto::factory()->exento()->precio(40.0, 50.0)->create(['unidades_por_caja' => 10]);
});

/**
 * Pedido de 2 unidades del gravado + 1 caja del exento.
 */
function crearPedido(Bodega $bodega, User $vendedor, Producto $gravado, Producto $exento): Pedido
{
    $pedido = Pedido::query()->create([
        'bodega_id'   => $bodega->id,
        'cliente_id'  => Cliente::factory()->create(['bodega_id' => $bodega->id])->id,
        'vendedor_id' => $vendedor->id,
        'fecha'       => now(),
    ]);

    $pedido->items()->create(['producto_id' => $gravado->id, 'unidad' => UnidadVenta::Unidad, 'cantidad' => 2]);
    $pedido->items()->create(['producto_id' => $exento->id, 'unidad' => UnidadVenta::Caja, 'cantidad' => 1]);

    return app(RecalcularPedido::class)->ejecutar($pedido);
}

test('el pedido toma los precios de hoy y calcula ISV solo de lo gravado', function (): void {
    $pedido = crearPedido($this->bodega, $this->vendedor, $this->gravado, $this->exento);

    // 2 × 100 = 200 (+30 ISV) y 1 caja × 500 = 500 (exento)
    expect($pedido->subtotal)->toBe(700.0)
        ->and($pedido->isv)->toBe(30.0)
        ->and($pedido->total)->toBe(730.0)
        ->and($pedido->estado)->toBe(EstadoPedido::Pendiente)
        ->and($pedido->numero)->toMatch('/^COP-\d{6}$/');
});

test('bodega despacha lo que hay y se cobra con el precio vigente al despachar', function (): void {
    $pedido = crearPedido($this->bodega, $this->vendedor, $this->gravado, $this->exento);
    $encargado = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $encargado->assignRole(Rol::Bodega->value);

    // Sube el precio después de la preventa.
    app(ActualizarPrecioProducto::class)->ejecutar($this->gravado, 80.0, MotivoCambioPrecio::Manual, precioUnidad: 110.0);

    [$lineaGravada, $lineaExenta] = $pedido->items->all();

    $despachado = app(ValidarDespacho::class)->ejecutar($pedido, [
        $lineaGravada->id => 1,   // pidió 2, sale 1
        $lineaExenta->id  => 0,   // no hay
    ], $encargado);

    expect($despachado->estado)->toBe(EstadoPedido::PorImprimir)
        ->and($despachado->validado_por)->toBe($encargado->id)
        ->and($despachado->subtotal)->toBe(110.0)
        ->and($despachado->isv)->toBe(16.5)
        ->and($despachado->total)->toBe(126.5)
        ->and($lineaGravada->fresh()?->cantidad_despachada)->toBe(1)
        ->and($lineaGravada->fresh()?->faltante())->toBe(1)
        ->and($lineaExenta->fresh()?->cantidad_despachada)->toBe(0);
});

test('bodega puede mandar más de lo pedido: se cobra lo que sale y queda dicho', function (): void {
    $pedido = crearPedido($this->bodega, $this->vendedor, $this->gravado, $this->exento);
    [$lineaGravada] = $pedido->items->all();

    // Pidió 2 del gravado y salen 3; el exento sale completo.
    $despachado = app(ValidarDespacho::class)->ejecutar($pedido, [$lineaGravada->id => 3], $this->vendedor);
    $linea = $lineaGravada->fresh();

    // 3 × 100 = 300 (+45 ISV) y 1 caja × 500 = 500 → 845
    expect($linea?->cantidad_despachada)->toBe(3)
        ->and($linea?->faltante())->toBe(0)
        ->and($linea?->faltaEnPalabras())->toBeNull()
        ->and($linea?->deMasEnPalabras())->toBe('Va de más: 1 unidad más de lo pedido (pidió 2)')
        ->and($despachado->total)->toBe(845.0)
        ->and(LineasPedido::incompletas($despachado))->toBe("Va de más: {$this->gravado->descripcion} (+1 unidad, pidió 2).");
});

test('al validar, cada producto trae su caja y su unidad aunque el pedido traiga una sola', function (): void {
    $pedido = crearPedido($this->bodega, $this->vendedor, $this->gravado, $this->exento);
    $filas = LineasPedido::datos($pedido)['items'];

    // Primero las líneas del pedido; después, sin id, la unidad que no traen.
    expect(array_map(fn (array $fila): array => [$fila['id'] === null, $fila['producto_id'], $fila['es_caja'], $fila['cantidad']], $filas))->toBe([
        [false, $this->gravado->id, false, 2],
        [false, $this->exento->id, true, 1],
        [true, $this->gravado->id, true, 0],
        [true, $this->exento->id, false, 0],
    ]);

    // Lo que bodega llena en esas filas entra como línea nueva.
    $filas[3]['sale'] = 7;

    expect(LineasPedido::nuevas(['items' => $filas]))->toBe([['producto_id' => $this->exento->id, 'unidad' => UnidadVenta::Unidad, 'cantidad' => 7]]);
});

test('bodega puede mandar en la otra unidad: una caja dañada sale en unidades sueltas', function (): void {
    $pedido = crearPedido($this->bodega, $this->vendedor, $this->gravado, $this->exento);
    [, $lineaExenta] = $pedido->items->all();

    // La caja del exento (10) vino dañada: la caja no sale y salen 7 sueltas.
    $despachado = app(ValidarDespacho::class)->ejecutar($pedido, [$lineaExenta->id => 0], $this->vendedor, [
        ['producto_id' => $this->exento->id, 'unidad' => UnidadVenta::Unidad, 'cantidad' => 7],
    ]);

    $sueltas = $despachado->items()->where('producto_id', $this->exento->id)->where('unidad', UnidadVenta::Unidad)->sole();

    // 2 × 100 = 200 (+30 ISV) y 7 × 50 = 350 exento → 580
    expect($sueltas->cantidad)->toBe(0)
        ->and($sueltas->cantidad_despachada)->toBe(7)
        ->and($sueltas->deMasEnPalabras())->toBe('Va de más: 7 unidades (no lo pidió en unidades)')
        ->and($lineaExenta->fresh()?->faltaEnPalabras())->toBe('No sale: pidió 1 caja')
        ->and($despachado->total)->toBe(580.0);
});

test('un pedido despachado no se puede validar otra vez', function (): void {
    $pedido = crearPedido($this->bodega, $this->vendedor, $this->gravado, $this->exento);
    app(ValidarDespacho::class)->ejecutar($pedido, [], $this->vendedor);

    app(ValidarDespacho::class)->ejecutar($pedido->fresh() ?? $pedido, [], $this->vendedor);
})->throws(ReglaNegocioException::class);

test('solo se anulan pedidos pendientes', function (): void {
    $pedido = crearPedido($this->bodega, $this->vendedor, $this->gravado, $this->exento);

    expect(app(AnularPedido::class)->ejecutar($pedido, 'Cliente cerrado')->estado)->toBe(EstadoPedido::Anulado);

    app(AnularPedido::class)->ejecutar($pedido);
})->throws(ReglaNegocioException::class);

test('el vendedor solo ve sus pedidos y bodega solo los de su bodega', function (): void {
    $mio = crearPedido($this->bodega, $this->vendedor, $this->gravado, $this->exento);

    $otroVendedor = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $otroVendedor->assignRole(Rol::Vendedor->value);

    $otraBodega = Bodega::factory()->create(['codigo' => 'OCO']);
    $encargadoOtra = User::factory()->create(['bodega_id' => $otraBodega->id]);
    $encargadoOtra->assignRole(Rol::Bodega->value);

    $encargado = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $encargado->assignRole(Rol::Bodega->value);

    $admin = User::factory()->create();
    $admin->assignRole(Rol::Administrador->value);

    expect(Pedido::query()->visiblePara($this->vendedor)->pluck('id')->all())->toBe([$mio->id])
        ->and(Pedido::query()->visiblePara($otroVendedor)->count())->toBe(0)
        ->and(Pedido::query()->visiblePara($encargado)->count())->toBe(1)
        ->and(Pedido::query()->visiblePara($encargadoOtra)->count())->toBe(0)
        ->and(Pedido::query()->visiblePara($admin)->count())->toBe(1);

    expect($encargado->can('validar', $mio))->toBeTrue()
        ->and($encargadoOtra->can('validar', $mio))->toBeFalse()
        ->and($this->vendedor->can('validar', $mio))->toBeFalse()
        ->and($this->vendedor->can('update', $mio))->toBeTrue()
        ->and($otroVendedor->can('update', $mio))->toBeFalse();
});
