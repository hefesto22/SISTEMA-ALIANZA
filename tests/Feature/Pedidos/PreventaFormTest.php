<?php

declare(strict_types=1);

use App\Domain\Pedidos\RecalcularPedido;
use App\Enums\EstadoPedido;
use App\Enums\Rol;
use App\Filament\Resources\Pedidos\Pages\CreatePedido;
use App\Filament\Resources\Pedidos\Pages\EditPedido;
use App\Filament\Resources\Pedidos\Schemas\PedidoForm;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\User;
use Database\Seeders\RolesSistemaSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Preventa con los productos en lista tipo carrito (LineasPedido): el
 * buscador, el total con promociones que pide el teléfono y cómo se guardan
 * las líneas al crear y al editar.
 */
beforeEach(function (): void {
    $this->seed(RolesSistemaSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->bodega = Bodega::factory()->create(['codigo' => 'COP']);

    $this->vendedor = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->vendedor->assignRole(Rol::Vendedor->value);

    $this->encargado = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->encargado->assignRole(Rol::Bodega->value);

    // L 100 la UN sin ISV; la CJ12 a L 1,200.
    $this->producto = Producto::factory()->precio(80.0, 100.0)->create(['descripcion' => 'ACEITE EL DORADO 20X750ML']);
});

/**
 * Pedido pendiente con las líneas dadas: [[producto, lo que trae la
 * presentación (1 = UN, 12 = CJ12), cantidad], …].
 *
 * @param list<array{0: Producto, 1: int, 2: int}> $lineas
 */
function pedidoConLineas(Bodega $bodega, User $vendedor, array $lineas): Pedido
{
    $pedido = Pedido::query()->create([
        'bodega_id'   => $bodega->id,
        'cliente_id'  => Cliente::factory()->create(['bodega_id' => $bodega->id])->id,
        'vendedor_id' => $vendedor->id,
        'fecha'       => now(),
    ]);

    foreach ($lineas as [$producto, $contenido, $cantidad]) {
        $pedido->items()->create(['producto_id' => $producto->id, 'presentacion_id' => presentacion($producto, $contenido)->id, 'cantidad' => $cantidad]);
    }

    return app(RecalcularPedido::class)->ejecutar($pedido);
}

test('el buscador trae solo productos activos, con el precio con ISV de cada presentación que se vende', function (): void {
    Producto::factory()->precio(80.0, 100.0)->create(['descripcion' => 'ACEITE VIEJO 12X1L', 'activo' => false]);

    $this->actingAs($this->vendedor);

    /** @var list<array<string, mixed>> $resultados */
    $resultados = Livewire::test(CreatePedido::class)
        ->instance()
        ->callSchemaComponentMethod('form.items', 'buscarProductos', ['buscar' => 'aceite']);

    expect($resultados)->toHaveCount(1)
        ->and($resultados[0]['id'])->toBe($this->producto->id)
        ->and(array_column($resultados[0]['presentaciones'], 'sigla'))->toBe(['CJ12', 'UN'])
        ->and(array_column($resultados[0]['presentaciones'], 'precioIsv'))->toBe([1380.0, 115.0])
        ->and($resultados[0]['mayor_sigla'])->toBe('CJ12')
        ->and($resultados[0]['base_sigla'])->toBe('UN');
});

test('el último pedido del cliente se puede repetir aunque ya esté validado: solo lo que se vende y lo del mismo vendedor', function (): void {
    $viejo = Producto::factory()->precio(10.0, 20.0)->create(['activo' => false]);
    $cliente = Cliente::factory()->create(['bodega_id' => $this->bodega->id]);

    $anterior = pedidoConLineas($this->bodega, $this->vendedor, [[$this->producto, 12, 2], [$viejo, 1, 5]]);
    // Ya validado: el vendedor no lo ve en su lista, pero sí sirve para repetirlo.
    $anterior->forceFill(['cliente_id' => $cliente->id, 'estado' => EstadoPedido::PorImprimir])->save();

    // Uno anulado más reciente no cuenta, ni uno de otro vendedor.
    $anulado = pedidoConLineas($this->bodega, $this->vendedor, [[$this->producto, 1, 9]]);
    $anulado->forceFill(['cliente_id' => $cliente->id, 'estado' => EstadoPedido::Anulado])->save();
    $otroVendedor = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $otroVendedor->assignRole(Rol::Vendedor->value);
    $ajeno = pedidoConLineas($this->bodega, $otroVendedor, [[$this->producto, 1, 7]]);
    $ajeno->update(['cliente_id' => $cliente->id]);

    $this->actingAs($this->vendedor);

    /** @var array{numero: string, lineas: list<array<string, mixed>>, productos: array<int, mixed>, fuera: int} $ultimo */
    $ultimo = Livewire::test(CreatePedido::class)
        ->instance()
        ->callSchemaComponentMethod('form.items', 'ultimoPedido', ['clienteId' => $cliente->id]);

    expect($ultimo['numero'])->toBe($anterior->numero)
        ->and($ultimo['lineas'])->toBe([['producto_id' => $this->producto->id, 'presentacion_id' => caja($this->producto)->id, 'cantidad' => 2]])
        ->and(array_keys($ultimo['productos']))->toBe([$this->producto->id])
        ->and($ultimo['fuera'])->toBe(1);
});

test('el total que pide el teléfono trae las promociones', function (): void {
    $toffee = Producto::factory()->precio(20.0, 30.0)->create();
    $nutrine = Producto::factory()->precio(20.0, 25.30)->create();
    Promocion::factory()->para($toffee, $nutrine, porcentaje: 50)->create();

    // 2 cajas × 12 × L 100 = L 2,400 + 15% = L 2,760
    expect(PedidoForm::resumenDe([['id' => null, 'producto_id' => $this->producto->id, 'presentacion_id' => caja($this->producto)->id, 'cantidad' => 2]]))
        ->toMatchArray(['total' => 2760.0, 'ahorro' => 0.0]);

    // 30 + 25.30 = 55.30 − 12.65 (nutrine a mitad) = 42.65 + 15% ISV = 49.05
    $resumen = PedidoForm::resumenDe([
        ['id' => null, 'producto_id' => $toffee->id, 'presentacion_id' => presentacion($toffee)->id, 'cantidad' => 1],
        ['id' => null, 'producto_id' => $nutrine->id, 'presentacion_id' => presentacion($nutrine)->id, 'cantidad' => 1],
    ]);

    // En la fila del producto se ve lo que le quita la promoción (con ISV).
    expect($resumen)->toMatchArray(['total' => 49.05, 'ahorro' => 12.65])
        ->and(array_keys($resumen['descuentos']))->toBe([$nutrine->id])
        ->and($resumen['descuentos'][$nutrine->id])->toBeGreaterThan(12.65);
});

test('la preventa trae las promociones de hoy de su bodega, con lo que hay que agregar para llevarlas', function (): void {
    $regalo = Producto::factory()->precio(10.0, 20.0)->create(['descripcion' => 'JABON DE REGALO']);
    $otra = Bodega::factory()->create();

    $suya = Promocion::factory()->para($this->producto, $regalo, cantidad: 2, enCaja: true)->create();
    $suya->bodegas()->sync([$this->bodega->id]);
    $deOtra = Promocion::factory()->para($this->producto, $regalo)->create();
    $deOtra->bodegas()->sync([$otra->id]);

    $this->actingAs($this->vendedor);

    /** @var array{promos: list<array<string, mixed>>, productos: array<int, array<string, mixed>>} $hoy */
    $hoy = Livewire::test(CreatePedido::class)
        ->instance()
        ->callSchemaComponentMethod('form.items', 'promocionesDeHoy', []);

    expect(array_column($hoy['promos'], 'id'))->toBe([$suya->id])
        ->and($hoy['promos'][0]['compra'])->toBe([$this->producto->id])
        ->and($hoy['promos'][0]['regalo'])->toBe($regalo->id)
        ->and($hoy['promos'][0]['lineas'])->toBe([
            ['producto_id' => $this->producto->id, 'presentacion_id' => caja($this->producto)->id, 'cantidad' => 2],
            ['producto_id' => $regalo->id, 'presentacion_id' => presentacion($regalo)->id, 'cantidad' => 1],
        ])
        ->and(array_keys($hoy['productos']))->toEqualCanonicalizing([$this->producto->id, $regalo->id]);
});

test('un producto lleva varias presentaciones en el mismo pedido; si viene repetida se junta', function (): void {
    $this->actingAs($this->encargado);

    Livewire::test(CreatePedido::class)
        ->fillForm([
            'cliente_id'  => Cliente::factory()->create(['bodega_id' => $this->bodega->id])->id,
            'vendedor_id' => $this->vendedor->id,
            'fecha'       => now()->toDateString(),
            'items'       => [
                ['producto_id' => $this->producto->id, 'presentacion_id' => caja($this->producto)->id, 'cantidad' => 2],
                ['producto_id' => $this->producto->id, 'presentacion_id' => presentacion($this->producto)->id, 'cantidad' => 3],
                ['producto_id' => $this->producto->id, 'presentacion_id' => presentacion($this->producto)->id, 'cantidad' => 2],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $pedido = Pedido::query()->sole();
    $items = $pedido->items()->orderBy('id')->get();

    // 2 cajas × 12 × L 100 = 2,400 y 5 unidades × L 100 = 500 → 2,900 + 15% = 3,335
    expect($items)->toHaveCount(2)
        ->and($items[0]->presentacion_id)->toBe(caja($this->producto)->id)
        ->and($items[0]->contenido)->toBe(12)
        ->and($items[0]->cantidad)->toBe(2)
        ->and($items[1]->presentacion_id)->toBe(presentacion($this->producto)->id)
        ->and($items[1]->contenido)->toBe(1)
        ->and($items[1]->cantidad)->toBe(5)
        ->and($pedido->total)->toBe(3335.0);
});

test('sin productos no se guarda el pedido', function (): void {
    $this->actingAs($this->encargado);

    Livewire::test(CreatePedido::class)
        ->assertSee('Guardar pedido')
        ->assertSee('Agregue productos')
        ->fillForm([
            'cliente_id'  => Cliente::factory()->create(['bodega_id' => $this->bodega->id])->id,
            'vendedor_id' => $this->vendedor->id,
            'fecha'       => now()->toDateString(),
            'items'       => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['items' => 'required']);

    expect(Pedido::query()->count())->toBe(0);
});

test('al editar se cargan las líneas, y al guardar se cambian, se quitan y se agregan', function (): void {
    $otro = Producto::factory()->precio(40.0, 50.0)->conCaja(10)->create();
    $pedido = pedidoConLineas($this->bodega, $this->vendedor, [
        [$this->producto, 1, 3],
        [$otro, 1, 1],
    ]);
    [$primera, $segunda] = $pedido->items()->orderBy('id')->get()->all();

    $this->actingAs($this->vendedor);

    Livewire::test(EditPedido::class, ['record' => $pedido->getRouteKey()])
        ->assertSee('Guardar cambios')
        ->assertSee('2 productos')
        ->assertFormSet(['items' => [
            ['id' => $primera->id, 'producto_id' => $this->producto->id, 'presentacion_id' => presentacion($this->producto)->id, 'cantidad' => 3],
            ['id' => $segunda->id, 'producto_id' => $otro->id, 'presentacion_id' => presentacion($otro)->id, 'cantidad' => 1],
        ]])
        ->fillForm(['items' => [
            ['id' => $primera->id, 'producto_id' => $this->producto->id, 'presentacion_id' => caja($this->producto)->id, 'cantidad' => 2],
            // Línea nueva (sin id): en las pruebas fillForm mezcla con lo que había, por eso el id va en null.
            ['id' => null, 'producto_id' => $otro->id, 'presentacion_id' => caja($otro)->id, 'cantidad' => 1],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    $items = $pedido->items()->orderBy('id')->get();

    // 2 cajas × 12 × L 100 = 2,400 y 1 caja × 10 × L 50 = 500 → 2,900 + 15% = 3,335
    expect($items)->toHaveCount(2)
        ->and($items[0]->id)->toBe($primera->id)
        ->and($items[0]->presentacion_id)->toBe(caja($this->producto)->id)
        ->and($items[0]->contenido)->toBe(12)
        ->and($items[0]->cantidad)->toBe(2)
        ->and($items[1]->producto_id)->toBe($otro->id)
        ->and($items[1]->presentacion_id)->toBe(caja($otro)->id)
        ->and(PedidoItem::query()->whereKey($segunda->id)->exists())->toBeFalse()
        ->and($pedido->fresh()?->total)->toBe(3335.0);
});

test('una línea con el id de otro pedido no lo toca: entra como nueva', function (): void {
    $ajeno = pedidoConLineas($this->bodega, $this->vendedor, [[$this->producto, 1, 4]]);
    $deOtro = $ajeno->items()->sole();
    $pedido = pedidoConLineas($this->bodega, $this->vendedor, [[$this->producto, 1, 1]]);

    $this->actingAs($this->vendedor);

    Livewire::test(EditPedido::class, ['record' => $pedido->getRouteKey()])
        ->fillForm(['items' => [
            ['id' => $deOtro->id, 'producto_id' => $this->producto->id, 'presentacion_id' => presentacion($this->producto)->id, 'cantidad' => 7],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($deOtro->fresh()?->cantidad)->toBe(4)
        ->and($deOtro->fresh()?->pedido_id)->toBe($ajeno->id)
        ->and($pedido->items()->sole()->cantidad)->toBe(7)
        ->and($pedido->items()->sole()->id)->not->toBe($deOtro->id);
});

test('no se vende en una presentación desactivada o sin precio: no sale en el buscador y no se guarda', function (): void {
    caja($this->producto)->forceFill(['activo' => false])->save();
    $enFardo = Producto::factory()->precio(80.0, 100.0)->conPresentacion('FD', 6)->create(['descripcion' => 'ACEITE EN FARDO 6X1L']);
    presentacion($enFardo, 6)->forceFill(['precio' => 0])->save();

    $this->actingAs($this->vendedor);

    /** @var list<array<string, mixed>> $resultados */
    $resultados = Livewire::test(CreatePedido::class)
        ->instance()
        ->callSchemaComponentMethod('form.items', 'buscarProductos', ['buscar' => 'aceite']);

    expect(array_column($resultados, 'id'))->toBe([$this->producto->id, $enFardo->id])
        ->and(array_column($resultados[0]['presentaciones'], 'sigla'))->toBe(['UN'])
        ->and(array_column($resultados[1]['presentaciones'], 'sigla'))->toBe(['CJ12', 'UN']);

    $this->actingAs($this->encargado);

    foreach ([[$this->producto, caja($this->producto)], [$enFardo, presentacion($enFardo, 6)]] as [$producto, $presentacion]) {
        Livewire::test(CreatePedido::class)
            ->fillForm([
                'cliente_id'  => Cliente::factory()->create(['bodega_id' => $this->bodega->id])->id,
                'vendedor_id' => $this->vendedor->id,
                'fecha'       => now()->toDateString(),
                'items'       => [['producto_id' => $producto->id, 'presentacion_id' => $presentacion->id, 'cantidad' => 1]],
            ])
            ->call('create')
            ->assertHasFormErrors(['items']);
    }

    expect(Pedido::query()->count())->toBe(0);
});
