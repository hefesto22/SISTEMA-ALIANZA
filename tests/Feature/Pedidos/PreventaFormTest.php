<?php

declare(strict_types=1);

use App\Domain\Pedidos\RecalcularPedido;
use App\Enums\EstadoPedido;
use App\Enums\Rol;
use App\Enums\UnidadVenta;
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

    // L 100 la unidad sin ISV; la caja trae 12.
    $this->producto = Producto::factory()->precio(80.0, 100.0)->create(['descripcion' => 'ACEITE EL DORADO 20X750ML', 'unidades_por_caja' => 12]);
});

/**
 * Pedido pendiente con las líneas dadas: [[producto, unidad, cantidad], …].
 *
 * @param list<array{0: Producto, 1: UnidadVenta, 2: int}> $lineas
 */
function pedidoConLineas(Bodega $bodega, User $vendedor, array $lineas): Pedido
{
    $pedido = Pedido::query()->create([
        'bodega_id'   => $bodega->id,
        'cliente_id'  => Cliente::factory()->create(['bodega_id' => $bodega->id])->id,
        'vendedor_id' => $vendedor->id,
        'fecha'       => now(),
    ]);

    foreach ($lineas as [$producto, $unidad, $cantidad]) {
        $pedido->items()->create(['producto_id' => $producto->id, 'unidad' => $unidad, 'cantidad' => $cantidad]);
    }

    return app(RecalcularPedido::class)->ejecutar($pedido);
}

test('el buscador trae solo productos activos, con sus precios con ISV', function (): void {
    Producto::factory()->precio(80.0, 100.0)->create(['descripcion' => 'ACEITE VIEJO 12X1L', 'activo' => false]);

    $this->actingAs($this->vendedor);

    /** @var list<array<string, mixed>> $resultados */
    $resultados = Livewire::test(CreatePedido::class)
        ->instance()
        ->callSchemaComponentMethod('form.items', 'buscarProductos', ['buscar' => 'aceite']);

    expect($resultados)->toHaveCount(1)
        ->and($resultados[0]['id'])->toBe($this->producto->id)
        ->and($resultados[0]['caja'])->toBe(12)
        ->and($resultados[0]['precioUnidadIsv'])->toBe(115.0)
        ->and($resultados[0]['precioCajaIsv'])->toBe(1380.0);
});

test('el último pedido del cliente se puede repetir aunque ya esté validado: solo lo que se vende y lo del mismo vendedor', function (): void {
    $viejo = Producto::factory()->precio(10.0, 20.0)->create(['activo' => false]);
    $cliente = Cliente::factory()->create(['bodega_id' => $this->bodega->id]);

    $anterior = pedidoConLineas($this->bodega, $this->vendedor, [[$this->producto, UnidadVenta::Caja, 2], [$viejo, UnidadVenta::Unidad, 5]]);
    // Ya validado: el vendedor no lo ve en su lista, pero sí sirve para repetirlo.
    $anterior->forceFill(['cliente_id' => $cliente->id, 'estado' => EstadoPedido::PorImprimir])->save();

    // Uno anulado más reciente no cuenta, ni uno de otro vendedor.
    $anulado = pedidoConLineas($this->bodega, $this->vendedor, [[$this->producto, UnidadVenta::Unidad, 9]]);
    $anulado->forceFill(['cliente_id' => $cliente->id, 'estado' => EstadoPedido::Anulado])->save();
    $otroVendedor = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $otroVendedor->assignRole(Rol::Vendedor->value);
    $ajeno = pedidoConLineas($this->bodega, $otroVendedor, [[$this->producto, UnidadVenta::Unidad, 7]]);
    $ajeno->update(['cliente_id' => $cliente->id]);

    $this->actingAs($this->vendedor);

    /** @var array{numero: string, lineas: list<array<string, mixed>>, productos: array<int, mixed>, fuera: int} $ultimo */
    $ultimo = Livewire::test(CreatePedido::class)
        ->instance()
        ->callSchemaComponentMethod('form.items', 'ultimoPedido', ['clienteId' => $cliente->id]);

    expect($ultimo['numero'])->toBe($anterior->numero)
        ->and($ultimo['lineas'])->toBe([['producto_id' => $this->producto->id, 'unidad' => 'caja', 'cantidad' => 2]])
        ->and(array_keys($ultimo['productos']))->toBe([$this->producto->id])
        ->and($ultimo['fuera'])->toBe(1);
});

