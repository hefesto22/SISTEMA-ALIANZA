<?php

declare(strict_types=1);

use App\Domain\Cargas\ArmarCarga;
use App\Domain\Cargas\CuentaCarga;
use App\Domain\Cargas\FacturasParaCargar;
use App\Domain\Cargas\HojaDeCarga;
use App\Domain\Cargas\LiquidarCarga;
use App\Domain\Devoluciones\RegistrarDevolucion;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Inventario\RegistrarCompra;
use App\Domain\Pedidos\CorregirPedido;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarDespacho;
use App\Enums\EntregaFactura;
use App\Enums\EstadoCarga;
use App\Enums\EstadoFactura;
use App\Enums\EstadoPedido;
use App\Enums\ModoImpresion;
use App\Enums\Rol;
use App\Enums\TipoDevolucion;
use App\Filament\Resources\Cargas\CargaResource;
use App\Filament\Resources\Cargas\Pages\CreateCarga;
use App\Filament\Resources\Cargas\Pages\EditCarga;
use App\Filament\Resources\Cargas\Pages\Liquidacion;
use App\Filament\Resources\Cargas\Pages\ListCargas;
use App\Filament\Resources\Cargas\Pages\ViewCarga;
use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Models\Bodega;
use App\Models\Carga;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Existencia;
use App\Models\Factura;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Cargas del camión: se arman con facturas impresas (el total es lo que debe
 * regresar en dinero) y al regresar se liquidan: entregada, no se entregó
 * (se anula o sale en otra carga), lo que trajo y el cuadre.
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
    // El que sale a entregar: otro vendedor de la bodega.
    $this->sale = User::factory()->create(['bodega_id' => $this->bodega->id, 'name' => 'JOSE RAMIREZ']);
    $this->sale->assignRole(Rol::Vendedor->value);

    $this->jabon = Producto::factory()->precio(8.0, 10.0)->create(['margen' => 0.20]);
    $this->un = presentacion($this->jabon);
    app(RegistrarCompra::class)->ejecutar($this->bodega, $this->jabon->proveedor, [
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->un->id, 'cantidad' => 100, 'costo' => 9.2],
    ], $this->admin);

    facturacionLista();
    Configuracion::actual()->update(['notas_credito' => false]);
});

/**
 * Un pedido de $cantidad UN de jabón, validado e impreso (con su factura).
 */
function pedidoParaCarga(object $prueba, int $cantidad, ?User $vendedor = null): Pedido
{
    $pedido = Pedido::query()->create([
        'bodega_id'   => $prueba->bodega->id,
        'cliente_id'  => Cliente::factory()->create(['bodega_id' => $prueba->bodega->id])->id,
        'vendedor_id' => ($vendedor ?? $prueba->vendedor)->id,
        'fecha'       => now(),
    ]);
    $pedido->items()->create(['producto_id' => $prueba->jabon->id, 'presentacion_id' => $prueba->un->id, 'cantidad' => $cantidad]);
    app(ValidarDespacho::class)->ejecutar(app(RecalcularPedido::class)->ejecutar($pedido), [], $prueba->encargado);
    imprimir($pedido, $prueba->encargado);

    return $pedido->fresh();
}

function facturaDe(Pedido $pedido): Factura
{
    return Factura::query()->where('pedido_id', $pedido->id)->where('estado', EstadoFactura::Vigente)->sole();
}

/**
 * @param list<Pedido> $pedidos
 */
function cargaCon(object $prueba, array $pedidos): Carga
{
    return app(ArmarCarga::class)->ejecutar(null, [
        'bodega_id'     => $prueba->bodega->id,
        'fecha'         => today()->toDateString(),
        'repartidor_id' => $prueba->sale->id,
        'camion'        => 'pab 1234',
        'facturas'      => array_map(fn (Pedido $pedido): int => facturaDe($pedido)->id, $pedidos),
    ], $prueba->encargado);
}

