<?php

declare(strict_types=1);

use App\Domain\Inventario\RegistrarCompra;
use App\Domain\Pedidos\HojaDeAlisto;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarVarios;
use App\Enums\EstadoPedido;
use App\Enums\Rol;
use App\Filament\Resources\Pedidos\Actions\ValidarVariosAction;
use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Existencia;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Producto;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

/**
 * Validar de un solo muchos pendientes (ValidarVarios): "Validar los N" de
 * un cuadro del día, "Validar todos" de la pestaña Pendiente y "Validar
 * seleccionados". Es como darle Validar a cada uno sin cambiar nada, del
 * más viejo al más nuevo. Antes se saca la hoja de alisto (HojaDeAlisto)
 * y lo que faltó al alistar se anota al validar.
 *
 * Hay 12 UN y 3 CJ12 de jabón.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $this->bodega = Bodega::factory()->create(['codigo' => 'COP']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Rol::Administrador->value);
    $this->encargado = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->encargado->assignRole(Rol::Bodega->value);
    $this->vendedor = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->vendedor->assignRole(Rol::Vendedor->value);

    $this->jabon = Producto::factory()->precio(8.0, 10.0)->create(['margen' => 0.20]);
    $this->un = presentacion($this->jabon);
    $this->cj12 = presentacion($this->jabon, 12);
    app(RegistrarCompra::class)->ejecutar($this->bodega, $this->jabon->proveedor, [
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->un->id, 'cantidad' => 12, 'costo' => 9.2],
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->cj12->id, 'cantidad' => 3, 'costo' => 110.4],
    ], $this->admin);

    $this->cliente = Cliente::factory()->create(['bodega_id' => $this->bodega->id]);
});

/**
 * Un pendiente del vendedor, del día $fecha.
 *
 * @param list<array{0: int, 1: int}> $lineas [presentacion_id, cantidad]
 */
function pendienteParaValidar(object $prueba, array $lineas, string $fecha): Pedido
{
    $pedido = Pedido::query()->create([
        'bodega_id'   => $prueba->bodega->id,
        'cliente_id'  => $prueba->cliente->id,
        'vendedor_id' => $prueba->vendedor->id,
        'fecha'       => $fecha,
    ]);

    foreach ($lineas as [$presentacion, $cantidad]) {
        $pedido->items()->create(['producto_id' => $prueba->jabon->id, 'presentacion_id' => $presentacion, 'cantidad' => $cantidad]);
    }

    return app(RecalcularPedido::class)->ejecutar($pedido);
}

/**
 * Lo que salió de cada línea, en orden.
 *
 * @return list<int|null>
 */
function despachadoDe(Pedido $pedido): array
{
    return array_values($pedido->items()->orderBy('id')->get()->map(fn (PedidoItem $item): ?int => $item->cantidad_despachada)->all());
}

