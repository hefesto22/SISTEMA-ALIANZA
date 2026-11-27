<?php

declare(strict_types=1);

use App\Domain\Cargas\ArmarCarga;
use App\Domain\Cargas\CuentaCarga;
use App\Domain\Cargas\HojaDeCarga;
use App\Domain\Cargas\LiquidarCarga;
use App\Domain\Devoluciones\OrdenesDeCambio;
use App\Domain\Devoluciones\RegistrarDevolucion;
use App\Domain\Devoluciones\Reposiciones;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Inventario\RegistrarCompra;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarDespacho;
use App\Enums\EntregaFactura;
use App\Enums\EstadoFactura;
use App\Enums\EstadoPedido;
use App\Enums\EstadoReposicion;
use App\Enums\Rol;
use App\Enums\TipoDevolucion;
use App\Filament\Resources\Cargas\CargaResource;
use App\Filament\Resources\Cargas\Pages\CreateCarga;
use App\Filament\Resources\Cargas\Pages\Liquidacion;
use App\Filament\Resources\Cargas\Pages\ListCargas;
use App\Filament\Resources\Cargas\Pages\ViewCarga;
use App\Filament\Resources\Pedidos\Actions\DevolucionAction;
use App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction;
use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Filament\Resources\Pedidos\Pages\ViewPedido;
use App\Models\Bodega;
use App\Models\Carga;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Devolucion;
use App\Models\Existencia;
use App\Models\Factura;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Reposicion;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

/**
 * Cambios por entregar: lo que se le debe a un cliente por un cambio por
 * cambio queda Por entregar (a su nombre o, si ya se le dio con producto
 * del pedido de otro cliente, a nombre de ese otro) como orden de cambio
 * (CAM-000001): se imprime en Pedidos → Por imprimir, ya impresa se escoge
 * al armar la carga y sale de la bodega al subirlo al camión; al liquidar,
 * entregado o no se entregó (regresa a la bodega y espera otra carga).
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
    $this->sale = User::factory()->create(['bodega_id' => $this->bodega->id, 'name' => 'JOSE RAMIREZ']);
    $this->sale->assignRole(Rol::Vendedor->value);

    $this->jabon = Producto::factory()->precio(8.0, 10.0)->create(['margen' => 0.20, 'descripcion' => 'JABON BEST 250G']);
    $this->un = presentacion($this->jabon);
    $this->cj12 = presentacion($this->jabon, 12);
    app(RegistrarCompra::class)->ejecutar($this->bodega, $this->jabon->proveedor, [
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->un->id, 'cantidad' => 12, 'costo' => 9.2],
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->cj12->id, 'cantidad' => 3, 'costo' => 110.4],
    ], $this->admin);

    facturacionLista();
    Configuracion::actual()->update(['notas_credito' => false]);
});

/**
 * Un pedido de $cantidad UN de jabón de un cliente nuevo, validado e impreso.
 */
function pedidoDeCambios(object $prueba, int $cantidad, string $cliente): Pedido
{
    $pedido = Pedido::query()->create([
        'bodega_id'   => $prueba->bodega->id,
        'cliente_id'  => Cliente::factory()->create(['bodega_id' => $prueba->bodega->id, 'nombre' => $cliente])->id,
        'vendedor_id' => $prueba->vendedor->id,
        'fecha'       => now(),
    ]);
    $pedido->items()->create(['producto_id' => $prueba->jabon->id, 'presentacion_id' => $prueba->un->id, 'cantidad' => $cantidad]);
    app(ValidarDespacho::class)->ejecutar(app(RecalcularPedido::class)->ejecutar($pedido), [], $prueba->encargado);
    imprimir($pedido, $prueba->encargado);

    return $pedido->fresh();
}

/**
 * Cambio de $danadas UN dañadas del pedido, con su orden de cambio ya
 * impresa (lista para subirse a la carga).
 */
