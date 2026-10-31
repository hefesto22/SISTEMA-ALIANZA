<?php

declare(strict_types=1);

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Inventario\AjustarExistencia;
use App\Domain\Inventario\Disponible;
use App\Domain\Inventario\RegistrarCompra;
use App\Domain\Pedidos\CorregirPedido;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarDespacho;
use App\Domain\Precios\ActualizarPrecioProducto;
use App\Enums\EstadoPedido;
use App\Enums\MetodoCosto;
use App\Enums\MotivoCambioPrecio;
use App\Enums\PrecioAlDespachar;
use App\Enums\Rol;
use App\Enums\TipoMovimiento;
use App\Enums\UnidadVenta;
use App\Filament\Pages\BrandingSettingsPage;
use App\Filament\Resources\Compras\Pages\CreateCompra;
use App\Filament\Resources\Compras\Pages\ListCompras;
use App\Filament\Resources\Compras\Pages\ViewCompra;
use App\Filament\Resources\Pedidos\Actions\LineasPedido;
use App\Filament\Resources\Pedidos\Pages\CreatePedido;
use App\Filament\Resources\Productos\Pages\ListProductos;
use App\Models\Bodega;
use App\Models\CambioPrecio;
use App\Models\Cliente;
use App\Models\Compra;
use App\Models\Configuracion;
use App\Models\Existencia;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Database\Seeders\RolesSistemaSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * Inventario por bodega y compras: el costo (promedio o último) y el precio
 * de venta son uno solo para todas las bodegas; la existencia y las compras
 * son de cada bodega. El despacho saca de la bodega del pedido.
 */
