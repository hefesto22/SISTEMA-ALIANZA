<?php

declare(strict_types=1);

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Pedidos\CorregirPedido;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarDespacho;
use App\Domain\Precios\ActualizarPrecioProducto;
use App\Enums\EstadoFactura;
use App\Enums\EstadoPedido;
use App\Enums\ModoImpresion;
use App\Enums\MotivoCambioPrecio;
use App\Enums\Rol;
use App\Enums\UnidadVenta;
use App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction;
use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Filament\Resources\Pedidos\Pages\ViewPedido;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Factura;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Database\Seeders\RolesSistemaSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * Imprimir: la acción (en el servidor) emite la factura y cuenta la
 * impresión; la hoja (GET) solo muestra. Con enlace firmado sale ORIGINAL;
 * sin firma, COPIA · SIN VALOR FISCAL. "No salió" repite la hoja sin
 * contarla como copia. Corregir un impreso anula su factura y al
 * reimprimir sale una nueva que la sustituye.
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

    facturacionLista();
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

test('el botón Imprimir emite y cuenta; la hoja firmada sale original y sin firma sale copia sin valor fiscal', function (): void {
    $pedido = app(ValidarDespacho::class)->ejecutar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento), [], $this->encargado);

    expect($pedido->estado)->toBe(EstadoPedido::PorImprimir)
        ->and($pedido->impresiones)->toBe(0);

    $this->actingAs($this->encargado);

    $url = null;

    Livewire::test(ViewPedido::class, ['record' => $pedido->getRouteKey()])
        ->assertActionVisible('imprimir')
        ->callAction('imprimir')
        ->assertDispatched(ImprimirPedidoAction::EVENTO, function (string $nombre, array $datos) use (&$url): bool {
            $url = $datos['url'] ?? null;

            return is_string($url) && str_contains($url, 'signature=');
        });

    $factura = Factura::query()->where('pedido_id', $pedido->id)->sole();

    expect($pedido->fresh()?->estado)->toBe(EstadoPedido::Impreso)
        ->and($pedido->fresh()?->impresiones)->toBe(1)
        ->and($pedido->fresh()?->impreso_por)->toBe($this->encargado->id)
        ->and($factura->impresiones)->toBe(1);

    // La hoja firmada: original, sin sello.
    $this->get((string) $url)
        ->assertOk()
        ->assertSee($factura->numero)
        ->assertDontSee('REIMPRESIÓN')
        ->assertDontSee('SIN VALOR FISCAL');

    // Abrir la hoja no cambia nada; sin firma (o con la firma alterada) sale como copia.
    $this->get(route('pedidos.imprimir', $pedido))->assertOk()->assertSee('COPIA · SIN VALOR FISCAL');
    $this->get(route('pedidos.imprimir-lote', ['ids' => $pedido->id, 'originales' => $pedido->id]))
        ->assertOk()
        ->assertSee('COPIA · SIN VALOR FISCAL');
    $this->get(str_replace('originales='.$pedido->id, 'originales=0', (string) $url))->assertOk()->assertSee('COPIA · SIN VALOR FISCAL');

    expect($pedido->fresh()?->impresiones)->toBe(1)
        ->and($factura->fresh()?->impresiones)->toBe(1)
        ->and(Factura::query()->count())->toBe(1);
});

test('copia sale como reimpresión; "No salió" repite la hoja igual sin contarla como copia', function (): void {
    $pedido = app(ValidarDespacho::class)->ejecutar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento), [], $this->encargado);

    $this->actingAs($this->encargado);

    imprimir($pedido, $this->encargado, ModoImpresion::Originales);

    // La impresora se atascó: sale otra vez el original.
    $otraVez = imprimir($pedido, $this->encargado, ModoImpresion::NoSalio);
    $this->get($otraVez->url())->assertOk()->assertDontSee('REIMPRESIÓN');

    $factura = Factura::query()->where('pedido_id', $pedido->id)->sole();

    expect($otraVez->originales)->toBe([$pedido->id])
        ->and($factura->impresiones)->toBe(1)
        ->and($factura->reintentos)->toBe(1)
        ->and($factura->reintento_por)->toBe($this->encargado->id);

    $copia = imprimir($pedido, $this->encargado, ModoImpresion::Copia);
    $this->get($copia->url())->assertOk()->assertSee('REIMPRESIÓN · COPIA');

    // Si la copia no salió, sale otra copia (no un original).
    $copiaOtraVez = imprimir($pedido, $this->encargado, ModoImpresion::NoSalio);
    $this->get($copiaOtraVez->url())->assertOk()->assertSee('REIMPRESIÓN · COPIA');

    expect($factura->fresh()?->impresiones)->toBe(2)
        ->and($factura->fresh()?->reintentos)->toBe(2)
        ->and($pedido->fresh()?->impresiones)->toBe(2)
        ->and(Factura::query()->count())->toBe(1);
});

