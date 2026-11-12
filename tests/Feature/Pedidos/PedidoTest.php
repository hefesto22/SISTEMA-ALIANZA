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
use App\Filament\Resources\Pedidos\Actions\LineasPedido;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Existencia;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Database\Seeders\RolesSistemaSeeder;

beforeEach(function (): void {
    $this->seed(RolesSistemaSeeder::class);

    $this->bodega = Bodega::factory()->create(['codigo' => 'COP']);
    $this->vendedor = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->vendedor->assignRole(Rol::Vendedor->value);

    // Gravado: L 100 la UN (CJ12 a L 1,200). Exento: L 50 la UN, CJ10 = L 500.
    $this->gravado = Producto::factory()->precio(80.0, 100.0)->create();
    $this->exento = Producto::factory()->exento()->precio(40.0, 50.0)->conCaja(10)->create();
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

    $pedido->items()->create(['producto_id' => $gravado->id, 'presentacion_id' => presentacion($gravado)->id, 'cantidad' => 2]);
    $pedido->items()->create(['producto_id' => $exento->id, 'presentacion_id' => caja($exento)->id, 'cantidad' => 1]);

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
    app(ActualizarPrecioProducto::class)->ejecutar($this->gravado, 80.0, MotivoCambioPrecio::Manual, precios: [presentacion($this->gravado)->id => ['precio' => 110.0]]);

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

test('al validar, cada producto trae todas sus presentaciones aunque el pedido traiga una sola', function (): void {
    $pedido = crearPedido($this->bodega, $this->vendedor, $this->gravado, $this->exento);
    $filas = LineasPedido::datos($pedido)['items'];

    // Primero las líneas del pedido; después, sin id, las presentaciones que no traen.
    expect(array_map(fn (array $fila): array => [$fila['id'] === null, $fila['producto_id'], $fila['sigla'], $fila['cantidad']], $filas))->toBe([
        [false, $this->gravado->id, 'UN', 2],
        [false, $this->exento->id, 'CJ10', 1],
        [true, $this->gravado->id, 'CJ12', 0],
        [true, $this->exento->id, 'UN', 0],
    ]);

    // Lo que bodega llena en esas filas entra como línea nueva.
    $filas[3]['sale'] = 7;

    expect(LineasPedido::nuevas(['items' => $filas]))->toBe([['producto_id' => $this->exento->id, 'presentacion_id' => presentacion($this->exento)->id, 'cantidad' => 7]]);
});

test('bodega puede mandar en otra presentación: una caja dañada sale en unidades sueltas', function (): void {
    $pedido = crearPedido($this->bodega, $this->vendedor, $this->gravado, $this->exento);
    [, $lineaExenta] = $pedido->items->all();

    // La caja del exento (10) vino dañada: la caja no sale y salen 7 sueltas.
    $despachado = app(ValidarDespacho::class)->ejecutar($pedido, [$lineaExenta->id => 0], $this->vendedor, [
        ['producto_id' => $this->exento->id, 'presentacion_id' => presentacion($this->exento)->id, 'cantidad' => 7],
    ]);

    $sueltas = $despachado->items()->where('presentacion_id', presentacion($this->exento)->id)->sole();

    // 2 × 100 = 200 (+30 ISV) y 7 × 50 = 350 exento → 580
    expect($sueltas->cantidad)->toBe(0)
        ->and($sueltas->cantidad_despachada)->toBe(7)
        ->and($sueltas->deMasEnPalabras())->toBe('Va de más: 7 unidades (no lo pidió en unidades)')
        ->and($lineaExenta->fresh()?->faltaEnPalabras())->toBe('No sale: pidió 1 caja de 10')
        ->and($despachado->total)->toBe(580.0);
});

test('bodega puede agregar al validar un producto que no venía en el pedido', function (): void {
    $pedido = crearPedido($this->bodega, $this->vendedor, $this->gravado, $this->exento);

    // Gravado: L 30 la UN, CJ6 = L 180.
    $sal = Producto::factory()->precio(20.0, 30.0)->conCaja(6)->create();

    $despachado = app(ValidarDespacho::class)->ejecutar($pedido, [], $this->vendedor, [
        ['producto_id' => $sal->id, 'presentacion_id' => caja($sal)->id, 'cantidad' => 2],
    ]);

    $agregada = $despachado->items()->where('producto_id', $sal->id)->sole();

    // 2 × 100 = 200 (+30 ISV), 1 caja × 500 = 500 exento y 2 cajas × 180 = 360 (+54 ISV) → 1144
    expect($agregada->cantidad)->toBe(0)
        ->and($agregada->presentacion_id)->toBe(caja($sal)->id)
        ->and($agregada->cantidad_despachada)->toBe(2)
        ->and($agregada->esAgregado())->toBeTrue()
        ->and($agregada->deMasEnPalabras())->toBe('Agregado: 2 cajas de 6 (no venía en el pedido)')
        ->and($agregada->faltaEnPalabras())->toBeNull()
        ->and($despachado->total)->toBe(1144.0)
        ->and(LineasPedido::incompletas($despachado))->toBe("Sale completo. Agregado: {$sal->descripcion} (2 cajas de 6).");
});

test('al validar no se agrega un producto que no está activo', function (): void {
    $pedido = crearPedido($this->bodega, $this->vendedor, $this->gravado, $this->exento);
    $viejo = Producto::factory()->create(['activo' => false]);

    expect(fn (): Pedido => app(ValidarDespacho::class)->ejecutar($pedido, [], $this->vendedor, [
        ['producto_id' => $viejo->id, 'presentacion_id' => presentacion($viejo)->id, 'cantidad' => 1],
    ]))->toThrow(ReglaNegocioException::class, "{$viejo->descripcion} no está activo");

    expect($pedido->fresh()?->estado)->toBe(EstadoPedido::Pendiente)
        ->and($pedido->items()->count())->toBe(2);
});

test('el buscador de agregar trae productos activos, primero los que hay en la bodega, con sus filas', function (): void {
    $pedido = crearPedido($this->bodega, $this->vendedor, $this->gravado, $this->exento);

    $gruesa = Producto::factory()->create(['descripcion' => 'ZZSAL GRUESA 12X1KG']);
    $mesa = Producto::factory()->conCaja(6)->create(['descripcion' => 'ZZSAL DE MESA 6X1KG']);
    Producto::factory()->create(['descripcion' => 'ZZSAL VIEJA 6X1KG', 'activo' => false]);

    // De la de mesa hay 4 unidades (no alcanza para una CJ6): sale 1 UN.
    // De la gruesa no hay nada: sale 1 de la más grande (CJ12).
    Existencia::query()->create(['bodega_id' => $this->bodega->id, 'producto_id' => $mesa->id, 'cantidad' => 4]);

    $resultados = LineasPedido::paraAgregar($pedido, 'zzsal');
    $filas = fn (int $i): array => array_map(fn (array $fila): array => [$fila['id'], $fila['sigla'], $fila['cantidad'], $fila['sale'], $fila['hay_unidades']], $resultados[$i]['filas']);

    expect(array_column($resultados, 'producto_id'))->toBe([$mesa->id, $gruesa->id])
        ->and($resultados[0]['hay_unidades'])->toBe(4)
        ->and($resultados[0]['hay'])->toBe('4 UN')
        ->and($filas(0))->toBe([[null, 'CJ6', 0, 0, 4], [null, 'UN', 0, 1, 4]])
        ->and($filas(1))->toBe([[null, 'CJ12', 0, 1, 0], [null, 'UN', 0, 0, 0]])
        ->and(LineasPedido::paraAgregar($pedido, '  '))->toBe([]);
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