function cambioDe(object $prueba, Pedido $pedido, int $danadas, ?Pedido $seLeDebeA = null): Reposicion
{
    $devolucion = app(RegistrarDevolucion::class)->ejecutar($pedido, TipoDevolucion::Cambio, [$pedido->items()->sole()->id => ['danadas' => $danadas]], $prueba->encargado, seLeDebeA: $seLeDebeA);
    $cambio = $devolucion->reposicion()->firstOrFail();
    app(OrdenesDeCambio::class)->imprimir([$cambio->id], $prueba->encargado);

    return $cambio->fresh() ?? $cambio;
}

/**
 * @param list<Pedido> $pedidos
 * @param list<Reposicion> $cambios
 */
function cargaConCambios(object $prueba, array $pedidos, array $cambios, ?Carga $carga = null): Carga
{
    return app(ArmarCarga::class)->ejecutar($carga, [
        'bodega_id'     => $prueba->bodega->id,
        'fecha'         => today()->toDateString(),
        'repartidor_id' => $prueba->sale->id,
        'facturas'      => array_map(fn (Pedido $pedido): int => Factura::query()->where('pedido_id', $pedido->id)->where('estado', EstadoFactura::Vigente)->sole()->id, $pedidos),
        'cambios'       => array_map(fn (Reposicion $cambio): int => $cambio->id, $cambios),
    ], $prueba->encargado);
}

test('el cambio queda por entregar a nombre del cliente; sale de la bodega al subirlo al camión y regresa si se quita', function (): void {
    $pedido = pedidoDeCambios($this, 10, 'PULPERIA LA BENDICION');

    expect(Existencia::de($this->bodega->id, $this->un->id))->toBe(2);

    $devolucion = app(RegistrarDevolucion::class)->ejecutar($pedido, TipoDevolucion::Cambio, [$pedido->items()->sole()->id => ['buenas' => 1, 'danadas' => 2]], $this->encargado);
    $uno = $devolucion->reposicion()->firstOrFail();
    $dos = cambioDe($this, $pedido, 2);

    // Solo entra la buena: lo del cambio todavía no sale.
    expect(Existencia::de($this->bodega->id, $this->un->id))->toBe(3)
        ->and([$uno->estado, $uno->cliente_id, $uno->pedido_id, $uno->nota])->toBe([EstadoReposicion::PorEntregar, $pedido->cliente_id, $pedido->id, null])
        ->and($uno->texto())->toBe('3 UN JABON BEST 250G');

    // Su orden de cambio todavía no se imprime: no se puede subir a la carga.
    expect([$uno->numero, $uno->estaImpresa()])->toBe([sprintf('CAM-%06d', $uno->id), false])
        ->and(OrdenesDeCambio::de($this->encargado, OrdenesDeCambio::POR_IMPRIMIR)->modelKeys())->toBe([$uno->id])
        ->and(Reposiciones::paraCarga($this->bodega->id)->modelKeys())->toBe([$dos->id])
        ->and(fn () => cargaConCambios($this, [], [$uno]))->toThrow(ReglaNegocioException::class, "La orden de cambio {$uno->numero}");

    expect(app(OrdenesDeCambio::class)->imprimir([$uno->id], $this->encargado))->toBe([$uno->id])
        ->and(OrdenesDeCambio::de($this->encargado, OrdenesDeCambio::POR_CARGAR)->modelKeys())->toBe([$uno->id, $dos->id])
        ->and(Reposiciones::paraCarga($this->bodega->id)->modelKeys())->toBe([$uno->id, $dos->id]);

    // Una carga solo con los cambios: salen 3 sueltas y, para las otras 2, se abre una CJ12.
    $carga = cargaConCambios($this, [], [$uno, $dos]);

    expect($carga->cambios()->pluck('estado')->all())->toBe([EntregaFactura::EnRuta, EntregaFactura::EnRuta])
        ->and([$uno->fresh()?->estado, $dos->fresh()?->estado])->toBe([EstadoReposicion::EnRuta, EstadoReposicion::EnRuta])
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe(10)
        ->and(Existencia::de($this->bodega->id, $this->cj12->id))->toBe(2)
        ->and(Reposiciones::paraCarga($this->bodega->id))->toBeEmpty()
        ->and(Reposiciones::paraCarga($this->bodega->id, $carga->id)->modelKeys())->toBe([$uno->id, $dos->id])
        ->and(fn () => cargaConCambios($this, [], [$uno]))->toThrow(ReglaNegocioException::class, 'ya va en la carga '.$carga->numero());

    // Se quita uno al cambiar la carga: regresa a la bodega y vuelve a esperar.
    cargaConCambios($this, [], [$uno], $carga);

    expect($dos->fresh()?->estado)->toBe(EstadoReposicion::PorEntregar)
        ->and($carga->cambios()->count())->toBe(1)
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe(12);

    // La carga se borra: el que llevaba también regresa.
    app(ArmarCarga::class)->borrar($carga, $this->encargado);

    expect(Carga::query()->count())->toBe(0)
        ->and($uno->fresh()?->estado)->toBe(EstadoReposicion::PorEntregar)
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe(15)
        ->and(fn () => cargaConCambios($this, [], []))->toThrow(ReglaNegocioException::class, 'Escoja las facturas');
});