test('armar: lleva facturas impresas, sale con su total y una factura no va en dos cargas', function (): void {
    $uno = pedidoParaCarga($this, 10);
    $dos = pedidoParaCarga($this, 5);

    expect(FacturasParaCargar::de($this->bodega->id)->modelKeys())->toBe([facturaDe($uno)->id, facturaDe($dos)->id]);

    $carga = cargaCon($this, [$uno, $dos]);

    expect($carga->estado)->toBe(EstadoCarga::EnRuta)
        ->and($carga->camion)->toBe('PAB 1234')
        ->and($carga->renglones()->count())->toBe(2)
        ->and($carga->total_carga)->toBe(round(facturaDe($uno)->total + facturaDe($dos)->total, 2))
        ->and(FacturasParaCargar::de($this->bodega->id))->toBeEmpty()
        ->and(fn () => cargaCon($this, [$uno]))->toThrow(ReglaNegocioException::class, 'ya va en la carga '.$carga->numero());

    // Quien sale tiene que ser de la bodega.
    $otra = User::factory()->create(['bodega_id' => Bodega::factory()->create()->id]);

    expect(fn () => app(ArmarCarga::class)->ejecutar($carga, [
        'bodega_id'     => $this->bodega->id,
        'fecha'         => today()->toDateString(),
        'repartidor_id' => $otra->id,
        'facturas'      => [facturaDe($uno)->id],
    ], $this->encargado))->toThrow(ReglaNegocioException::class, 'usuario activo de la bodega');
});

test('las de días atrás salen si se busca desde antes (Impresas desde)', function (): void {
    $this->travelTo(now()->subDays(12));
    $vieja = pedidoParaCarga($this, 2);
    $this->travelBack();

    $hace12 = today()->subDays(12)->toDateString();

    expect(FacturasParaCargar::de($this->bodega->id))->toBeEmpty()
        ->and(FacturasParaCargar::de($this->bodega->id, desde: $hace12)->modelKeys())->toBe([facturaDe($vieja)->id]);

    expect(FacturasParaCargar::anteriores($this->bodega->id, FacturasParaCargar::desdeDe()->toDateString()))
        ->toBe(['cuantas' => 1, 'desde' => $hace12, 'ultimo' => $hace12]);

    $this->actingAs($this->encargado);

    // Nueva carga sin día: como en los últimos días no hay, empieza en el último día que quedó sin cargar.
    Livewire::test(CreateCarga::class)
        ->assertOk()
        ->assertFormSet(['desde' => $hace12, 'facturas' => []])
        ->assertSee('Sale con');

    // "Cargar camión" de ese día: la lista empieza ese día y la trae marcada.
    Livewire::withQueryParams(['dia' => $hace12, 'bodega' => $this->bodega->id])
        ->test(CreateCarga::class)
        ->assertOk()
        ->assertFormSet(['desde' => $hace12, 'facturas' => [(string) facturaDe($vieja)->id]]);
});

test('la factura que sustituye a una corregida sube a la misma carga y la anulada se queda', function (): void {
    $pedido = pedidoParaCarga($this, 10);
    $carga = cargaCon($this, [$pedido]);
    $vieja = facturaDe($pedido);

    app(CorregirPedido::class)->ejecutar($pedido, [$pedido->items()->sole()->id => 6], $this->encargado, 'no había');
    imprimir($pedido->fresh(), $this->encargado, ModoImpresion::Originales);
    $nueva = facturaDe($pedido->fresh());

    expect($carga->renglones()->pluck('factura_id')->all())->toBe([$vieja->id, $nueva->id])
        ->and($carga->fresh()->total_carga)->toBe($nueva->total);

    // Al cambiarla, la anulada se queda junto a la que la sustituye.
    app(ArmarCarga::class)->ejecutar($carga->fresh(), [
        'bodega_id'     => $this->bodega->id,
        'fecha'         => today()->toDateString(),
        'repartidor_id' => $this->sale->id,
        'facturas'      => [$nueva->id],
    ], $this->encargado);

    expect($carga->renglones()->count())->toBe(2);
});