test('nunca salen dos originales: si otra persona ya lo imprimió, se salta', function (): void {
    $pedido = app(ValidarDespacho::class)->ejecutar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento), [], $this->encargado);

    $otro = User::factory()->create(['bodega_id' => $this->bodega->id, 'name' => 'ANA']);
    $otro->assignRole(Rol::Bodega->value);

    imprimir($pedido, $otro, ModoImpresion::Originales);
    $segundo = imprimir($pedido, $this->encargado, ModoImpresion::Originales);

    expect($segundo->hayQueImprimir())->toBeFalse()
        ->and($segundo->saltados[0] ?? '')->toContain('ya lo imprimió ANA')
        ->and(Factura::query()->count())->toBe(1)
        ->and(Factura::query()->sole()->impresiones)->toBe(1);
});

test('si otra persona está imprimiendo el mismo pedido, avisa y no imprime nada', function (): void {
    $pedido = app(ValidarDespacho::class)->ejecutar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento), [], $this->encargado);

    // Alguien (en esta u otra bodega) lo tiene tomado en este momento.
    $candado = Cache::lock("imprimir:pedido:{$pedido->id}", 120);
    expect($candado->get())->toBeTrue();

    expect(fn () => imprimir($pedido, $this->encargado, ModoImpresion::Originales))
        ->toThrow(ReglaNegocioException::class, 'Otra persona está imprimiendo');

    expect(Factura::query()->count())->toBe(0)
        ->and($pedido->fresh()?->estado)->toBe(EstadoPedido::PorImprimir);

    $candado->release();

    expect(imprimir($pedido, $this->encargado, ModoImpresion::Originales)->hayQueImprimir())->toBeTrue()
        ->and(Factura::query()->count())->toBe(1);
});

test('en un lote, el que otra persona está imprimiendo se salta y los demás salen', function (): void {
    $tomado = app(ValidarDespacho::class)->ejecutar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento), [], $this->encargado);
    $libre = app(ValidarDespacho::class)->ejecutar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento), [], $this->encargado);

    $candado = Cache::lock("imprimir:pedido:{$tomado->id}", 120);
    expect($candado->get())->toBeTrue();

    $resultado = imprimir([$tomado, $libre], $this->encargado, ModoImpresion::Originales);

    $candado->release();

    expect($resultado->pedidos)->toBe([$libre->id])
        ->and($resultado->saltados)->toBe(["{$tomado->numero}: lo está imprimiendo otra persona en este momento"])
        ->and($tomado->fresh()?->estado)->toBe(EstadoPedido::PorImprimir)
        ->and($libre->fresh()?->estado)->toBe(EstadoPedido::Impreso)
        ->and(Factura::query()->count())->toBe(1);
});

test('ya validado, el vendedor no ve el pedido ni su hoja y no lo imprime; la hoja de un pendiente es vista previa', function (): void {
    $pendiente = pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento);
    $validado = app(ValidarDespacho::class)->ejecutar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento), [], $this->encargado);

    $this->actingAs($this->vendedor);

    expect($this->get(PedidoResource::getUrl('view', ['record' => $validado]))->status())->toBeIn([403, 404])
        ->and($this->get(route('pedidos.imprimir', $validado))->status())->toBeIn([403, 404]);

    $intento = imprimir($validado, $this->vendedor, ModoImpresion::Seleccionados);

    expect($intento->hayQueImprimir())->toBeFalse()
        ->and(Factura::query()->count())->toBe(0)
        ->and($validado->fresh()?->estado)->toBe(EstadoPedido::PorImprimir);

    // Ya impreso por bodega, tampoco.
    imprimir($validado, $this->encargado, ModoImpresion::Originales);
    expect($this->get(route('pedidos.imprimir', $validado))->status())->toBeIn([403, 404]);

    // Su pendiente sí: la hoja sale como vista previa.
    $this->get(PedidoResource::getUrl('view', ['record' => $pendiente]))->assertOk();
    $this->get(route('pedidos.imprimir', $pendiente))
        ->assertOk()
        ->assertSee('vista previa');

    expect($pendiente->fresh()?->estado)->toBe(EstadoPedido::Pendiente);
});