test('ya se le dio con producto del pedido de otro cliente: lo que queda por entregar es para ese otro', function (): void {
    $bendicion = pedidoDeCambios($this, 4, 'PULPERIA LA BENDICION');
    $roca = pedidoDeCambios($this, 3, 'PULPERIA LA ROCA');

    expect(RegistrarDevolucion::paraDeberle($bendicion)->pluck('id')->all())->toBe([$roca->id]);

    $cambio = cambioDe($this, $bendicion, 1, seLeDebeA: $roca);

    expect([$cambio->cliente_id, $cambio->pedido_id, $cambio->estado])->toBe([$roca->cliente_id, $roca->id, EstadoReposicion::PorEntregar])
        ->and($cambio->nota)->toBe("SE LE QUEDÓ DEBIENDO: LO DE SU PEDIDO SE LE DIO A PULPERIA LA BENDICION ({$cambio->devolucion->numero})")
        ->and($cambio->etiqueta())->toContain('PULPERIA LA ROCA: 1 UN JABON BEST 250G');

    // No sirve el mismo pedido ni uno sin entregar; en un crédito no aplica.
    $pendiente = Pedido::query()->create(['bodega_id' => $this->bodega->id, 'cliente_id' => $roca->cliente_id, 'vendedor_id' => $this->vendedor->id, 'fecha' => now()]);
    $credito = app(RegistrarDevolucion::class)->ejecutar($bendicion, TipoDevolucion::Credito, [$bendicion->items()->sole()->id => ['buenas' => 1]], $this->encargado, seLeDebeA: $roca);

    expect(fn () => cambioDe($this, $bendicion, 1, seLeDebeA: $bendicion))->toThrow(ReglaNegocioException::class, 'Escoja el pedido del otro cliente')
        ->and(fn () => cambioDe($this, $bendicion, 1, seLeDebeA: $pendiente))->toThrow(ReglaNegocioException::class, 'ya entregado (impreso)')
        ->and($credito->reposicion()->exists())->toBeFalse()
        ->and(Reposicion::query()->count())->toBe(1);
});