test('los pendientes se ven por día y "Validar los N" valida todos los de ese día de un solo', function (): void {
    $ayer = pendienteParaValidar($this, [[$this->un->id, 1]], today()->subDay()->toDateString());
    $uno = pendienteParaValidar($this, [[$this->un->id, 2]], today()->toDateString());
    $dos = pendienteParaValidar($this, [[$this->cj12->id, 1], [$this->un->id, 3]], today()->toDateString());

    $this->actingAs($this->encargado);

    Livewire::test(ListPedidos::class)
        ->assertSee('Los pedidos por validar, por el día del pedido')
        ->assertSee('por validar')
        ->assertSee('Validar los 2')
        ->assertActionVisible('validarTodos')
        ->mountAction('validarDia', ['dia' => today()->toDateString(), 'bodega' => $this->bodega->id])
        ->assertMountedActionModalSee(['Validar los 2 pedidos', 'Son 2 pedidos por'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('2 pedidos validados: quedan por imprimir');

    expect([$ayer->fresh()?->estado, $uno->fresh()?->estado, $dos->fresh()?->estado])
        ->toBe([EstadoPedido::Pendiente, EstadoPedido::PorImprimir, EstadoPedido::PorImprimir])
        ->and($uno->fresh()?->validado_por)->toBe($this->encargado->id)
        ->and([Existencia::de($this->bodega->id, $this->un->id), Existencia::de($this->bodega->id, $this->cj12->id)])->toBe([7, 2]);

    // El vendedor no valida.
    $this->actingAs($this->vendedor);

    Livewire::test(ListPedidos::class)->assertActionHidden('validarTodos');
});

test('con controlar existencias avisa qué no alcanza: al que no le alcanza le sale lo que hay, y sin nada queda pendiente', function (): void {
    Configuracion::actual()->update(['controlar_existencias' => true]);

    // Hay 3 CJ12: al primero le salen 2, al segundo 1 de 2 y al tercero nada.
    $primero = pendienteParaValidar($this, [[$this->cj12->id, 2]], today()->subDay()->toDateString());
    $segundo = pendienteParaValidar($this, [[$this->cj12->id, 2], [$this->un->id, 4]], today()->toDateString());
    $tercero = pendienteParaValidar($this, [[$this->cj12->id, 1]], today()->toDateString());
    $ids = [$tercero->id, $segundo->id, $primero->id];

    expect(ValidarVarios::queNoAlcanza($ids))->toHaveCount(1)
        ->and(ValidarVarios::queNoAlcanza($ids)[0])->toContain($this->jabon->descripcion)->toContain('faltan 2')
        ->and(ValidarVarios::queNoAlcanza([$primero->id]))->toBe([]);

    $resultado = app(ValidarVarios::class)->ejecutar($ids, $this->encargado);

    expect($resultado->ids())->toBe([$primero->id, $segundo->id])
        ->and($resultado->incompletos)->toBe([$segundo->numero])
        ->and($resultado->pendientes)->toBe([(string) $tercero->numero => 'No hay nada de lo que pidió.'])
        ->and(despachadoDe($segundo))->toBe([1, 4])
        ->and($resultado->total())->toBe(round((float) $primero->fresh()?->total + (float) $segundo->fresh()?->total, 2))
        ->and($tercero->fresh()?->estado)->toBe(EstadoPedido::Pendiente)
        ->and(Existencia::de($this->bodega->id, $this->cj12->id))->toBe(0);
});

test('"Validar todos" con "Dejarlo pendiente": al que no le alcanza no se le toca', function (): void {
    Configuracion::actual()->update(['controlar_existencias' => true]);

    $primero = pendienteParaValidar($this, [[$this->cj12->id, 2]], today()->subDay()->toDateString());
    $segundo = pendienteParaValidar($this, [[$this->cj12->id, 2]], today()->toDateString());

    $this->actingAs($this->encargado);

    Livewire::test(ListPedidos::class)
        ->mountAction('validarTodos')
        ->assertMountedActionModalSee(['Validar los 2 pendientes', 'No alcanza para todos', 'Si a un pedido no le alcanza algo'])
        ->setActionData([ValidarVariosAction::SI_NO_ALCANZA => ValidarVariosAction::DEJARLO])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('1 pedido validado: queda por imprimir');

    expect([$primero->fresh()?->estado, $segundo->fresh()?->estado])->toBe([EstadoPedido::PorImprimir, EstadoPedido::Pendiente])
        ->and(despachadoDe($segundo))->toBe([null])
        ->and(Existencia::de($this->bodega->id, $this->cj12->id))->toBe(1);
});

test('en "Uno por uno" se marcan los pendientes y se validan los seleccionados', function (): void {
    $uno = pendienteParaValidar($this, [[$this->un->id, 1]], today()->toDateString());
    $dos = pendienteParaValidar($this, [[$this->un->id, 2]], today()->toDateString());
    $otro = pendienteParaValidar($this, [[$this->un->id, 3]], today()->toDateString());

    $this->actingAs($this->encargado);

    Livewire::test(ListPedidos::class)
        ->set('vista', 'lista')
        ->assertCanSeeTableRecords([$uno, $dos, $otro])
        ->callTableBulkAction('validarSeleccionados', [$uno, $dos])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('2 pedidos validados: quedan por imprimir');

    expect([$uno->fresh()?->estado, $dos->fresh()?->estado, $otro->fresh()?->estado])
        ->toBe([EstadoPedido::PorImprimir, EstadoPedido::PorImprimir, EstadoPedido::Pendiente])
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe(9);
});

test('la hoja de alisto dice lo que se saca de cada producto y a qué pedido va; sale del día y de los marcados', function (): void {
    $uno = pendienteParaValidar($this, [[$this->cj12->id, 1], [$this->un->id, 3]], today()->toDateString());
    $dos = pendienteParaValidar($this, [[$this->cj12->id, 2]], today()->toDateString());
    $ids = [$dos->id, $uno->id];

    $hoja = HojaDeAlisto::datos($ids);
    $jabon = $hoja[0]['productos'][0];

    expect($hoja)->toHaveCount(1)
        ->and(array_column($hoja[0]['pedidos'], 'numero'))->toBe([$uno->numero, $dos->numero])
        ->and(array_map(fn (array $sale): array => [$sale['sigla'], $sale['cantidad']], $jabon['sale']))->toBe([['CJ12', 3], ['UN', 3]])
        ->and([$jabon['hay'], $jabon['falta']])->toBe(['3 CJ12 · 12 UN', null])
        ->and($jabon['para'])->toHaveCount(2)
        ->and($jabon['para'][0])->toStartWith($uno->numero.' ')->toEndWith(': 1 CJ12 + 3 UN')
        ->and(HojaDeAlisto::paraAnotar($hoja))->toBe([
            "{$this->bodega->id}:{$this->cj12->id}" => "{$this->jabon->descripcion} · CJ12 (salen 3)",
            "{$this->bodega->id}:{$this->un->id}"   => "{$this->jabon->descripcion} · UN (salen 3)",
        ]);

    $this->actingAs($this->encargado);

    $this->get(route('pedidos.alisto', ['ids' => implode(',', $ids)]))
        ->assertOk()
        ->assertSee('Hoja de alisto')
        ->assertSee('2 pedidos del '.today()->format('d/m/Y'))
        ->assertSee($this->jabon->descripcion)
        ->assertSee((string) $dos->numero)
        ->assertSee('Apartado')
        ->assertSee('Faltó');

    Livewire::test(ListPedidos::class)
        ->assertSee('Hoja de alisto')
        ->mountAction('alistoDia', ['dia' => today()->toDateString(), 'bodega' => $this->bodega->id])
        ->assertMountedActionModalSee('Hoja de alisto de los 2 pedidos');

    Livewire::test(ListPedidos::class)
        ->set('vista', 'lista')
        ->assertTableBulkActionVisible('alistoSeleccionados')
        ->mountTableBulkAction('alistoSeleccionados', [$uno, $dos])
        ->assertMountedActionModalSee('Hoja de alisto de los 2 pedidos marcados');

    // Nada se movió: la hoja solo muestra.
    expect([$uno->fresh()?->estado, Existencia::de($this->bodega->id, $this->cj12->id)])->toBe([EstadoPedido::Pendiente, 3]);

    // El vendedor no la saca.
    $this->actingAs($this->vendedor);

    $this->get(route('pedidos.alisto', ['ids' => implode(',', $ids)]))->assertForbidden();
});

test('al validar se anota lo que faltó al alistar: a los primeros les sale completo y a los últimos menos', function (): void {
    // Piden 1 + 2 + 1 = 4 CJ12; al alistar faltaron 2 (se encontraron 2).
    $primero = pendienteParaValidar($this, [[$this->cj12->id, 1], [$this->un->id, 2]], today()->subDay()->toDateString());
    $segundo = pendienteParaValidar($this, [[$this->cj12->id, 2]], today()->toDateString());
    $tercero = pendienteParaValidar($this, [[$this->cj12->id, 1]], today()->toDateString());

    $this->actingAs($this->encargado);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(ListPedidos::class)
        ->mountAction('validarTodos')
        ->assertMountedActionModalSee(['¿Faltó algo al alistar?', 'Agregar lo que faltó'])
        ->setActionData([ValidarVariosAction::FALTARON => [['clave' => "{$this->bodega->id}:{$this->cj12->id}", 'cantidad' => 2]]])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('2 pedidos validados: quedan por imprimir');

    $undoRepeaterFake();

    expect([$primero->fresh()?->estado, $segundo->fresh()?->estado, $tercero->fresh()?->estado])
        ->toBe([EstadoPedido::PorImprimir, EstadoPedido::PorImprimir, EstadoPedido::Pendiente])
        ->and(despachadoDe($primero))->toBe([1, 2])
        ->and(despachadoDe($segundo))->toBe([1])
        ->and(despachadoDe($tercero))->toBe([null])
        ->and(Existencia::de($this->bodega->id, $this->cj12->id))->toBe(1);

    // Lo que llega del modal: lo que no se entiende se salta.
    expect(ValidarVariosAction::faltaron([ValidarVariosAction::FALTARON => [['clave' => 'x', 'cantidad' => 1], ['clave' => "{$this->bodega->id}:{$this->un->id}", 'cantidad' => '3']]]))
        ->toBe([$this->bodega->id => [$this->un->id => 3]]);
});
