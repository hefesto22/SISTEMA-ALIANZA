<?php

declare(strict_types=1);

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Pedidos\CorregirPedido;
use App\Domain\Pedidos\MarcarImpreso;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarDespacho;
use App\Domain\Precios\ActualizarPrecioProducto;
use App\Enums\EstadoPedido;
use App\Enums\MotivoCambioPrecio;
use App\Enums\Rol;
use App\Enums\UnidadVenta;
use App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction;
use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Filament\Resources\Pedidos\Pages\ViewPedido;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Database\Seeders\RolesSistemaSeeder;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

/**
 * Después de validar: Por imprimir → Imprimir → Impreso. Si se corrige
 * después de impreso, vuelve a Por imprimir y la hoja sale como REIMPRESIÓN.
 */
beforeEach(function (): void {
    $this->seed(RolesSistemaSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->bodega = Bodega::factory()->create(['codigo' => 'COP']);

    $this->vendedor = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->vendedor->assignRole(Rol::Vendedor->value);

    $this->encargado = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->encargado->assignRole(Rol::Bodega->value);

    // Gravado: L 100 la unidad. Exento: L 50 la unidad, caja de 10 = L 500.
    $this->gravado = Producto::factory()->precio(80.0, 100.0)->create(['unidades_por_caja' => 12]);
    $this->exento = Producto::factory()->exento()->precio(40.0, 50.0)->create(['unidades_por_caja' => 10]);
});

/**
 * Pedido de 2 unidades del gravado + 1 caja del exento (L 730 con ISV).
 */
function pedidoParaImprimir(Bodega $bodega, User $vendedor, Producto $gravado, Producto $exento): Pedido
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

test('al validar queda por imprimir; al imprimir pasa a impreso y la copia sale como reimpresión', function (): void {
    $pedido = pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento);
    $pedido = app(ValidarDespacho::class)->ejecutar($pedido, [], $this->encargado);

    expect($pedido->estado)->toBe(EstadoPedido::PorImprimir)
        ->and($pedido->impresiones)->toBe(0)
        ->and($pedido->esReimpresion())->toBeFalse();

    $this->actingAs($this->encargado);

    // El botón manda a imprimir la hoja ahí mismo (sin otra pestaña).
    Livewire::test(ViewPedido::class, ['record' => $pedido->getRouteKey()])
        ->assertActionVisible('imprimir')
        ->assertActionExists('imprimir', fn (Action $action): bool => $action->getEvent() === ImprimirPedidoAction::EVENTO
            && $action->getEventData() === ['url' => route('pedidos.imprimir', $pedido)]);

    $this->get(route('pedidos.imprimir', $pedido))
        ->assertOk()
        ->assertSee((string) $pedido->numero)
        ->assertDontSee('REIMPRESIÓN');

    expect($pedido->fresh()?->estado)->toBe(EstadoPedido::Impreso)
        ->and($pedido->fresh()?->impresiones)->toBe(1)
        ->and($pedido->fresh()?->impreso_por)->toBe($this->encargado->id)
        ->and($pedido->fresh()?->impreso_at)->not->toBeNull();

    $this->get(route('pedidos.imprimir', $pedido))
        ->assertOk()
        ->assertSee('REIMPRESIÓN');

    expect($pedido->fresh()?->impresiones)->toBe(2);
});

test('el vendedor ve la hoja pero no cuenta como impresa; la de un pendiente es vista previa', function (): void {
    $pendiente = pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento);
    $validado = app(ValidarDespacho::class)->ejecutar(
        pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento),
        [],
        $this->encargado,
    );

    $this->actingAs($this->vendedor)
        ->get(route('pedidos.imprimir', $validado))
        ->assertOk()
        ->assertSee((string) $validado->numero);

    expect($validado->fresh()?->estado)->toBe(EstadoPedido::PorImprimir)
        ->and($validado->fresh()?->impresiones)->toBe(0);

    $this->actingAs($this->encargado)
        ->get(route('pedidos.imprimir', $pendiente))
        ->assertOk()
        ->assertSee('vista previa');

    expect($pendiente->fresh()?->estado)->toBe(EstadoPedido::Pendiente)
        ->and($pendiente->fresh()?->impresiones)->toBe(0);
});