test('liquidar: lo entregado menos lo devuelto; la que no se entregó se anula y regresa; si no cuadra pide nota', function (): void {
    $uno = pedidoParaCarga($this, 10);
    $dos = pedidoParaCarga($this, 5);
    $tres = pedidoParaCarga($this, 4);
    $carga = cargaCon($this, [$uno, $dos, $tres]);

    // En la ruta, el cliente del primero devuelve 2 (crédito): se descuenta de esta carga.
    $devolucion = app(RegistrarDevolucion::class)->ejecutar($uno, TipoDevolucion::Credito, [$uno->items()->sole()->id => ['buenas' => 2]], $this->encargado);
    expect($devolucion->carga_id)->toBe($carga->id);

    $entregas = [facturaDe($tres)->id => CuentaCarga::ANULAR];
    $debe = round(facturaDe($uno)->total + facturaDe($dos)->total - $devolucion->total, 2);
    $cuenta = CuentaCarga::de($carga, $entregas, 0.0, [], []);

    expect($cuenta['debe_traer'])->toBe($debe)
        ->and($cuenta['no_entregado'])->toBe(facturaDe($tres)->total)
        ->and($cuenta['devuelto'])->toBe($devolucion->total);

    $liquidar = app(LiquidarCarga::class);

    expect(fn () => $liquidar->ejecutar($carga, ['entregas' => $entregas, 'efectivo' => $debe - 10, 'cheques' => [], 'transferencias' => []], $this->encargado))
        ->toThrow(ReglaNegocioException::class, 'escriba en la nota');

    $antes = Existencia::de($this->bodega->id, $this->un->id);
    $facturaTres = facturaDe($tres);

    $carga = $liquidar->ejecutar($carga, [
        'entregas'       => $entregas,
        'efectivo'       => $debe - 50,
        'cheques'        => [['factura_id' => facturaDe($dos)->id, 'banco' => 'bac', 'referencia' => '000123', 'monto' => 30.0]],
        'transferencias' => [['banco' => 'atlántida', 'referencia' => 'tr-9', 'monto' => 20.0]],
    ], $this->encargado);

    expect($carga->estado)->toBe(EstadoCarga::Liquidada)
        ->and([$carga->debe_traer, $carga->trajo, $carga->diferencia, $carga->diferenciaTexto()])->toBe([$debe, $debe, 0.0, 'Cuadra'])
        ->and($carga->renglones()->pluck('estado')->all())->toBe([EntregaFactura::Entregada, EntregaFactura::Entregada, EntregaFactura::Anulada])
        ->and($tres->fresh()->estado)->toBe(EstadoPedido::Anulado)
        ->and($facturaTres->fresh()->estado)->toBe(EstadoFactura::Anulada)
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe($antes + 4)
        ->and($carga->pagos()->pluck('banco')->all())->toBe(['BAC', 'ATLÁNTIDA'])
        ->and(fn () => $liquidar->ejecutar($carga, ['entregas' => [], 'efectivo' => 0.0, 'cheques' => [], 'transferencias' => []], $this->encargado))
        ->toThrow(ReglaNegocioException::class, 'ya se liquidó');

    // La hoja de carga suma lo que salió (también lo que regresó sin entregarse).
    expect(HojaDeCarga::datos($carga->fresh())['productos'][0]['total'])->toBe(19);
});