test('imprimir todos: solo los Por imprimir de su bodega, uno por página', function (): void {
    $validar = fn (Pedido $pedido): Pedido => app(ValidarDespacho::class)->ejecutar($pedido, [], $this->encargado);

    $primero = $validar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento));
    $segundo = $validar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento));
    $pendiente = pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento);

    $otraBodega = Bodega::factory()->create(['codigo' => 'OCO']);
    $otroVendedor = User::factory()->create(['bodega_id' => $otraBodega->id]);
    $otroVendedor->assignRole(Rol::Vendedor->value);
    $deOtra = pedidoParaImprimir($otraBodega, $otroVendedor, $this->gravado, $this->exento);
    $deOtra->forceFill(['estado' => EstadoPedido::PorImprimir, 'validado_at' => now()])->save();

    $resultado = imprimir([$primero, $segundo, $pendiente, $deOtra], $this->encargado, ModoImpresion::Originales);

    expect($resultado->pedidos)->toBe([$primero->id, $segundo->id])
        ->and($resultado->saltados)->toHaveCount(2);

    $this->actingAs($this->encargado)
        ->get($resultado->url())
        ->assertOk()
        ->assertSee((string) $primero->numero)
        ->assertSee((string) $segundo->numero)
        ->assertDontSee((string) $pendiente->numero)
        ->assertDontSee((string) $deOtra->numero);

    expect($primero->fresh()?->estado)->toBe(EstadoPedido::Impreso)
        ->and($segundo->fresh()?->estado)->toBe(EstadoPedido::Impreso)
        ->and($pendiente->fresh()?->estado)->toBe(EstadoPedido::Pendiente)
        ->and($deOtra->fresh()?->estado)->toBe(EstadoPedido::PorImprimir);

    $this->get(route('pedidos.imprimir-lote', ['ids' => (string) $deOtra->id]))->assertNotFound();
});

test('el botón Imprimir todos pide confirmación e imprime los que se ven', function (): void {
    $primero = app(ValidarDespacho::class)->ejecutar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento), [], $this->encargado);
    $segundo = app(ValidarDespacho::class)->ejecutar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento), [], $this->encargado);

    $this->actingAs($this->encargado);

    Livewire::test(ListPedidos::class)
        ->assertActionHidden('imprimirTodos')
        ->set('activeTab', EstadoPedido::PorImprimir->value)
        ->assertCanSeeTableRecords([$primero, $segundo])
        ->assertActionVisible('imprimirTodos')
        ->assertActionHasLabel('imprimirTodos', 'Imprimir todos (2)')
        ->callAction('imprimirTodos')
        ->assertDispatched(ImprimirPedidoAction::EVENTO);

    expect($primero->fresh()?->estado)->toBe(EstadoPedido::Impreso)
        ->and($segundo->fresh()?->estado)->toBe(EstadoPedido::Impreso)
        ->and(Factura::query()->count())->toBe(2);

    // El vendedor no imprime: no le sale.
    $this->actingAs($this->vendedor);

    Livewire::test(ListPedidos::class)
        ->set('activeTab', EstadoPedido::PorImprimir->value)
        ->assertActionHidden('imprimirTodos');
});

test('se marcan varias y se imprimen de un solo: las por imprimir salen original, las impresas copia', function (): void {
    $validar = fn (): Pedido => app(ValidarDespacho::class)->ejecutar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento), [], $this->encargado);

    $impreso = $validar();
    imprimir($impreso, $this->encargado, ModoImpresion::Originales);
    $porImprimir = $validar();
    $pendiente = pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento);

    $this->actingAs($this->encargado);

    $url = null;

    Livewire::test(ListPedidos::class)
        ->assertTableBulkActionHidden('imprimirSeleccionados')
        ->set('activeTab', 'todos')
        ->assertTableBulkActionVisible('imprimirSeleccionados')
        ->callTableBulkAction('imprimirSeleccionados', [$impreso, $porImprimir, $pendiente])
        ->assertDispatched(ImprimirPedidoAction::EVENTO, function (string $nombre, array $datos) use (&$url): bool {
            $url = $datos['url'] ?? null;

            return is_string($url);
        });

    $this->get((string) $url)->assertOk()->assertSee('REIMPRESIÓN · COPIA');

    expect(Factura::query()->where('pedido_id', $impreso->id)->sole()->impresiones)->toBe(2)
        ->and(Factura::query()->where('pedido_id', $porImprimir->id)->sole()->impresiones)->toBe(1)
        ->and($pendiente->fresh()?->estado)->toBe(EstadoPedido::Pendiente);

    // El vendedor no imprime: no puede marcar.
    $this->actingAs($this->vendedor);

    Livewire::test(ListPedidos::class)
        ->set('activeTab', 'todos')
        ->assertTableBulkActionHidden('imprimirSeleccionados');
});