test('imprimir todos: solo entran los validados de su bodega, uno por página', function (): void {
    $validar = fn (Pedido $pedido): Pedido => app(ValidarDespacho::class)->ejecutar($pedido, [], $this->encargado);

    $primero = $validar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento));
    $segundo = $validar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento));
    $pendiente = pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento);

    $otraBodega = Bodega::factory()->create(['codigo' => 'OCO']);
    $otroVendedor = User::factory()->create(['bodega_id' => $otraBodega->id]);
    $otroVendedor->assignRole(Rol::Vendedor->value);
    $deOtra = pedidoParaImprimir($otraBodega, $otroVendedor, $this->gravado, $this->exento);
    $deOtra->forceFill(['estado' => EstadoPedido::PorImprimir, 'validado_at' => now()])->save();

    $this->actingAs($this->encargado);

    $ids = implode(',', [$primero->id, $segundo->id, $pendiente->id, $deOtra->id]);

    $this->get(route('pedidos.imprimir-lote', ['ids' => $ids]))
        ->assertOk()
        ->assertSee((string) $primero->numero)
        ->assertSee((string) $segundo->numero)
        ->assertDontSee((string) $pendiente->numero)
        ->assertDontSee((string) $deOtra->numero);

    expect($primero->fresh()?->estado)->toBe(EstadoPedido::Impreso)
        ->and($segundo->fresh()?->estado)->toBe(EstadoPedido::Impreso)
        ->and($pendiente->fresh()?->estado)->toBe(EstadoPedido::Pendiente)
        ->and($deOtra->fresh()?->estado)->toBe(EstadoPedido::PorImprimir);

    $this->get(route('pedidos.imprimir-lote', ['ids' => (string) $pendiente->id]))->assertNotFound();
});

test('el botón Imprimir todos sale en la pestaña Por imprimir con los que se ven', function (): void {
    $primero = app(ValidarDespacho::class)->ejecutar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento), [], $this->encargado);
    $segundo = app(ValidarDespacho::class)->ejecutar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento), [], $this->encargado);

    $this->actingAs($this->encargado);

    Livewire::test(ListPedidos::class)
        ->assertActionHidden('imprimirTodos')
        ->set('activeTab', EstadoPedido::PorImprimir->value)
        ->assertCanSeeTableRecords([$primero, $segundo])
        ->assertActionVisible('imprimirTodos')
        ->assertActionHasLabel('imprimirTodos', 'Imprimir todos (2)')
        ->assertActionExists('imprimirTodos', fn (Action $action): bool => $action->getEvent() === ImprimirPedidoAction::EVENTO
            && $action->getEventData() === ['url' => route('pedidos.imprimir-lote', ['ids' => "{$primero->id},{$segundo->id}"])]);

    // El vendedor no imprime: no le sale.
    $this->actingAs($this->vendedor);

    Livewire::test(ListPedidos::class)
        ->set('activeTab', EstadoPedido::PorImprimir->value)
        ->assertActionHidden('imprimirTodos');
});

test('se marcan varias tarjetas y se imprimen de un solo; los pendientes no se pueden marcar', function (): void {
    $validar = fn (): Pedido => app(ValidarDespacho::class)->ejecutar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento), [], $this->encargado);

    $impreso = app(MarcarImpreso::class)->ejecutar($validar(), $this->encargado);
    $porImprimir = $validar();
    $pendiente = pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento);

    $this->actingAs($this->encargado);

    Livewire::test(ListPedidos::class)
        ->assertTableBulkActionHidden('imprimirSeleccionados')
        ->set('activeTab', 'todos')
        ->assertTableBulkActionVisible('imprimirSeleccionados')
        ->callTableBulkAction('imprimirSeleccionados', [$impreso, $porImprimir, $pendiente])
        ->assertDispatched(ImprimirPedidoAction::EVENTO, url: ImprimirPedidoAction::urlDeVarios([$impreso->id, $porImprimir->id]));

    // Al abrir la hoja quedan impresos; el que ya estaba, como reimpresión.
    $this->get(ImprimirPedidoAction::urlDeVarios([$impreso->id, $porImprimir->id]))
        ->assertOk()
        ->assertSee('REIMPRESIÓN');

    expect($impreso->fresh()?->impresiones)->toBe(2)
        ->and($porImprimir->fresh()?->estado)->toBe(EstadoPedido::Impreso)
        ->and($pendiente->fresh()?->estado)->toBe(EstadoPedido::Pendiente);

    // El vendedor no imprime: no puede marcar.
    $this->actingAs($this->vendedor);

    Livewire::test(ListPedidos::class)
        ->set('activeTab', 'todos')
        ->assertTableBulkActionHidden('imprimirSeleccionados');
});