test('el total que pide el teléfono trae las promociones', function (): void {
    $toffee = Producto::factory()->precio(20.0, 30.0)->create();
    $nutrine = Producto::factory()->precio(20.0, 25.30)->create();
    Promocion::factory()->para($toffee, $nutrine, porcentaje: 50)->create();

    // 2 cajas × 12 × L 100 = L 2,400 + 15% = L 2,760
    expect(PedidoForm::resumenDe([['id' => null, 'producto_id' => $this->producto->id, 'unidad' => 'caja', 'cantidad' => 2]]))
        ->toMatchArray(['total' => 2760.0, 'ahorro' => 0.0]);

    // 30 + 25.30 = 55.30 − 12.65 (nutrine a mitad) = 42.65 + 15% ISV = 49.05
    expect(PedidoForm::resumenDe([
        ['id' => null, 'producto_id' => $toffee->id, 'unidad' => 'unidad', 'cantidad' => 1],
        ['id' => null, 'producto_id' => $nutrine->id, 'unidad' => 'unidad', 'cantidad' => 1],
    ]))->toMatchArray(['total' => 49.05, 'ahorro' => 12.65]);
});

test('un producto lleva cajas y unidades en el mismo pedido; si viene repetido se junta', function (): void {
    $this->actingAs($this->encargado);

    Livewire::test(CreatePedido::class)
        ->fillForm([
            'cliente_id'  => Cliente::factory()->create(['bodega_id' => $this->bodega->id])->id,
            'vendedor_id' => $this->vendedor->id,
            'fecha'       => now()->toDateString(),
            'items'       => [
                ['producto_id' => $this->producto->id, 'unidad' => 'caja', 'cantidad' => 2],
                ['producto_id' => $this->producto->id, 'unidad' => 'unidad', 'cantidad' => 3],
                ['producto_id' => $this->producto->id, 'unidad' => 'unidad', 'cantidad' => 2],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $pedido = Pedido::query()->sole();
    $items = $pedido->items()->orderBy('id')->get();

    // 2 cajas × 12 × L 100 = 2,400 y 5 unidades × L 100 = 500 → 2,900 + 15% = 3,335
    expect($items)->toHaveCount(2)
        ->and($items[0]->unidad)->toBe(UnidadVenta::Caja)
        ->and($items[0]->cantidad)->toBe(2)
        ->and($items[1]->unidad)->toBe(UnidadVenta::Unidad)
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
    $otro = Producto::factory()->precio(40.0, 50.0)->create(['unidades_por_caja' => 10]);
    $pedido = pedidoConLineas($this->bodega, $this->vendedor, [
        [$this->producto, UnidadVenta::Unidad, 3],
        [$otro, UnidadVenta::Unidad, 1],
    ]);
    [$primera, $segunda] = $pedido->items()->orderBy('id')->get()->all();

    $this->actingAs($this->vendedor);

    Livewire::test(EditPedido::class, ['record' => $pedido->getRouteKey()])
        ->assertSee('Guardar cambios')
        ->assertSee('2 productos')
        ->assertFormSet(['items' => [
            ['id' => $primera->id, 'producto_id' => $this->producto->id, 'unidad' => 'unidad', 'cantidad' => 3],
            ['id' => $segunda->id, 'producto_id' => $otro->id, 'unidad' => 'unidad', 'cantidad' => 1],
        ]])
        ->fillForm(['items' => [
            ['id' => $primera->id, 'producto_id' => $this->producto->id, 'unidad' => 'caja', 'cantidad' => 2],
            // Línea nueva (sin id): en las pruebas fillForm mezcla con lo que había, por eso el id va en null.
            ['id' => null, 'producto_id' => $otro->id, 'unidad' => 'caja', 'cantidad' => 1],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    $items = $pedido->items()->orderBy('id')->get();

    // 2 cajas × 12 × L 100 = 2,400 y 1 caja × 10 × L 50 = 500 → 2,900 + 15% = 3,335
    expect($items)->toHaveCount(2)
        ->and($items[0]->id)->toBe($primera->id)
        ->and($items[0]->unidad)->toBe(UnidadVenta::Caja)
        ->and($items[0]->cantidad)->toBe(2)
        ->and($items[1]->producto_id)->toBe($otro->id)
        ->and($items[1]->unidad)->toBe(UnidadVenta::Caja)
        ->and(PedidoItem::query()->whereKey($segunda->id)->exists())->toBeFalse()
        ->and($pedido->fresh()?->total)->toBe(3335.0);
});

test('una línea con el id de otro pedido no lo toca: entra como nueva', function (): void {
    $ajeno = pedidoConLineas($this->bodega, $this->vendedor, [[$this->producto, UnidadVenta::Unidad, 4]]);
    $deOtro = $ajeno->items()->sole();
    $pedido = pedidoConLineas($this->bodega, $this->vendedor, [[$this->producto, UnidadVenta::Unidad, 1]]);

    $this->actingAs($this->vendedor);

    Livewire::test(EditPedido::class, ['record' => $pedido->getRouteKey()])
        ->fillForm(['items' => [
            ['id' => $deOtro->id, 'producto_id' => $this->producto->id, 'unidad' => 'unidad', 'cantidad' => 7],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($deOtro->fresh()?->cantidad)->toBe(4)
        ->and($deOtro->fresh()?->pedido_id)->toBe($ajeno->id)
        ->and($pedido->items()->sole()->cantidad)->toBe(7)
        ->and($pedido->items()->sole()->id)->not->toBe($deOtro->id);
});