test('corregir un pedido impreso: anula la factura, vuelve a por imprimir y al reimprimir sale una nueva, con los precios de cuando se validó', function (): void {
    $pedido = app(ValidarDespacho::class)->ejecutar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento), [], $this->encargado);

    imprimir($pedido, $this->encargado, ModoImpresion::Originales);

    // Sube el precio después de validado: la corrección no lo cambia.
    app(ActualizarPrecioProducto::class)->ejecutar($this->gravado, 80.0, MotivoCambioPrecio::Manual, precioUnidad: 110.0);

    $this->actingAs($this->encargado);
    $undoRepeaterFake = Repeater::fake();

    // Avisan que del gravado solo salió 1.
    Livewire::test(ViewPedido::class, ['record' => $pedido->getRouteKey()])
        ->assertActionVisible('corregir')
        ->callAction('corregir', data: ['items' => [['sale' => 1], ['sale' => 1]], 'motivo' => 'el cliente devolvió uno'])
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

    $anulada = Factura::query()->where('pedido_id', $pedido?->id)->sole();

    /** @var Pedido $pedido */
    $nueva = imprimir($pedido, $this->encargado, ModoImpresion::Originales);

    $this->get($nueva->url())
        ->assertOk()
        ->assertSee('Sustituye a la factura N° '.$anulada->numero)
        ->assertDontSee('REIMPRESIÓN');

    $facturas = $pedido->facturas()->get();

    expect($pedido->fresh()?->estado)->toBe(EstadoPedido::Impreso)
        ->and($pedido->fresh()?->impresiones)->toBe(2)
        ->and($facturas)->toHaveCount(2)
        ->and($facturas->first()?->estado)->toBe(EstadoFactura::Anulada)
        ->and($facturas->first()?->motivo_anulacion)->toBe('PEDIDO CORREGIDO: EL CLIENTE DEVOLVIÓ UNO')
        ->and($facturas->last()?->estado)->toBe(EstadoFactura::Vigente)
        ->and($facturas->last()?->reemplaza_a_id)->toBe($anulada->id)
        ->and($facturas->last()?->total)->toBe(615.0);
});

test('solo se corrige lo validado, y solo bodega', function (): void {
    $pendiente = pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento);
    $validado = app(ValidarDespacho::class)->ejecutar(pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento), [], $this->encargado);

    $this->actingAs($this->encargado);

    Livewire::test(ViewPedido::class, ['record' => $pendiente->getRouteKey()])
        ->assertActionHidden('corregir')
        ->assertActionVisible('validarDespacho');

    // El vendedor ya ni lo ve.
    expect($this->vendedor->can('view', $validado))->toBeFalse()
        ->and($this->vendedor->can('corregir', $validado))->toBeFalse()
        ->and($this->vendedor->can('imprimir', $validado))->toBeFalse()
        ->and($this->encargado->can('corregir', $validado))->toBeTrue();

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

test('ver pedido: resumen con el cliente y el total, cada producto con lo que sale y lo que no salió, y los totales', function (): void {
    $pedido = pedidoParaImprimir($this->bodega, $this->vendedor, $this->gravado, $this->exento);
    $pedido->cliente->update(['telefono' => '99887766']);
    $pedido->update(['notas' => 'Cobrar en efectivo']);
    $lineas = $pedido->items()->orderBy('id')->pluck('id')->all();

    $this->actingAs($this->encargado);

    Livewire::test(ViewPedido::class, ['record' => $pedido->getRouteKey()])
        ->assertSee('Pedido '.$pedido->numero)
        ->assertSee($pedido->cliente->nombre)
        ->assertSee('9988-7766')
        ->assertSee('Pendiente')
        ->assertSee('Sin validar')
        ->assertSee('COBRAR EN EFECTIVO')
        ->assertSee('2 UN')
        ->assertSee('1 CJ')
        ->assertSee('L. 100.00 c/u + ISV')
        ->assertSee('L. 500.00 la caja de 10 · exento')
        ->assertSee('L. 730.00')
        ->assertDontSee('no salieron');

    // Del gravado solo sale 1.
    $validado = app(ValidarDespacho::class)->ejecutar($pedido, [$lineas[0] => 1], $this->encargado);

    Livewire::test(ViewPedido::class, ['record' => $validado->getRouteKey()])
        ->assertSee('Por imprimir')
        ->assertSee($this->encargado->name)
        ->assertSee('Sin imprimir')
        ->assertSee('1 UN')
        ->assertSee('Pidió 2 UN · no salieron 1')
        ->assertSee('L. 615.00');
});