test('liquidar: el entregado queda entregado y el que no se entregó regresa a la bodega y sale en otra carga', function (): void {
    $bendicion = pedidoDeCambios($this, 4, 'PULPERIA LA BENDICION');
    $roca = pedidoDeCambios($this, 3, 'PULPERIA LA ROCA');
    $nuevo = pedidoDeCambios($this, 2, 'PULPERIA EL SOL');
    $uno = cambioDe($this, $bendicion, 1);
    $dos = cambioDe($this, $roca, 2);

    $carga = cargaConCambios($this, [$nuevo], [$uno, $dos]);
    $hoja = HojaDeCarga::datos($carga->fresh());

    // La hoja de carga suma lo de los cambios y dice a quién se le da cada uno.
    expect($hoja['productos'])->toBe([[
        'codigo'      => $this->jabon->codigo,
        'descripcion' => 'JABON BEST 250G',
        'cajas'       => [],
        'unidades'    => 5,
        'suelta'      => 'UN',
        'regalo'      => null,
        'cambio'      => '3 UN',
    ]])
        ->and(array_map(fn (array $c): array => [$c['cliente'], $c['lleva']], $hoja['cambios']))->toBe([
            ['PULPERIA LA BENDICION', ['1 UN JABON BEST 250G']],
            ['PULPERIA LA ROCA', ['2 UN JABON BEST 250G']],
        ]);

    $this->actingAs($this->encargado);
    $this->get(route('cargas.imprimir', ['carga' => $carga, 'hoja' => 'carga']))
        ->assertOk()
        ->assertSee('Cambios que lleva')
        ->assertSee('Incluye de cambios: 3 UN')
        ->assertSee('Recibió (firma)');

    $antes = Existencia::de($this->bodega->id, $this->un->id);

    app(LiquidarCarga::class)->ejecutar($carga, [
        'entregas'       => [],
        'cambios'        => [$dos->id => CuentaCarga::OTRA_CARGA],
        'efectivo'       => $carga->total_carga,
        'cheques'        => [],
        'transferencias' => [],
    ], $this->encargado);

    expect($uno->fresh()?->estado)->toBe(EstadoReposicion::Entregada)
        ->and($uno->fresh()?->entregada_at)->not->toBeNull()
        ->and($dos->fresh()?->estado)->toBe(EstadoReposicion::PorEntregar)
        ->and($carga->cambios()->pluck('estado')->all())->toBe([EntregaFactura::Entregada, EntregaFactura::OtraCarga])
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe($antes + 2)
        ->and(Reposiciones::paraCarga($this->bodega->id)->modelKeys())->toBe([$dos->id]);

    $this->get(route('cargas.imprimir', ['carga' => $carga, 'hoja' => 'liquidacion']))
        ->assertOk()
        ->assertSee('Cambios que llevaba')
        ->assertSee('No se entregó: regresó a la bodega');

    // Sale en otra carga.
    expect(cargaConCambios($this, [], [$dos])->cambios()->count())->toBe(1)
        ->and($dos->fresh()?->estado)->toBe(EstadoReposicion::EnRuta);
});