test('sale en otra carga: no se cobra y queda libre para cargarse otro día', function (): void {
    $pedido = pedidoParaCarga($this, 3);
    $carga = cargaCon($this, [$pedido]);

    $carga = app(LiquidarCarga::class)->ejecutar($carga, [
        'entregas'       => [facturaDe($pedido)->id => CuentaCarga::OTRA_CARGA],
        'efectivo'       => 0.0,
        'cheques'        => [],
        'transferencias' => [],
    ], $this->encargado);

    expect([$carga->debe_traer, $carga->diferenciaTexto()])->toBe([0.0, 'Cuadra'])
        ->and($carga->renglones()->sole()->estado)->toBe(EntregaFactura::OtraCarga)
        ->and($pedido->fresh()->estado)->toBe(EstadoPedido::Impreso)
        ->and(FacturasParaCargar::de($this->bodega->id)->modelKeys())->toBe([facturaDe($pedido)->id])
        ->and(cargaCon($this, [$pedido])->renglones()->count())->toBe(1);
});

test('las de un día que ya salió en su ruta y vendedor no salen para cargar; dos camiones del mismo día sí se la reparten', function (): void {
    $hoy = today();
    $hace = fn (int $dias): string => $hoy->copy()->subDays($dias)->toDateString();
    $otro = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $otro->assignRole(Rol::Vendedor->value);

    $this->travelTo($hoy->copy()->subDays(3)->setTime(8, 0));
    $viejo = pedidoParaCarga($this, 1);
    $deOtro = pedidoParaCarga($this, 1, $otro);
    $this->travelTo($hoy->copy()->subDays(2)->setTime(8, 0));
    $uno = pedidoParaCarga($this, 2);
    $dos = pedidoParaCarga($this, 3);
    $this->travelBack();

    // Hoy sale un camión con una del día del vendedor: las de días antes de él ya no salen.
    $carga = cargaCon($this, [$uno]);
    $ids = fn (array $pedidos): array => array_map(fn (Pedido $pedido): int => facturaDe($pedido)->id, $pedidos);

    expect(FacturasParaCargar::de($this->bodega->id, desde: $hace(5))->modelKeys())->toBe($ids([$deOtro, $dos]))
        ->and(FacturasParaCargar::anteriores($this->bodega->id, $hace(2)))->toBe(['cuantas' => 1, 'desde' => $hace(3), 'ultimo' => $hace(3)])
        // Al cambiar la carga, ella no cierra días: salen todas.
        ->and(FacturasParaCargar::de($this->bodega->id, carga: $carga->id, desde: $hace(5))->modelKeys())->toBe($ids([$viejo, $deOtro, $uno, $dos]))
        // Mañana, la que quedó de ese día tampoco sale (el camión ya salió).
        ->and(FacturasParaCargar::de($this->bodega->id, $hoy->copy()->addDay()->toDateString(), desde: $hace(5))->modelKeys())->toBe($ids([$deOtro]));

    // La que la liquidación manda a otra carga sí sale.
    app(LiquidarCarga::class)->ejecutar($carga, [
        'entregas'       => [facturaDe($uno)->id => CuentaCarga::OTRA_CARGA],
        'efectivo'       => 0.0,
        'cheques'        => [],
        'transferencias' => [],
    ], $this->encargado);

    expect(FacturasParaCargar::de($this->bodega->id, $hoy->copy()->addDay()->toDateString(), desde: $hace(5))->modelKeys())->toBe($ids([$deOtro, $uno]));
});

