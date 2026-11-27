<?php

declare(strict_types=1);

use App\Domain\Devoluciones\RegistrarDevolucion;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Inventario\RegistrarCompra;
use App\Domain\Pedidos\CorregirPedido;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarDespacho;
use App\Enums\EstadoReposicion;
use App\Enums\Rol;
use App\Enums\TipoDevolucion;
use App\Enums\TipoMovimiento;
use App\Filament\Resources\Devoluciones\Pages\ListDevoluciones;
use App\Filament\Resources\Pedidos\Actions\DevolucionAction;
use App\Filament\Resources\Pedidos\Pages\ViewPedido;
use App\Models\Bodega;
use App\Models\Cai;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Devolucion;
use App\Models\DevolucionItem;
use App\Models\Existencia;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

/**
 * Devoluciones de un pedido entregado: lo bueno regresa a la bodega, lo
 * dañado queda marcado; cambio (se le debe lo mismo: queda por entregar,
 * ver CambiosPorEntregarTest) o crédito (interno o con nota de crédito,
 * según Configuración).
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

    // Gravado: UN a L 10 y CJ12 a L 120 sin ISV. Hay 12 UN y 3 CJ12.
    $this->jabon = Producto::factory()->precio(8.0, 10.0)->create(['margen' => 0.20]);
    $this->un = presentacion($this->jabon);
    $this->cj12 = presentacion($this->jabon, 12);
    app(RegistrarCompra::class)->ejecutar($this->bodega, $this->jabon->proveedor, [
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->un->id, 'cantidad' => 12, 'costo' => 9.2],
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->cj12->id, 'cantidad' => 3, 'costo' => 110.4],
    ], $this->admin);

    $this->cai = facturacionLista();

    // De fábrica se emite la nota de crédito; estas pruebas son del crédito interno (la de la nota la enciende).
    Configuracion::actual()->update(['notas_credito' => false]);

    // Entregado: 10 UN y 1 CJ12, impreso con su factura.
    $pedido = Pedido::query()->create([
        'bodega_id'   => $this->bodega->id,
        'cliente_id'  => Cliente::factory()->create(['bodega_id' => $this->bodega->id])->id,
        'vendedor_id' => $this->vendedor->id,
        'fecha'       => now(),
    ]);
    $pedido->items()->create(['producto_id' => $this->jabon->id, 'presentacion_id' => $this->un->id, 'cantidad' => 10]);
    $pedido->items()->create(['producto_id' => $this->jabon->id, 'presentacion_id' => $this->cj12->id, 'cantidad' => 1]);
    app(ValidarDespacho::class)->ejecutar(app(RecalcularPedido::class)->ejecutar($pedido), [], $this->encargado);
    imprimir($pedido, $this->encargado);

    $this->pedido = $pedido->fresh();
    $this->lineaUn = $this->pedido->items()->where('presentacion_id', $this->un->id)->sole();
});

test('cambio: lo bueno regresa, lo dañado queda marcado y lo que se le repone queda por entregar (sale al subirlo al camión)', function (): void {
    expect(Existencia::de($this->bodega->id, $this->un->id))->toBe(2);

    $devolucion = app(RegistrarDevolucion::class)->ejecutar($this->pedido, TipoDevolucion::Cambio, [
        $this->lineaUn->id => ['buenas' => 1, 'danadas' => 2],
    ], $this->encargado, 'venían golpeados');

    // Entra 1 buena; las 3 que se le reponen todavía no salen.
    expect($devolucion->numero)->toBe('DEV-'.str_pad((string) $devolucion->id, 6, '0', STR_PAD_LEFT))
        ->and($devolucion->tipo)->toBe(TipoDevolucion::Cambio)
        ->and($devolucion->motivo)->toBe('VENÍAN GOLPEADOS')
        ->and($devolucion->nota_numero)->toBeNull()
        ->and($devolucion->items->map(fn (DevolucionItem $item): array => [$item->cantidad, $item->danado, $item->subtotal, $item->total])->all())->toBe([[1, false, 10.0, 11.5], [2, true, 20.0, 23.0]])
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe(3)
        ->and(MovimientoInventario::query()->where('pedido_id', $this->pedido->id)->whereIn('tipo', [TipoMovimiento::Devolucion, TipoMovimiento::Reposicion])->orderBy('id')->pluck('cantidad')->all())->toBe([1])
        ->and($devolucion->reposicion?->estado)->toBe(EstadoReposicion::PorEntregar)
        ->and($devolucion->reposicion?->cliente_id)->toBe($this->pedido->cliente_id)
        ->and($devolucion->reposicion?->productos()[0]['cantidad'] ?? null)->toBe(3);
});

test('crédito interno: se abona lo que pagó con su ISV; no se devuelve más de lo que salió menos lo ya devuelto', function (): void {
    $devolucion = app(RegistrarDevolucion::class)->ejecutar($this->pedido, TipoDevolucion::Credito, [$this->lineaUn->id => ['buenas' => 2]], $this->encargado);

    expect([$devolucion->subtotal, $devolucion->importe_gravado, $devolucion->isv, $devolucion->total])->toBe([20.0, 20.0, 3.0, 23.0])
        ->and($devolucion->nota_numero)->toBeNull()
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe(4)
        ->and(fn () => app(RegistrarDevolucion::class)->ejecutar($this->pedido, TipoDevolucion::Credito, [$this->lineaUn->id => ['buenas' => 9]], $this->encargado))
        ->toThrow(ReglaNegocioException::class, 'hasta 8 unidades')
        ->and(fn () => app(RegistrarDevolucion::class)->ejecutar($this->pedido, TipoDevolucion::Credito, [$this->lineaUn->id => ['buenas' => 0]], $this->encargado))
        ->toThrow(ReglaNegocioException::class, 'al menos un producto');
});

test('con notas de crédito encendidas se emite con el CAI tipo 07 y los datos de la factura; sin ese CAI no se registra', function (): void {
    Configuracion::actual()->update(['notas_credito' => true]);

    expect(fn () => app(RegistrarDevolucion::class)->ejecutar($this->pedido, TipoDevolucion::Credito, [$this->lineaUn->id => ['buenas' => 1]], $this->encargado))
        ->toThrow(ReglaNegocioException::class, 'CAI vigente de notas de crédito')
        ->and(Devolucion::query()->count())->toBe(0);

    $notas = Cai::query()->create([
        'cai'             => 'ABCDEF-123456-ABCDEF-123456-ABCDEF-07',
        'establecimiento' => '001',
        'punto_emision'   => '001',
        'tipo_documento'  => Cai::NOTA_CREDITO,
        'rango_desde'     => 1,
        'rango_hasta'     => 100,
        'fecha_limite'    => today()->addYear(),
    ]);

    $devolucion = app(RegistrarDevolucion::class)->ejecutar($this->pedido, TipoDevolucion::Credito, [$this->lineaUn->id => ['danadas' => 1]], $this->encargado);
    $cambio = app(RegistrarDevolucion::class)->ejecutar($this->pedido, TipoDevolucion::Cambio, [$this->lineaUn->id => ['danadas' => 1]], $this->encargado);

    expect($devolucion->nota_numero)->toBe('001-001-07-00000001')
        ->and($devolucion->factura_id)->toBe($this->pedido->factura()->firstOrFail()->id)
        ->and($devolucion->cai)->toBe($notas->cai)
        ->and($notas->fresh()?->siguiente)->toBe(2)
        ->and($cambio->nota_numero)->toBeNull()
        // Las facturas siguen saliendo con su CAI.
        ->and(Cai::paraBodega($this->bodega->id)?->id)->toBe($this->cai->id);
});

test('un pedido con devoluciones ya no se corrige; uno sin entregar no recibe devolución', function (): void {
    app(RegistrarDevolucion::class)->ejecutar($this->pedido, TipoDevolucion::Cambio, [$this->lineaUn->id => ['danadas' => 1]], $this->encargado);

    $pendiente = Pedido::query()->create(['bodega_id' => $this->bodega->id, 'cliente_id' => $this->pedido->cliente_id, 'vendedor_id' => $this->vendedor->id, 'fecha' => now()]);
    $linea = $pendiente->items()->create(['producto_id' => $this->jabon->id, 'presentacion_id' => $this->un->id, 'cantidad' => 1]);

    expect(fn () => app(CorregirPedido::class)->ejecutar($this->pedido, [$this->lineaUn->id => 5], $this->encargado, 'SALE MENOS'))
        ->toThrow(ReglaNegocioException::class, 'ya tiene devoluciones')
        ->and(fn () => app(RegistrarDevolucion::class)->ejecutar($pendiente, TipoDevolucion::Cambio, [$linea->id => ['buenas' => 1]], $this->encargado))
        ->toThrow(ReglaNegocioException::class, 'ya entregado');
});

test('bodega registra la devolución desde el pedido y la ve en Devoluciones; el vendedor no', function (): void {
    $this->actingAs($this->encargado);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(ViewPedido::class, ['record' => $this->pedido->getRouteKey()])
        ->assertActionVisible('devolucion')
        ->callAction('devolucion', data: [
            'tipo'   => TipoDevolucion::Credito->value,
            'lineas' => [
                ['id' => $this->lineaUn->id, 'buenas' => 1, 'danadas' => 1],
                ['id' => $this->pedido->items()->where('presentacion_id', $this->cj12->id)->sole()->id, 'buenas' => 0, 'danadas' => 0],
            ],
            'motivo' => 'cliente no lo quiso',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $undoRepeaterFake();

    $devolucion = Devolucion::query()->sole();

    // Con crédito (el campo entrega el enum, no el texto): no queda cambio por entregar.
    expect($devolucion->tipo)->toBe(TipoDevolucion::Credito)
        ->and($devolucion->reposicion()->exists())->toBeFalse()
        ->and($devolucion->items()->count())->toBe(2)
        ->and($devolucion->total)->toBe(23.0)
        ->and($devolucion->user_id)->toBe($this->encargado->id);

    Livewire::test(ListDevoluciones::class)->assertCanSeeTableRecords([$devolucion]);

    $this->get(route('devoluciones.imprimir', $devolucion))->assertOk()->assertSee('CONSTANCIA DE DEVOLUCIÓN')->assertSee('SIN VALOR FISCAL');

    expect($this->vendedor->can('viewAny', Devolucion::class))->toBeFalse()
        ->and($this->vendedor->can('create', Devolucion::class))->toBeFalse();
});

test('lo que se puede devolver de cada línea, para el modal', function (): void {
    app(RegistrarDevolucion::class)->ejecutar($this->pedido, TipoDevolucion::Cambio, [$this->lineaUn->id => ['danadas' => 3]], $this->encargado);

    $lineas = DevolucionAction::lineas($this->pedido);

    // En qué salió cada una, cuánto queda (en unidades) y en qué se anota: la caja, además, en sueltas.
    expect(array_column($lineas, 'id'))->toBe([$this->lineaUn->id, $this->pedido->items()->where('presentacion_id', $this->cj12->id)->sole()->id])
        ->and([$lineas[0]['salio'], $lineas[0]['puede'], $lineas[0]['sigla'], $lineas[0]['caja']])->toBe(['10 UN', 'ya devolvió 3 UN · quedan 7 UN', 'UN', false])
        ->and([$lineas[1]['salio'], $lineas[1]['puede'], $lineas[1]['sigla'], $lineas[1]['base'], $lineas[1]['caja']])->toBe(['1 CJ12', 'son 12 UN', 'CJ12', 'UN', true]);
});

test('de una caja se devuelven unidades sueltas: se abonan y cuestan por unidad y no pasan de lo que traía la caja', function (): void {
    $caja = $this->pedido->items()->where('presentacion_id', $this->cj12->id)->sole();
    $antes = Existencia::de($this->bodega->id, $this->un->id);

    $devolucion = app(RegistrarDevolucion::class)->ejecutar($this->pedido, TipoDevolucion::Credito, [$caja->id => ['buenas_un' => 2, 'danadas_un' => 3]], $this->encargado);

    // 2 buenas regresan sueltas a la bodega; las 3 dañadas, a costo de unidad, son pérdida.
    expect($devolucion->items->map(fn (DevolucionItem $item): array => [$item->presentacion_id, $item->cantidad, $item->danado, $item->precio, $item->subtotal, $item->costo])->all())
        ->toBe([[$this->un->id, 2, false, 10.0, 20.0, 8.0], [$this->un->id, 3, true, 10.0, 30.0, 8.0]])
        ->and($devolucion->total)->toBe(57.5)
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe($antes + 2)
        ->and(RegistrarDevolucion::devueltas($this->pedido)[$caja->id])->toBe(5)
        ->and(fn () => app(RegistrarDevolucion::class)->ejecutar($this->pedido, TipoDevolucion::Credito, [$caja->id => ['buenas' => 1]], $this->encargado))
        ->toThrow(ReglaNegocioException::class, 'hasta 7 unidades (salió 1 caja de 12 y ya devolvió 5 unidades)');

    // En una línea en unidades, lo suelto es lo mismo.
    $otra = app(RegistrarDevolucion::class)->ejecutar($this->pedido, TipoDevolucion::Credito, [$this->lineaUn->id => ['buenas_un' => 1]], $this->encargado);

    expect($otra->items->sole()->cantidad)->toBe(1);
});

test('el formulario dice en qué salió cada producto y la caja se anota en cajas o en unidades sueltas', function (): void {
    $this->actingAs($this->encargado);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(ViewPedido::class, ['record' => $this->pedido->getRouteKey()])
        ->mountAction('devolucion')
        ->assertMountedActionModalSee(['1. ¿Qué pasó?', '2. ¿Qué regresó?', '3. ¿Qué se le da?', '10 UN', '1 CJ12', 'son 12 UN', 'Buenas: vuelven a la bodega'])
        ->fillForm([
            'tipo'   => TipoDevolucion::Credito->value,
            'lineas' => [1 => ['danadas_un' => 3]],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified();

    $undoRepeaterFake();

    expect(Devolucion::query()->sole()->items->map(fn (DevolucionItem $item): array => [$item->presentacion_id, $item->cantidad, $item->danado])->all())
        ->toBe([[$this->un->id, 3, true]]);
});