test('bodega registra el cambio para otro cliente, sale marcado al cargar el camión, va en la hoja y se liquida en pantalla', function (): void {
    $bendicion = pedidoDeCambios($this, 4, 'PULPERIA LA BENDICION');
    $roca = pedidoDeCambios($this, 3, 'PULPERIA LA ROCA');
    $this->actingAs($this->encargado);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(ViewPedido::class, ['record' => $bendicion->getRouteKey()])
        ->callAction('devolucion', data: [
            'tipo'         => TipoDevolucion::Cambio->value,
            'entrega'      => DevolucionAction::DE_OTRO,
            'se_le_debe_a' => $roca->id,
            'lineas'       => [['id' => $bendicion->items()->sole()->id, 'buenas' => 0, 'danadas' => 1]],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $undoRepeaterFake();
    $cambio = Reposicion::query()->sole();

    expect($cambio->cliente_id)->toBe($roca->cliente_id);

    // La orden de cambio sale en Pedidos → Por imprimir; al imprimirla pasa a Impreso (por cargar).
    Livewire::test(ListPedidos::class)
        ->set('activeTab', EstadoPedido::PorImprimir->value)
        ->assertSee('Órdenes de cambio por imprimir')
        ->assertSee((string) $cambio->numero)
        ->callAction('imprimirCambios', arguments: ['ids' => [$cambio->id]])
        ->assertDispatched(ImprimirPedidoAction::EVENTO)
        ->assertNotified('Orden de cambio enviada a imprimir');

    expect($cambio->fresh()?->estaImpresa())->toBeTrue();

    $this->get(route('cambios.imprimir', ['ids' => $cambio->id]))
        ->assertOk()
        ->assertSee('ORDEN DE CAMBIO')
        ->assertSee((string) $cambio->numero)
        ->assertSee('PULPERIA LA ROCA')
        ->assertSee('Recibió conforme');

    Livewire::test(ListPedidos::class)
        ->set('activeTab', EstadoPedido::Impreso->value)
        ->assertSee('Órdenes de cambio por cargar')
        ->assertSee((string) $cambio->numero);

    // Nueva carga desde Impresos ("Escoger una por una"): el cambio de esa ruta viene marcado.
    Livewire::withQueryParams(['dias' => today()->toDateString(), 'bodega' => $this->bodega->id])
        ->test(CreateCarga::class)
        ->assertOk()
        ->assertSee('Cambios por entregar')
        ->assertFormSet(['cambios' => [(string) $cambio->id]]);

    // "Cargar camión" de Impresos: también viene marcado.
    Livewire::test(ListPedidos::class)
        ->set('activeTab', EstadoPedido::Impreso->value)
        ->mountAction('cargarDias', ['dias' => [today()->toDateString()], 'bodega' => $this->bodega->id])
        ->assertMountedActionModalSee(['Cambios por entregar que lleva', 'PULPERIA LA ROCA']);

    Livewire::test(ListPedidos::class)
        ->set('activeTab', EstadoPedido::Impreso->value)
        ->callAction('cargarDias', data: ['repartidor_id' => $this->sale->id], arguments: ['dias' => [today()->toDateString()], 'bodega' => $this->bodega->id])
        ->assertHasNoActionErrors()
        ->assertRedirect();

    $carga = Carga::query()->latest('id')->firstOrFail();

    expect([$carga->renglones()->count(), $carga->cambios()->sole()->reposicion_id])->toBe([2, $cambio->id]);

    Livewire::test(ViewCarga::class, ['record' => $carga->getRouteKey()])
        ->assertOk()
        ->assertSee('Cambios que lleva')
        ->assertSee('SE LE QUEDÓ DEBIENDO');

    Livewire::test(Liquidacion::class, ['record' => $carga->getRouteKey()])
        ->assertOk()
        ->assertSee('Cambios que llevaba')
        ->assertSee('PULPERIA LA ROCA')
        ->set('data.efectivo', $carga->total_carga)
        ->call('liquidar')
        ->assertHasNoFormErrors()
        ->assertRedirect(CargaResource::getUrl('view', ['record' => $carga]));

    expect($cambio->fresh()?->estado)->toBe(EstadoReposicion::Entregada);
});

test('la devolución se registra desde la carga, por factura: en Ver carga y en Liquidar; la de crédito se descuenta del cuadre', function (): void {
    $bendicion = pedidoDeCambios($this, 4, 'PULPERIA LA BENDICION');
    $roca = pedidoDeCambios($this, 3, 'PULPERIA LA ROCA');
    $carga = cargaConCambios($this, [$bendicion, $roca], []);
    $facturaDe = fn (Pedido $pedido): Factura => Factura::query()->where('pedido_id', $pedido->id)->where('estado', EstadoFactura::Vigente)->sole();

    $this->actingAs($this->encargado);
    $undoRepeaterFake = Repeater::fake();

    // Ver carga: cada factura trae Devolución; solo de las facturas que lleva.
    Livewire::test(ViewCarga::class, ['record' => $carga->getRouteKey()])
        ->assertSee('Devolución')
        ->assertActionVisible('devolucion', ['factura' => $facturaDe($bendicion)->id])
        ->assertActionHidden('devolucion', ['factura' => 999999])
        ->callAction('devolucion', data: [
            'tipo'    => TipoDevolucion::Cambio->value,
            'entrega' => DevolucionAction::PROXIMA_CARGA,
            'lineas'  => [['id' => $bendicion->items()->sole()->id, 'buenas' => 0, 'danadas' => 1]],
        ], arguments: ['factura' => $facturaDe($bendicion)->id])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $cambio = Reposicion::query()->sole();

    expect([$cambio->devolucion->carga_id, $cambio->cliente_id, $cambio->estaImpresa()])->toBe([$carga->id, $bendicion->cliente_id, false])
        ->and(OrdenesDeCambio::de($this->encargado, OrdenesDeCambio::POR_IMPRIMIR)->modelKeys())->toBe([$cambio->id]);

    Livewire::test(ViewCarga::class, ['record' => $carga->getRouteKey()])
        ->assertSee('Devolución: '.$cambio->devolucion->numero.' · Cambio');

    // Liquidar: la de crédito se descuenta de lo que debe traer.
    Livewire::test(Liquidacion::class, ['record' => $carga->getRouteKey()])
        ->callAction('devolucion', data: [
            'tipo'   => TipoDevolucion::Credito->value,
            'lineas' => [['id' => $roca->items()->sole()->id, 'buenas' => 1, 'danadas' => 0]],
        ], arguments: ['factura' => $facturaDe($roca)->id])
        ->assertHasNoActionErrors()
        ->assertNotified()
        ->assertSee('Devoluciones que se descuentan');

    $undoRepeaterFake();
    $credito = $carga->devoluciones()->where('tipo', TipoDevolucion::Credito->value)->sole();

    expect($credito->total)->toBeGreaterThan(0.0)
        ->and(CuentaCarga::de($carga->fresh() ?? $carga, [], 0.0, [], [])['devuelto'])->toBe($credito->total);
});

test('en la lista de cargas, Devolución va al lado de Liquidar: se escoge la factura (con una sola, directo) y abre la devolución', function (): void {
    $bendicion = pedidoDeCambios($this, 4, 'PULPERIA LA BENDICION');
    $roca = pedidoDeCambios($this, 3, 'PULPERIA LA ROCA');
    $sola = pedidoDeCambios($this, 2, 'PULPERIA EL SOL');
    $dos = cargaConCambios($this, [$bendicion, $roca], []);
    $una = cargaConCambios($this, [$sola], []);
    $facturaDe = fn (Pedido $pedido): Factura => Factura::query()->where('pedido_id', $pedido->id)->where('estado', EstadoFactura::Vigente)->sole();

    expect(array_keys(DevolucionAction::facturasDe($dos)))->toBe([$facturaDe($bendicion)->id, $facturaDe($roca)->id])
        ->and(DevolucionAction::facturasDe($una)[$facturaDe($sola)->id])->toStartWith('PULPERIA EL SOL · ');

    $this->actingAs($this->encargado);
    $undoRepeaterFake = Repeater::fake();

    // Con dos facturas se escoge cuál; luego, el mismo formulario de la devolución.
    Livewire::test(ListCargas::class)
        ->assertActionVisible(TestAction::make('devolver')->table($dos))
        ->callAction(TestAction::make('devolver')->table($dos), data: ['factura' => $facturaDe($roca)->id])
        ->assertActionMounted('devolucion')
        ->fillForm([
            'tipo'   => TipoDevolucion::Credito->value,
            'lineas' => [['id' => $roca->items()->sole()->id, 'buenas' => 1, 'danadas' => 0]],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified();

    $credito = Devolucion::query()->sole();

    expect([$credito->pedido_id, $credito->tipo, $credito->carga_id])->toBe([$roca->id, TipoDevolucion::Credito, $dos->id]);

    // Con una sola factura va directo a la devolución.
    Livewire::test(ListCargas::class)
        ->callAction(TestAction::make('devolver')->table($una))
        ->assertActionMounted('devolucion');

    $undoRepeaterFake();

    // Liquidada, ya no.
    app(LiquidarCarga::class)->ejecutar($una, ['entregas' => [], 'efectivo' => $una->total_carga, 'cheques' => [], 'transferencias' => []], $this->encargado);

    Livewire::test(ListCargas::class)
        ->set('activeTab', 'todas')
        ->assertActionHidden(TestAction::make('devolver')->table($una->fresh()))
        ->assertActionVisible(TestAction::make('devolver')->table($dos));
});