test('corregir un pedido impreso: vuelve a por imprimir como reimpresión, con los precios de cuando se validó', function (): void {
    $pedido = app(ValidarDespacho::class)->ejecutar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento), [], $this->encargado);

    $this->actingAs($this->encargado)->get(route('pedidos.imprimir', $pedido))->assertOk();

    // Sube el precio después de validado: la corrección no lo cambia.
    app(ActualizarPrecioProducto::class)->ejecutar($this->gravado, 80.0, MotivoCambioPrecio::Manual, precioUnidad: 110.0);

    $undoRepeaterFake = Repeater::fake();

    // Avisan que del gravado solo salió 1.
    Livewire::test(ViewPedido::class, ['record' => $pedido->getRouteKey()])
        ->assertActionVisible('corregir')
        ->callAction('corregir', data: ['items' => [['sale' => 1], ['sale' => 1]]])
        ->assertHasNoActionErrors();

    $undoRepeaterFake();

    $pedido = $pedido->fresh();
    $lineas = $pedido?->items()->orderBy('id')->get();

    // 1 × 100 (+15 ISV) + 1 caja × 500 (exento)
    expect($pedido?->estado)->toBe(EstadoPedido::PorImprimir)
        ->and($pedido?->esReimpresion())->toBeTrue()
        ->and($pedido?->corregido_por)->toBe($this->encargado->id)
        ->and($lineas?->first()?->cantidad_despachada)->toBe(1)
        ->and($lineas?->last()?->cantidad_despachada)->toBe(1)
        ->and($pedido?->subtotal)->toBe(600.0)
        ->and($pedido?->isv)->toBe(15.0)
        ->and($pedido?->total)->toBe(615.0);

    Livewire::test(ListPedidos::class)
        ->set('activeTab', EstadoPedido::PorImprimir->value)
        ->assertSee('Por reimprimir');

    $this->get(route('pedidos.imprimir', $pedido))
        ->assertOk()
        ->assertSee('REIMPRESIÓN');

    expect($pedido?->fresh()?->estado)->toBe(EstadoPedido::Impreso)
        ->and($pedido?->fresh()?->impresiones)->toBe(2);
});

test('solo se corrige lo validado, y solo bodega', function (): void {
    $pendiente = pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento);
    $validado = app(ValidarDespacho::class)->ejecutar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento), [], $this->encargado);

    $this->actingAs($this->encargado);

    Livewire::test(ViewPedido::class, ['record' => $pendiente->getRouteKey()])
        ->assertActionHidden('corregir')
        ->assertActionVisible('validarDespacho');

    $this->actingAs($this->vendedor);

    Livewire::test(ViewPedido::class, ['record' => $validado->getRouteKey()])
        ->assertActionHidden('corregir')
        ->assertActionHidden('imprimir');

    expect(fn () => app(CorregirPedido::class)->ejecutar($pendiente, [], $this->encargado))
        ->toThrow(ReglaNegocioException::class);
});

test('al corregir no se puede dejar sin nada: para eso se anula', function (): void {
    $pedido = app(ValidarDespacho::class)->ejecutar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento), [], $this->encargado);
    $lineas = $pedido->items()->pluck('id')->all();

    expect(fn () => app(CorregirPedido::class)->ejecutar($pedido, array_fill_keys($lineas, 0), $this->encargado))
        ->toThrow(ReglaNegocioException::class);

    expect($pedido->fresh()?->estado)->toBe(EstadoPedido::PorImprimir)
        ->and($pedido->fresh()?->total)->toBe(730.0);
});
