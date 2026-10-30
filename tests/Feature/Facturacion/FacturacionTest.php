<?php

declare(strict_types=1);

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Pedidos\CorregirPedido;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarDespacho;
use App\Enums\EstadoFactura;
use App\Enums\EstadoPedido;
use App\Enums\ModoImpresion;
use App\Enums\Rol;
use App\Enums\UnidadVenta;
use App\Filament\Resources\Cais\Pages\ManageCais;
use App\Filament\Resources\Facturas\Pages\ListFacturas;
use App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction;
use App\Filament\Resources\Pedidos\Pages\ViewPedido;
use App\Models\Bodega;
use App\Models\Cai;
use App\Models\Cliente;
use App\Models\DatosFiscales;
use App\Models\Factura;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Database\Seeders\RolesSistemaSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Facturación con CAI: número al imprimir, CAI por bodega / global /
 * compartido, anulación al corregir y datos fiscales.
 */
beforeEach(function (): void {
    $this->seed(RolesSistemaSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->cop = Bodega::factory()->create(['codigo' => 'COP', 'nombre' => 'Santa Rosa']);
    $this->oco = Bodega::factory()->create(['codigo' => 'OCO', 'nombre' => 'Ocotepeque']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Rol::Administrador->value);

    $this->vendedores = [];
    $this->encargados = [];

    foreach ([$this->cop, $this->oco] as $bodega) {
        $vendedor = User::factory()->create(['bodega_id' => $bodega->id]);
        $vendedor->assignRole(Rol::Vendedor->value);
        $this->vendedores[$bodega->codigo] = $vendedor;

        $encargado = User::factory()->create(['bodega_id' => $bodega->id]);
        $encargado->assignRole(Rol::Bodega->value);
        $this->encargados[$bodega->codigo] = $encargado;
    }

    // Gravado: L 100 la unidad. Exento: caja de 10 a L 50 = L 500.
    $this->gravado = Producto::factory()->precio(80.0, 100.0)->create(['unidades_por_caja' => 12]);
    $this->exento = Producto::factory()->exento()->precio(40.0, 50.0)->create(['unidades_por_caja' => 10]);

    DatosFiscales::actual()->update([
        'razon_social'     => 'Distribuidora Alianza S. de R.L.',
        'nombre_comercial' => 'Alianza Hozana',
        'rtn'              => '05019000123456',
        'direccion'        => 'Barrio El Centro, Santa Rosa de Copán',
        'telefono'         => '2662-0000',
    ]);
});

/**
 * Pedido validado: 2 unidades del gravado + 1 caja del exento.
 * Total L 730 (200 + 30 de ISV + 500 exento).
 */
function pedidoValidado(Bodega $bodega, User $vendedor, Producto $gravado, Producto $exento): Pedido
{
    $pedido = Pedido::query()->create([
        'bodega_id'   => $bodega->id,
        'cliente_id'  => Cliente::factory()->create(['bodega_id' => $bodega->id, 'rtn' => '04011960001000'])->id,
        'vendedor_id' => $vendedor->id,
        'fecha'       => now(),
    ]);

    $pedido->items()->create(['producto_id' => $gravado->id, 'unidad' => UnidadVenta::Unidad, 'cantidad' => 2]);
    $pedido->items()->create(['producto_id' => $exento->id, 'unidad' => UnidadVenta::Caja, 'cantidad' => 1]);

    $pedido = app(RecalcularPedido::class)->ejecutar($pedido);

    return app(ValidarDespacho::class)->ejecutar($pedido, [], User::query()->role(Rol::Administrador->value)->firstOrFail());
}

test('al imprimir se emite la factura con el CAI de la bodega; las copias usan el mismo número', function (): void {
    $cai = Cai::factory()->para($this->cop)->rango(120, 130)->create(['establecimiento' => '000', 'punto_emision' => '001']);

    $primero = pedidoValidado($this->cop, $this->vendedores['COP'], $this->gravado, $this->exento);
    $segundo = pedidoValidado($this->cop, $this->vendedores['COP'], $this->gravado, $this->exento);

    $this->actingAs($this->encargados['COP']);

    $this->get(imprimir($primero, $this->encargados['COP'], ModoImpresion::Originales)->url())
        ->assertOk()
        ->assertSee('FACTURA')
        ->assertSee('000-001-01-00000120')
        ->assertSee($cai->cai)
        ->assertSee('del 000-001-01-00000120 al 000-001-01-00000130')
        ->assertSee($cai->fecha_limite->format('d/m/Y'))
        ->assertSee('05019000123456')
        ->assertSee('ALIANZA HOZANA')
        ->assertSee('SON: SETECIENTOS TREINTA LEMPIRAS CON 00/100')
        ->assertDontSee('REIMPRESIÓN');

    $this->get(imprimir($primero, $this->encargados['COP'], ModoImpresion::Copia)->url())
        ->assertOk()
        ->assertSee('000-001-01-00000120')
        ->assertSee('REIMPRESIÓN');

    $this->get(imprimir($segundo, $this->encargados['COP'], ModoImpresion::Originales)->url())
        ->assertOk()
        ->assertSee('000-001-01-00000121');

    $factura = Factura::query()->where('pedido_id', $primero->id)->sole();

    expect(Factura::query()->count())->toBe(2)
        ->and($cai->fresh()?->siguiente)->toBe(122)
        ->and($factura->impresiones)->toBe(2)
        ->and($factura->estado)->toBe(EstadoFactura::Vigente)
        ->and($factura->cliente_rtn)->toBe('04011960001000')
        ->and($factura->importe_exento)->toBe(500.0)
        ->and($factura->importe_gravado)->toBe(200.0)
        ->and($factura->isv)->toBe(30.0)
        ->and($factura->total)->toBe(730.0);
});

test('el CAI puede ser de una bodega, global para las demás, o compartido por varias', function (): void {
    $sb = Bodega::factory()->create(['codigo' => 'SB']);
    $vendedorSb = User::factory()->create(['bodega_id' => $sb->id]);
    $vendedorSb->assignRole(Rol::Vendedor->value);
    $lucerna = Bodega::factory()->create(['codigo' => 'LUC']);
    $vendedorLuc = User::factory()->create(['bodega_id' => $lucerna->id]);
    $vendedorLuc->assignRole(Rol::Vendedor->value);

    Cai::factory()->create(['punto_emision' => '001']);
    Cai::factory()->para($this->cop)->create(['punto_emision' => '002']);
    Cai::factory()->para($sb, $lucerna)->create(['punto_emision' => '003']);

    $deCop = pedidoValidado($this->cop, $this->vendedores['COP'], $this->gravado, $this->exento);
    $deOco = pedidoValidado($this->oco, $this->vendedores['OCO'], $this->gravado, $this->exento);
    $deSb = pedidoValidado($sb, $vendedorSb, $this->gravado, $this->exento);
    $deLuc = pedidoValidado($lucerna, $vendedorLuc, $this->gravado, $this->exento);

    foreach ([$deCop, $deOco, $deSb, $deLuc] as $pedido) {
        imprimir($pedido, $this->admin, ModoImpresion::Originales);
    }

    $numero = fn (Pedido $pedido): string => Factura::query()->where('pedido_id', $pedido->id)->sole()->numero;

    expect($numero($deCop))->toBe('000-002-01-00000001')     // el suyo
        ->and($numero($deOco))->toBe('000-001-01-00000001')  // global
        ->and($numero($deSb))->toBe('000-003-01-00000001')   // compartido…
        ->and($numero($deLuc))->toBe('000-003-01-00000002'); // …sigue la misma numeración
});

test('si el CAI se acaba o vence se usa otro que sirva; sin ninguno no se imprime ni cambia nada', function (): void {
    $corto = Cai::factory()->para($this->cop)->rango(1, 1)->create(['punto_emision' => '010']);
    Cai::factory()->para($this->cop)->vencido()->create(['punto_emision' => '011']);

    $primero = pedidoValidado($this->cop, $this->vendedores['COP'], $this->gravado, $this->exento);
    $segundo = pedidoValidado($this->cop, $this->vendedores['COP'], $this->gravado, $this->exento);

    $this->actingAs($this->encargados['COP']);

    $this->get(imprimir($primero, $this->encargados['COP'], ModoImpresion::Originales)->url())->assertOk()->assertSee('000-010-01-00000001');

    expect(fn () => imprimir($segundo, $this->encargados['COP'], ModoImpresion::Originales))
        ->toThrow(ReglaNegocioException::class, 'no tiene un CAI vigente');

    // En pantalla sale como aviso, sin romper nada.
    Livewire::test(ViewPedido::class, ['record' => $segundo->getRouteKey()])
        ->callAction('imprimir')
        ->assertNotified('No se imprimió')
        ->assertNotDispatched(ImprimirPedidoAction::EVENTO);

    expect($segundo->fresh()?->estado)->toBe(EstadoPedido::PorImprimir)
        ->and($segundo->fresh()?->impresiones)->toBe(0)
        ->and(Factura::query()->where('pedido_id', $segundo->id)->exists())->toBeFalse()
        ->and($corto->fresh()?->estaAgotado())->toBeTrue();

    Cai::factory()->create(['punto_emision' => '020']);

    $this->get(imprimir($segundo, $this->encargados['COP'], ModoImpresion::Originales)->url())->assertOk()->assertSee('000-020-01-00000001');
});

test('sin datos fiscales completos no se emite la factura', function (): void {
    Cai::factory()->create();
    DatosFiscales::actual()->update(['rtn' => null]);

    $pedido = pedidoValidado($this->cop, $this->vendedores['COP'], $this->gravado, $this->exento);

    expect(fn () => imprimir($pedido, $this->encargados['COP'], ModoImpresion::Originales))
        ->toThrow(ReglaNegocioException::class, 'Faltan los datos fiscales');

    expect(Factura::query()->count())->toBe(0);
});

test('imprimir varios es todo o nada: si una bodega no tiene CAI no se gasta ningún número', function (): void {
    $cai = Cai::factory()->para($this->cop)->create();

    $deCop = pedidoValidado($this->cop, $this->vendedores['COP'], $this->gravado, $this->exento);
    $deOco = pedidoValidado($this->oco, $this->vendedores['OCO'], $this->gravado, $this->exento);

    expect(fn () => imprimir([$deCop, $deOco], $this->admin, ModoImpresion::Originales))
        ->toThrow(ReglaNegocioException::class, 'OCOTEPEQUE');

    expect(Factura::query()->count())->toBe(0)
        ->and($cai->fresh()?->siguiente)->toBe(1)
        ->and($deCop->fresh()?->estado)->toBe(EstadoPedido::PorImprimir);
});

test('corregir un impreso anula su factura; la nueva la sustituye y todo queda en el historial', function (): void {
    Cai::factory()->create(['punto_emision' => '001']);

    $pedido = pedidoValidado($this->cop, $this->vendedores['COP'], $this->gravado, $this->exento);
    $lineas = $pedido->items()->orderBy('id')->pluck('id')->all();

    $this->actingAs($this->encargados['COP']);
    imprimir($pedido, $this->encargados['COP'], ModoImpresion::Originales);

    // Sin cambiar nada no se corrige: no se anula ni se gasta un número.
    expect(fn () => app(CorregirPedido::class)->ejecutar($pedido, [], $this->encargados['COP'], 'nada'))
        ->toThrow(ReglaNegocioException::class);

    $original = Factura::query()->where('pedido_id', $pedido->id)->sole();
    expect($original->estado)->toBe(EstadoFactura::Vigente);

    app(CorregirPedido::class)->ejecutar($pedido, [$lineas[0] => 1], $this->encargados['COP'], 'devolvió una unidad');

    expect($original->fresh()?->estado)->toBe(EstadoFactura::Anulada)
        ->and($original->fresh()?->anulada_por)->toBe($this->encargados['COP']->id)
        ->and($original->fresh()?->motivo_anulacion)->toBe('PEDIDO CORREGIDO: DEVOLVIÓ UNA UNIDAD')
        ->and($pedido->fresh()?->facturaPorSustituir()?->id)->toBe($original->id)
        ->and($pedido->fresh()?->factura)->toBeNull();

    $this->get(imprimir($pedido, $this->encargados['COP'], ModoImpresion::Originales)->url())
        ->assertOk()
        ->assertSee('000-001-01-00000002')
        ->assertSee('Sustituye a la factura N° 000-001-01-00000001');

    $nueva = Factura::query()->where('pedido_id', $pedido->id)->where('estado', EstadoFactura::Vigente)->sole();

    expect($nueva->reemplaza_a_id)->toBe($original->id)
        ->and($nueva->total)->toBe(615.0)
        ->and($pedido->fresh()?->facturaPorSustituir())->toBeNull();

    Livewire::test(ViewPedido::class, ['record' => $pedido->getRouteKey()])
        ->assertSee('000-001-01-00000001')
        ->assertSee('Sustituida por 000-001-01-00000002');

    Livewire::test(ListFacturas::class)
        ->set('activeTab', 'anuladas')
        ->assertCanSeeTableRecords([$original])
        ->assertCanNotSeeTableRecords([$nueva]);
});

test('en Facturas el encargado ve solo las de su bodega', function (): void {
    Cai::factory()->create();

    $deCop = pedidoValidado($this->cop, $this->vendedores['COP'], $this->gravado, $this->exento);
    $deOco = pedidoValidado($this->oco, $this->vendedores['OCO'], $this->gravado, $this->exento);

    imprimir([$deCop, $deOco], $this->admin, ModoImpresion::Originales);

    $facturaCop = Factura::query()->where('pedido_id', $deCop->id)->sole();
    $facturaOco = Factura::query()->where('pedido_id', $deOco->id)->sole();

    $this->actingAs($this->encargados['COP']);

    Livewire::test(ListFacturas::class)
        ->assertCanSeeTableRecords([$facturaCop])
        ->assertCanNotSeeTableRecords([$facturaOco]);
});

test('el CAI se valida: formato, rango que no choque con otro; queda asignado a sus bodegas', function (): void {
    Cai::factory()->rango(1, 100)->create(['establecimiento' => '000', 'punto_emision' => '001']);

    $this->actingAs($this->admin);

    $datos = [
        'cai'             => 'a1b2c3-d4e5f6-a7b8c9-d0e1f2-a3b4c5-d6',
        'establecimiento' => '000',
        'punto_emision'   => '001',
        'tipo_documento'  => Cai::FACTURA,
        'rango_desde'     => 50,
        'rango_hasta'     => 200,
        'fecha_limite'    => today()->addMonths(6)->format('Y-m-d'),
        'bodegas'         => [$this->cop->id],
        'activo'          => true,
    ];

    Livewire::test(ManageCais::class)
        ->callAction('create', data: [...$datos, 'cai' => 'NO-ES-UN-CAI'])
        ->assertHasActionErrors(['cai']);

    Livewire::test(ManageCais::class)
        ->callAction('create', data: $datos)
        ->assertHasActionErrors(['rango_hasta']);

    Livewire::test(ManageCais::class)
        ->callAction('create', data: [...$datos, 'rango_desde' => 101, 'rango_hasta' => 600])
        ->assertHasNoActionErrors();

    $nuevo = Cai::query()->where('cai', 'A1B2C3-D4E5F6-A7B8C9-D0E1F2-A3B4C5-D6')->sole();

    expect($nuevo->siguiente)->toBe(101)
        ->and($nuevo->bodegas->pluck('id')->all())->toBe([$this->cop->id])
        ->and(Cai::paraBodega($this->cop->id)?->id)->toBe($nuevo->id)
        ->and(Cai::paraBodega($this->oco->id)?->id)->not->toBe($nuevo->id);
});

test('los datos fiscales se guardan desde la pantalla de CAI', function (): void {
    $this->actingAs($this->admin);

    $datos = [
        'razon_social'     => 'Inversiones Hozana S.A.',
        'nombre_comercial' => 'Alianza Hozana',
        'rtn'              => '123',
        'direccion'        => 'Barrio Mercedes, Santa Rosa de Copán',
        'telefono'         => '2662-1111',
        'correo'           => 'facturas@alianza.hn',
        'leyenda'          => null,
    ];

    Livewire::test(ManageCais::class)
        ->callAction('datosFiscales', data: $datos)
        ->assertHasActionErrors(['rtn']);

    Livewire::test(ManageCais::class)
        ->callAction('datosFiscales', data: [...$datos, 'rtn' => '05019000999999'])
        ->assertHasNoActionErrors();

    $fiscales = DatosFiscales::actual();

    expect($fiscales->razon_social)->toBe('INVERSIONES HOZANA S.A.')
        ->and($fiscales->rtn)->toBe('05019000999999')
        ->and($fiscales->correo)->toBe('facturas@alianza.hn')
        ->and($fiscales->estanCompletos())->toBeTrue();
});

test('dos bodegas imprimiendo con el mismo CAI global sacan números seguidos, sin repetir ni saltar', function (): void {
    $cai = Cai::factory()->create(['punto_emision' => '001']);

    $deCop = [];
    $deOco = [];

    foreach (range(1, 3) as $n) {
        $deCop[] = pedidoValidado($this->cop, $this->vendedores['COP'], $this->gravado, $this->exento);
        $deOco[] = pedidoValidado($this->oco, $this->vendedores['OCO'], $this->gravado, $this->exento);
    }

    // Lotes que se cruzan: cada bodega imprime lo suyo, una y otra vez.
    imprimir([$deCop[0], $deCop[1]], $this->encargados['COP'], ModoImpresion::Originales);
    imprimir([$deOco[0]], $this->encargados['OCO'], ModoImpresion::Originales);
    imprimir([$deCop[2]], $this->encargados['COP'], ModoImpresion::Originales);
    imprimir([$deOco[1], $deOco[2]], $this->encargados['OCO'], ModoImpresion::Originales);

    $correlativos = Factura::query()->orderBy('correlativo')->pluck('correlativo')->all();

    expect($correlativos)->toBe([1, 2, 3, 4, 5, 6])
        ->and($cai->fresh()?->siguiente)->toBe(7)
        ->and(Factura::query()->distinct()->count('numero'))->toBe(6);
});

test('si se corrige el inicio del rango antes de usarlo, el primer número sale de ahí', function (): void {
    $cai = Cai::factory()->rango(1, 5000)->create(['punto_emision' => '001']);

    $cai->update(['rango_desde' => 501, 'rango_hasta' => 1000]);

    expect($cai->fresh()?->siguiente)->toBe(501);

    $pedido = pedidoValidado($this->cop, $this->vendedores['COP'], $this->gravado, $this->exento);
    imprimir($pedido, $this->encargados['COP'], ModoImpresion::Originales);

    expect(Factura::query()->sole()->numero)->toBe('000-001-01-00000501');
});