test('bodega arma la carga desde Impresos, la ve, imprime sus hojas y la liquida en pantalla', function (): void {
    $uno = pedidoParaCarga($this, 10);
    $dos = pedidoParaCarga($this, 5);
    $this->actingAs($this->encargado);

    // En Impresos, cada día trae "Cargar camión".
    Livewire::test(ListPedidos::class)
        ->set('activeTab', EstadoPedido::Impreso->value)
        ->assertSee('Cargar camión')
        ->assertSee(CargaResource::getUrl('create', ['dia' => today()->toDateString(), 'bodega' => $this->bodega->id]));

    Livewire::withQueryParams(['dia' => today()->toDateString(), 'bodega' => $this->bodega->id])
        ->test(CreateCarga::class)
        ->assertOk()
        ->assertSee('Facturas que lleva')
        ->assertFormSet(['facturas' => [(string) facturaDe($uno)->id, (string) facturaDe($dos)->id]])
        ->fillForm(['repartidor_id' => $this->sale->id, 'camion' => 'pab 1234'])
        ->call('create')
        ->assertHasNoFormErrors();

    $carga = Carga::query()->latest('id')->firstOrFail();

    expect([$carga->repartidor_id, $carga->camion, $carga->renglones()->count()])->toBe([$this->sale->id, 'PAB 1234', 2]);

    Livewire::test(ListCargas::class)->assertOk()->assertCanSeeTableRecords([$carga]);
    Livewire::test(ViewCarga::class, ['record' => $carga->getRouteKey()])
        ->assertOk()
        ->assertSee('Lo que se sube al camión')
        ->assertSee('JOSE RAMIREZ');
    Livewire::test(EditCarga::class, ['record' => $carga->getRouteKey()])
        ->assertOk()
        ->assertFormSet(['facturas' => [(string) facturaDe($uno)->id, (string) facturaDe($dos)->id]]);

    $this->get(route('cargas.imprimir', ['carga' => $carga, 'hoja' => 'carga']))->assertOk()->assertSee('Hoja de carga');
    $this->get(route('cargas.imprimir', ['carga' => $carga, 'hoja' => 'cobro']))->assertOk()->assertSee('Hoja de cobro');
    $this->get(route('cargas.imprimir', ['carga' => $carga, 'hoja' => 'liquidacion']))->assertNotFound();

    $total = $carga->total_carga;

    // Sin nota no se liquida si no cuadra.
    Livewire::test(Liquidacion::class, ['record' => $carga->getRouteKey()])
        ->assertOk()
        ->assertSee('Cuadre')
        ->set('data.efectivo', $total - 5)
        ->call('liquidar')
        ->assertHasFormErrors(['nota']);

    expect($carga->fresh()->estado)->toBe(EstadoCarga::EnRuta);

    Livewire::test(Liquidacion::class, ['record' => $carga->getRouteKey()])
        ->set('data.efectivo', $total)
        ->call('liquidar')
        ->assertHasNoFormErrors()
        ->assertRedirect(CargaResource::getUrl('view', ['record' => $carga]));

    $carga->refresh();

    expect([$carga->estado, $carga->trajo, $carga->diferenciaTexto()])->toBe([EstadoCarga::Liquidada, $total, 'Cuadra']);

    $this->get(route('cargas.imprimir', ['carga' => $carga, 'hoja' => 'liquidacion']))->assertOk()->assertSee('Liquidación de la carga');
    $this->get(CargaResource::getUrl('liquidar', ['record' => $carga]))->assertForbidden();
    $this->get(CargaResource::getUrl('edit', ['record' => $carga]))->assertForbidden();
});

test('el vendedor no ve las cargas y el encargado no ve las de otra bodega', function (): void {
    $carga = cargaCon($this, [pedidoParaCarga($this, 2)]);

    $this->actingAs($this->vendedor);
    $this->get(CargaResource::getUrl('index'))->assertForbidden();
    $this->get(route('cargas.imprimir', ['carga' => $carga, 'hoja' => 'carga']))->assertForbidden();

    $otra = Bodega::factory()->create();
    $encargadoOtra = User::factory()->create(['bodega_id' => $otra->id]);
    $encargadoOtra->assignRole(Rol::Bodega->value);
    $this->actingAs($encargadoOtra);

    $this->get(CargaResource::getUrl('index'))->assertOk();
    $this->get(CargaResource::getUrl('view', ['record' => $carga]))->assertNotFound();
    $this->get(route('cargas.imprimir', ['carga' => $carga, 'hoja' => 'carga']))->assertForbidden();
});