beforeEach(function (): void {
    $this->seed(RolesSistemaSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->bodega = Bodega::factory()->create(['codigo' => 'COP']);
    $this->otra = Bodega::factory()->create(['codigo' => 'OCO']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Rol::Administrador->value);

    $this->encargado = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->encargado->assignRole(Rol::Bodega->value);

    $this->vendedor = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->vendedor->assignRole(Rol::Vendedor->value);

    // L 80 la unidad sin ISV con 20 % de margen → L 100. Caja de 12, gravado.
    $this->producto = Producto::factory()->precio(80.0, 100.0)->create(['margen' => 0.20, 'unidades_por_caja' => 12, 'gravado' => true]);
    $this->proveedor = $this->producto->proveedor;
});

/**
 * Pedido pendiente en la bodega con las líneas dadas: [[producto, unidad, cantidad], …].
 *
 * @param list<array{0: Producto, 1: UnidadVenta, 2: int}> $lineas
 */
function pedidoDeInventario(Bodega $bodega, User $vendedor, array $lineas): Pedido
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

/**
 * Lo que trae el buscador de la preventa (con la bodega que despacha, si se da).
 *
 * @return list<array<string, mixed>>
 */
function buscarEnPreventa(string $texto, ?int $bodegaId = null): array
{
    /** @var list<array<string, mixed>> $resultados */
    $resultados = Livewire::test(CreatePedido::class)
        ->fillForm($bodegaId === null ? [] : ['bodega_id' => $bodegaId])
        ->instance()
        ->callSchemaComponentMethod('form.items', 'buscarProductos', ['buscar' => $texto]);

    return $resultados;
}

test('las unidades se muestran en cajas y sueltas', function (): void {
    expect(Existencia::enCajas(29, 12))->toBe('2 CJ + 5 UN')
        ->and(Existencia::enCajas(24, 12))->toBe('2 CJ')
        ->and(Existencia::enCajas(5, 12))->toBe('5 UN')
        ->and(Existencia::enCajas(7, 1))->toBe('7 UN')
        ->and(Existencia::enCajas(-15, 12))->toBe('-1 CJ + 3 UN')
        ->and(Existencia::enCajas(0, 12))->toBe('0');
});

test('costo promedio: mezcla lo que hay en todas las bodegas con lo que entra, y el precio sube con el margen', function (): void {
    // Ya hay 24 a L 80 en Santa Rosa; entran 24 a L 100 en Ocotepeque.
    app(AjustarExistencia::class)->ejecutar($this->bodega->id, $this->producto, 24, $this->admin, 'conteo inicial');

    // 2 cajas a L 1,380 c/u con ISV = 1,380 ÷ 1.15 ÷ 12 = L 100 la unidad sin ISV.
    $compra = app(RegistrarCompra::class)->ejecutar($this->otra, $this->proveedor, [
        ['producto_id' => $this->producto->id, 'unidad' => 'caja', 'cantidad' => 2, 'costo' => 1380.0],
    ], $this->admin, 'A-0001');

    $producto = $this->producto->fresh();
    $item = $compra->items()->sole();

    // (24 × 80 + 24 × 100) ÷ 48 = 90 → 90 ÷ (1 − 20 %) = 112.50
    expect($producto?->costo_unidad)->toBe(90.0)
        ->and($producto?->precio_unidad)->toBe(112.5)
        ->and(Existencia::en($this->bodega->id, $this->producto->id))->toBe(24)
        ->and(Existencia::en($this->otra->id, $this->producto->id))->toBe(24)
        ->and($compra->numero)->toBe('COM-'.str_pad((string) $compra->id, 6, '0', STR_PAD_LEFT))
        ->and($compra->total)->toBe(2760.0)
        ->and($compra->subtotal)->toBe(2400.0)
        ->and($compra->isv)->toBe(360.0)
        ->and($item->unidades)->toBe(24)
        ->and($item->costo_unidad)->toBe(100.0)
        ->and($item->costo_anterior)->toBe(80.0)
        ->and($item->costo_nuevo)->toBe(90.0)
        ->and($item->precio_nuevo)->toBe(112.5);

    $cambio = CambioPrecio::query()->latest('id')->firstOrFail();
    $entrada = MovimientoInventario::query()->where('compra_id', $compra->id)->sole();

    expect($cambio->motivo)->toBe(MotivoCambioPrecio::Compra)
        ->and($cambio->nota)->toContain((string) $compra->numero)
        ->and($entrada->tipo)->toBe(TipoMovimiento::Compra)
        ->and($entrada->bodega_id)->toBe($this->otra->id)
        ->and($entrada->cantidad)->toBe(24)
        ->and($entrada->existencia)->toBe(24);
});

test('último costo: toma el costo de la compra', function (): void {
    Configuracion::actual()->update(['metodo_costo' => MetodoCosto::Ultimo]);
    app(AjustarExistencia::class)->ejecutar($this->bodega->id, $this->producto, 24, $this->admin);

    app(RegistrarCompra::class)->ejecutar($this->bodega, $this->proveedor, [
        ['producto_id' => $this->producto->id, 'unidad' => 'caja', 'cantidad' => 1, 'costo' => 1380.0],
    ], $this->admin);

    expect($this->producto->fresh()?->costo_unidad)->toBe(100.0)
        ->and($this->producto->fresh()?->precio_unidad)->toBe(125.0)
        ->and(Existencia::en($this->bodega->id, $this->producto->id))->toBe(36);
});

test('con promedio y sin existencia, el costo es el de la compra; lo exento entra sin ISV', function (): void {
    $exento = Producto::factory()->precio(40.0, 50.0)->create([
        'categoria_id'      => $this->producto->categoria_id,
        'margen'            => 0.20,
        'unidades_por_caja' => 1,
        'gravado'           => false,
    ]);

    $compra = app(RegistrarCompra::class)->ejecutar($this->bodega, $this->proveedor, [
        ['producto_id' => $this->producto->id, 'unidad' => 'unidad', 'cantidad' => 10, 'costo' => 115.0],
        ['producto_id' => $exento->id, 'unidad' => 'unidad', 'cantidad' => 10, 'costo' => 48.0],
    ], $this->admin);

    // 115 con ISV = 100 sin ISV; el exento queda en 48.
    expect($this->producto->fresh()?->costo_unidad)->toBe(100.0)
        ->and($exento->fresh()?->costo_unidad)->toBe(48.0)
        ->and($exento->fresh()?->precio_unidad)->toBe(60.0)
        ->and($compra->subtotal)->toBe(1480.0)
        ->and($compra->isv)->toBe(150.0)
        ->and($compra->total)->toBe(1630.0);
});

test('no entra un producto de otro proveedor: no se guarda nada', function (): void {
    $ajeno = Producto::factory()->create();

    expect(fn () => app(RegistrarCompra::class)->ejecutar($this->bodega, $this->proveedor, [
        ['producto_id' => $this->producto->id, 'unidad' => 'caja', 'cantidad' => 1, 'costo' => 1380.0],
        ['producto_id' => $ajeno->id, 'unidad' => 'caja', 'cantidad' => 1, 'costo' => 100.0],
    ], $this->admin))->toThrow(ReglaNegocioException::class);

    expect(Compra::query()->count())->toBe(0)
        ->and(Existencia::en($this->bodega->id, $this->producto->id))->toBe(0)
        ->and($this->producto->fresh()?->costo_unidad)->toBe(80.0);
});

test('el despacho saca de la bodega del pedido; sin controlar la existencia puede quedar en negativo', function (): void {
    $pedido = pedidoDeInventario($this->bodega, $this->vendedor, [
        [$this->producto, UnidadVenta::Caja, 1],
        [$this->producto, UnidadVenta::Unidad, 3],
    ]);

    app(ValidarDespacho::class)->ejecutar($pedido, [], $this->encargado);

    $salida = MovimientoInventario::query()->where('pedido_id', $pedido->id)->sole();

    expect(Existencia::en($this->bodega->id, $this->producto->id))->toBe(-15)
        ->and(Existencia::en($this->otra->id, $this->producto->id))->toBe(0)
        ->and($salida->tipo)->toBe(TipoMovimiento::Despacho)
        ->and($salida->cantidad)->toBe(-15);
});

test('controlando la existencia no se despacha más de lo que hay; con lo que alcanza sí', function (): void {
    Configuracion::actual()->update(['controlar_existencias' => true]);
    app(AjustarExistencia::class)->ejecutar($this->bodega->id, $this->producto, 15, $this->admin);

    $pedido = pedidoDeInventario($this->bodega, $this->vendedor, [
        [$this->producto, UnidadVenta::Caja, 2],
        [$this->producto, UnidadVenta::Unidad, 5],
    ]);
    [$caja, $sueltas] = $pedido->items()->orderBy('id')->get()->all();

    try {
        app(ValidarDespacho::class)->ejecutar($pedido, [], $this->encargado);
        $aviso = null;
    } catch (ReglaNegocioException $e) {
        $aviso = $e->getMessage();
    }

    expect($aviso)->toContain('No alcanza')
        ->and($pedido->fresh()?->estado)->toBe(EstadoPedido::Pendiente)
        ->and(Existencia::en($this->bodega->id, $this->producto->id))->toBe(15);

    // El modal ya trae lo que alcanza: 1 caja (12) y 3 sueltas.
    $filas = LineasPedido::datos($pedido, segunExistencia: true)['items'];

    expect($filas[0]['sale'])->toBe(1)
        ->and($filas[0]['hay'])->toBe('1 CJ + 3 UN')
        ->and($filas[1]['sale'])->toBe(3);

    app(ValidarDespacho::class)->ejecutar($pedido, [$caja->id => 1, $sueltas->id => 3], $this->encargado);

    expect(Existencia::en($this->bodega->id, $this->producto->id))->toBe(0)
        ->and($pedido->fresh()?->estado)->toBe(EstadoPedido::PorImprimir);
});

test('al corregir un pedido despachado regresa a la bodega lo que ya no sale', function (): void {
    app(AjustarExistencia::class)->ejecutar($this->bodega->id, $this->producto, 100, $this->admin);

    $pedido = pedidoDeInventario($this->bodega, $this->vendedor, [[$this->producto, UnidadVenta::Caja, 2]]);
    $linea = $pedido->items()->sole();

    app(ValidarDespacho::class)->ejecutar($pedido, [], $this->encargado);
    expect(Existencia::en($this->bodega->id, $this->producto->id))->toBe(76);

    app(CorregirPedido::class)->ejecutar($pedido, [$linea->id => 1], $this->encargado, 'devolvió una caja');

    $regreso = MovimientoInventario::query()->where('pedido_id', $pedido->id)->where('tipo', TipoMovimiento::Correccion)->sole();

    expect(Existencia::en($this->bodega->id, $this->producto->id))->toBe(88)
        ->and($regreso->cantidad)->toBe(12);
});

test('precio del pedido: se respeta lo que se le dijo al cliente, salvo que quede bajo el costo', function (): void {
    $cambiar = fn (float $costo) => app(ActualizarPrecioProducto::class)->ejecutar($this->producto->fresh() ?? $this->producto, $costo, MotivoCambioPrecio::Manual);

    // Por defecto se cobra el precio del día que se despacha.
    $hoy = pedidoDeInventario($this->bodega, $this->vendedor, [[$this->producto, UnidadVenta::Unidad, 1]]);
    $cambiar(90.0); // precio 112.50
    app(ValidarDespacho::class)->ejecutar($hoy, [], $this->encargado);
    expect($hoy->items()->sole()->precio)->toBe(112.5);

    Configuracion::actual()->update(['precio_al_despachar' => PrecioAlDespachar::Pedido]);

    // Pedido a L 112.50; el costo sube a 100 (precio 125): se respeta 112.50.
    $respetado = pedidoDeInventario($this->bodega, $this->vendedor, [[$this->producto, UnidadVenta::Unidad, 1]]);
    $cambiar(100.0);
    app(ValidarDespacho::class)->ejecutar($respetado, [], $this->encargado);
    expect($respetado->items()->sole()->precio)->toBe(112.5);

    // Pedido a L 125; el costo sube a 130 (precio 162.50): 125 ya es pérdida, se cobra 162.50.
    $subio = pedidoDeInventario($this->bodega, $this->vendedor, [[$this->producto, UnidadVenta::Unidad, 1]]);
    $cambiar(130.0);
    app(ValidarDespacho::class)->ejecutar($subio, [], $this->encargado);
    expect($subio->items()->sole()->precio)->toBe(162.5);
});

test('el encargado registra una compra para su bodega desde el panel', function (): void {
    $this->actingAs($this->encargado);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(CreateCompra::class)
        ->assertFormFieldHidden('bodega_id')
        ->fillForm([
            'proveedor_id'      => $this->proveedor->id,
            'factura_proveedor' => '000-001-01-00000077',
            'fecha'             => now()->toDateString(),
            'lineas'            => [
                ['producto_id' => $this->producto->id, 'unidad' => 'caja', 'cantidad' => 2, 'costo' => 1380],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $undoRepeaterFake();

    $compra = Compra::query()->sole();

    expect($compra->bodega_id)->toBe($this->bodega->id)
        ->and($compra->user_id)->toBe($this->encargado->id)
        ->and($compra->factura_proveedor)->toBe('000-001-01-00000077')
        ->and($compra->total)->toBe(2760.0)
        ->and(Existencia::en($this->bodega->id, $this->producto->id))->toBe(24);

    Livewire::test(ListCompras::class)->assertCanSeeTableRecords([$compra]);
    Livewire::test(ViewCompra::class, ['record' => $compra->getRouteKey()])->assertOk();
});

test('cada bodega ve sus compras; el vendedor no entra a compras', function (): void {
    $deOtra = app(RegistrarCompra::class)->ejecutar($this->otra, $this->proveedor, [
        ['producto_id' => $this->producto->id, 'unidad' => 'caja', 'cantidad' => 1, 'costo' => 1380.0],
    ], $this->admin);
    $mia = app(RegistrarCompra::class)->ejecutar($this->bodega, $this->proveedor, [
        ['producto_id' => $this->producto->id, 'unidad' => 'caja', 'cantidad' => 1, 'costo' => 1380.0],
    ], $this->admin);

    expect($this->encargado->can('view', $mia))->toBeTrue()
        ->and($this->encargado->can('view', $deOtra))->toBeFalse()
        ->and($this->admin->can('view', $deOtra))->toBeTrue()
        ->and($this->vendedor->can('viewAny', Compra::class))->toBeFalse()
        ->and($this->encargado->can('update', $mia))->toBeFalse();

    $this->actingAs($this->encargado);

    Livewire::test(ListCompras::class)
        ->assertCanSeeTableRecords([$mia])
        ->assertCanNotSeeTableRecords([$deOtra]);
});

test('el encargado ajusta la existencia de su bodega por conteo; el vendedor no puede', function (): void {
    $this->actingAs($this->encargado);

    Livewire::test(ListProductos::class)
        ->callTableAction('ajustarExistencia', $this->producto, data: ['cajas' => 2, 'unidades' => 5, 'nota' => 'conteo inicial'])
        ->assertHasNoTableActionErrors();

    $ajuste = MovimientoInventario::query()->sole();

    expect(Existencia::en($this->bodega->id, $this->producto->id))->toBe(29)
        ->and($ajuste->tipo)->toBe(TipoMovimiento::Ajuste)
        ->and($ajuste->cantidad)->toBe(29)
        ->and($ajuste->nota)->toBe('CONTEO INICIAL')
        ->and($this->producto->fresh()?->costo_unidad)->toBe(80.0);

    $this->actingAs($this->vendedor);

    Livewire::test(ListProductos::class)
        ->assertTableActionHidden('ajustarExistencia', $this->producto);
});

test('el super admin escoge en Configuración el costo, el precio al despachar y el control de existencia', function (): void {
    Cache::flush();

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(Utils::getSuperAdminName());
    $this->actingAs($superAdmin);

    Livewire::test(BrandingSettingsPage::class)
        ->assertOk()
        ->fillForm([
            'metodo_costo'          => MetodoCosto::Ultimo->value,
            'precio_al_despachar'   => PrecioAlDespachar::Pedido->value,
            'controlar_existencias' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $configuracion = Configuracion::query()->sole();

    expect($configuracion->metodo_costo)->toBe(MetodoCosto::Ultimo)
        ->and($configuracion->precio_al_despachar)->toBe(PrecioAlDespachar::Pedido)
        ->and($configuracion->controlar_existencias)->toBeTrue();
});

test('el buscador de la preventa dice cuánto hay y pone primero lo que tiene; controlando la existencia, solo eso', function (): void {
    Configuracion::actual()->update(['controlar_existencias' => true]);

    $this->producto->forceFill(['descripcion' => 'JABON A TOCADOR 12X100G'])->save();
    $sinExistencia = Producto::factory()->create(['descripcion' => 'JABON B SIN EXISTENCIA']);
    $enLaOtra = Producto::factory()->create(['descripcion' => 'JABON C EN LA OTRA BODEGA']);

    app(AjustarExistencia::class)->ejecutar($this->bodega->id, $this->producto, 29, $this->admin);
    app(AjustarExistencia::class)->ejecutar($this->otra->id, $enLaOtra, 10, $this->admin);

    // El vendedor: su bodega.
    $this->actingAs($this->vendedor);
    $suyos = buscarEnPreventa('jabon');

    expect(array_column($suyos, 'id'))->toBe([$this->producto->id])
        ->and($suyos[0]['hay'])->toBe(29);

    // Administración: lo de la bodega que escogió; sin bodega, nada.
    $this->actingAs($this->admin);

    expect(buscarEnPreventa('jabon'))->toBe([])
        ->and(array_column(buscarEnPreventa('jabon', $this->otra->id), 'id'))->toBe([$enLaOtra->id]);

    // Sin controlar la existencia salen todos: primero los que tienen.
    Configuracion::actual()->update(['controlar_existencias' => false]);
    $this->actingAs($this->vendedor);
    $todos = buscarEnPreventa('jabon');

    expect(array_column($todos, 'id'))->toBe([$this->producto->id, $sinExistencia->id, $enLaOtra->id])
        ->and(array_column($todos, 'hay'))->toBe([29, 0, 0]);

    // Administración sin bodega escogida: lo de todas las bodegas.
    $this->actingAs($this->admin);
    $deTodas = buscarEnPreventa('jabon');

    expect(array_column($deTodas, 'id'))->toBe([$this->producto->id, $enLaOtra->id, $sinExistencia->id])
        ->and(array_column($deTodas, 'hay'))->toBe([29, 10, 0]);
});

test('el vendedor ve lo disponible (lo de la bodega menos lo apartado en pedidos pendientes); al validar se ve lo que queda y qué no va completo', function (): void {
    $this->producto->forceFill(['descripcion' => 'JABON A TOCADOR 12X100G'])->save();
    app(AjustarExistencia::class)->ejecutar($this->bodega->id, $this->producto, 29, $this->admin);

    // Un pedido pendiente ya aparta 2 cajas (24 unidades): quedan 5 disponibles.
    $apartado = pedidoDeInventario($this->bodega, $this->vendedor, [[$this->producto, UnidadVenta::Caja, 2]]);

    expect(Disponible::en($this->bodega->id, [$this->producto->id]))->toBe([$this->producto->id => 5])
        ->and(Disponible::en($this->bodega->id, [$this->producto->id], sinPedido: $apartado->id))->toBe([$this->producto->id => 29])
        ->and(Disponible::en($this->otra->id, [$this->producto->id]))->toBe([$this->producto->id => 0]);

    $this->actingAs($this->vendedor);
    $resultados = buscarEnPreventa('jabon');

    expect($resultados[0]['hay'])->toBe(5)
        ->and($resultados[0]['en_bodega'])->toBe(29)
        ->and($resultados[0]['apartado'])->toBe(24);

    // Controlando la existencia no se guarda más de lo disponible (1 caja = 12 > 5).
    Configuracion::actual()->update(['controlar_existencias' => true]);
    $this->actingAs($this->encargado);

    Livewire::test(CreatePedido::class)
        ->fillForm([
            'cliente_id'  => Cliente::factory()->create(['bodega_id' => $this->bodega->id])->id,
            'vendedor_id' => $this->vendedor->id,
            'fecha'       => now()->toDateString(),
            'items'       => [['producto_id' => $this->producto->id, 'unidad' => 'caja', 'cantidad' => 1]],
        ])
        ->call('create')
        ->assertHasFormErrors(['items']);

    expect(Pedido::query()->count())->toBe(1);

    // Al validar el que apartó: hay 29 en la bodega y este pedido saca 2 cajas.
    $fila = LineasPedido::datos($apartado)['items'][0];

    expect($fila['hay_unidades'])->toBe(29)
        ->and($fila['es_caja'])->toBeTrue()
        ->and($fila['por_unidad'])->toBe(12)
        ->and($fila['mueve'])->toBeTrue()
        ->and($fila['salio_unidades'])->toBe(0);

    // Bodega manda 1 de las 2 cajas: queda dicho que fue la caja la que no va completa.
    $validado = app(ValidarDespacho::class)->ejecutar($apartado, [$apartado->items()->sole()->id => 1], $this->encargado);

    expect($validado->items()->sole()->faltaEnPalabras())->toBe('No va completo: falta 1 caja de 2')
        ->and(LineasPedido::incompletas($validado))->toBe('No va completo: JABON A TOCADOR 12X100G (falta 1 caja de 2).')
        ->and(Disponible::en($this->bodega->id, [$this->producto->id]))->toBe([$this->producto->id => 17]);

    // Al corregirlo, lo que ya salió (1 caja) es lo que regresaría.
    expect(LineasPedido::datos($validado)['items'][0]['salio_unidades'])->toBe(12);
});
