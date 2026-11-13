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
use App\Filament\Pages\BrandingSettingsPage;
use App\Filament\Resources\Compras\Pages\CreateCompra;
use App\Filament\Resources\Compras\Pages\ListCompras;
use App\Filament\Resources\Compras\Pages\ViewCompra;
use App\Filament\Resources\Pedidos\Actions\LineasPedido;
use App\Filament\Resources\Pedidos\Pages\CreatePedido;
use App\Filament\Resources\Productos\Pages\ListProductos;
use App\Filament\Schemas\Components\LineasCompra;
use App\Models\Bodega;
use App\Models\CambioPrecio;
use App\Models\Cliente;
use App\Models\Compra;
use App\Models\Configuracion;
use App\Models\Existencia;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Database\Seeders\RolesSistemaSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * Inventario por bodega y compras: cada presentación tiene su costo
 * (promedio o último) y su precio, los mismos para todas las bodegas, y su
 * existencia en cada bodega. El despacho saca cada presentación de la suya.
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

    // UN: costo L 80 sin ISV, 20 % de margen → L 100. CJ12: costo L 960 → L 1,200. Gravado.
    $this->producto = Producto::factory()->precio(80.0, 100.0)->create(['margen' => 0.20, 'gravado' => true]);
    $this->proveedor = $this->producto->proveedor;
    $this->base = presentacion($this->producto);
    $this->caja = presentacion($this->producto, 12);
});

/**
 * Conteo de una presentación en una bodega (inventario inicial).
 */
function contar(Bodega $bodega, Presentacion $presentacion, int $cantidad, User $usuario): void
{
    app(AjustarExistencia::class)->ejecutar($bodega->id, $presentacion, $cantidad, $usuario, 'conteo inicial');
}

/**
 * Pedido pendiente en la bodega con las líneas dadas: [[producto, lo que
 * trae la presentación (1 = UN, 12 = CJ12), cantidad], …].
 *
 * @param list<array{0: Producto, 1: int, 2: int}> $lineas
 */
function pedidoDeInventario(Bodega $bodega, User $vendedor, array $lineas): Pedido
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

test('la existencia se muestra por presentación; las unidades sueltas también en cajas', function (): void {
    expect($this->producto->existenciaPorPresentacion([$this->caja->id => 2, $this->base->id => 5]))->toBe('2 CJ12 · 5 UN')
        ->and($this->producto->existenciaPorPresentacion([$this->caja->id => 0, $this->base->id => -3]))->toBe('-3 UN')
        ->and($this->producto->existenciaPorPresentacion([]))->toBe('0');

    expect(Existencia::enCajas(29, 12))->toBe('2 CJ + 5 UN')
        ->and(Existencia::enCajas(24, 12))->toBe('2 CJ')
        ->and(Existencia::enCajas(5, 12))->toBe('5 UN')
        ->and(Existencia::enCajas(7, 1))->toBe('7 UN')
        ->and(Existencia::enCajas(-15, 12))->toBe('-1 CJ + 3 UN')
        ->and(Existencia::enCajas(0, 12))->toBe('0')
        ->and(Existencia::enCajas(29, 6, 'FD6', 'UN'))->toBe('4 FD6 + 5 UN')
        ->and($this->producto->existenciaEnTexto(29))->toBe('2 CJ12 + 5 UN');
});

test('costo promedio por presentación: la CJ12 se promedia con las CJ12 de todas las bodegas; la UN no se toca', function (): void {
    // Ya hay 2 CJ12 a L 960 en Santa Rosa; entran 2 CJ12 a L 1,200 en Ocotepeque.
    contar($this->bodega, $this->caja, 2, $this->admin);

    // 2 CJ12 a L 1,380 c/u con ISV = L 1,200 la caja sin ISV (L 100 la unidad).
    $compra = app(RegistrarCompra::class)->ejecutar($this->otra, $this->proveedor, [
        ['producto_id' => $this->producto->id, 'presentacion_id' => $this->caja->id, 'cantidad' => 2, 'costo' => 1380.0],
    ], $this->admin, 'A-0001');

    $item = $compra->items()->sole();

    // (2 × 960 + 2 × 1,200) ÷ 4 = 1,080 → CJ12: 1,080 ÷ 0.8 = 1,350. La UN sigue a 80 y 100.
    expect($this->caja->fresh()?->costo)->toBe(1080.0)
        ->and($this->caja->fresh()?->precio)->toBe(1350.0)
        ->and($this->base->fresh()?->costo)->toBe(80.0)
        ->and($this->base->fresh()?->precio)->toBe(100.0)
        ->and(Existencia::de($this->bodega->id, $this->caja->id))->toBe(2)
        ->and(Existencia::de($this->otra->id, $this->caja->id))->toBe(2)
        ->and(Existencia::en($this->otra->id, $this->producto->id))->toBe(24)
        ->and($compra->numero)->toBe('COM-'.str_pad((string) $compra->id, 6, '0', STR_PAD_LEFT))
        ->and($compra->total)->toBe(2760.0)
        ->and($compra->subtotal)->toBe(2400.0)
        ->and($compra->isv)->toBe(360.0)
        ->and($item->presentacion_id)->toBe($this->caja->id)
        ->and($item->cantidad)->toBe(2)
        ->and($item->unidades)->toBe(24)
        ->and($item->costo_unidad)->toBe(100.0)
        ->and($item->costo_anterior)->toBe(960.0)
        ->and($item->costo_nuevo)->toBe(1080.0)
        ->and($item->precio_nuevo)->toBe(1350.0);

    $cambio = CambioPrecio::query()->sole();
    $entrada = MovimientoInventario::query()->where('compra_id', $compra->id)->sole();

    expect($cambio->motivo)->toBe(MotivoCambioPrecio::Compra)
        ->and($cambio->presentacion_id)->toBe($this->caja->id)
        ->and($cambio->nota)->toContain((string) $compra->numero)
        ->and($entrada->tipo)->toBe(TipoMovimiento::Compra)
        ->and($entrada->bodega_id)->toBe($this->otra->id)
        ->and($entrada->presentacion_id)->toBe($this->caja->id)
        ->and($entrada->cantidad)->toBe(2)
        ->and($entrada->existencia)->toBe(2)
        ->and($entrada->costo_unidad)->toBe(1200.0);
});

test('último costo: toma el costo de la compra', function (): void {
    Configuracion::actual()->update(['metodo_costo' => MetodoCosto::Ultimo]);
    contar($this->bodega, $this->caja, 2, $this->admin);

    app(RegistrarCompra::class)->ejecutar($this->bodega, $this->proveedor, [
        ['producto_id' => $this->producto->id, 'presentacion_id' => $this->caja->id, 'cantidad' => 1, 'costo' => 1380.0],
    ], $this->admin);

    expect($this->caja->fresh()?->costo)->toBe(1200.0)
        ->and($this->caja->fresh()?->precio)->toBe(1500.0)
        ->and($this->base->fresh()?->precio)->toBe(100.0)
        ->and(Existencia::de($this->bodega->id, $this->caja->id))->toBe(3)
        ->and(Existencia::en($this->bodega->id, $this->producto->id))->toBe(36);
});

test('con promedio y sin existencia, el costo es el de la compra; lo exento entra sin ISV', function (): void {
    $exento = Producto::factory()->precio(40.0, 50.0)->conCaja(1)->create([
        'categoria_id' => $this->producto->categoria_id,
        'margen'       => 0.20,
        'gravado'      => false,
    ]);

    $compra = app(RegistrarCompra::class)->ejecutar($this->bodega, $this->proveedor, [
        ['producto_id' => $this->producto->id, 'presentacion_id' => presentacion($this->producto)->id, 'cantidad' => 10, 'costo' => 115.0],
        ['producto_id' => $exento->id, 'presentacion_id' => presentacion($exento)->id, 'cantidad' => 10, 'costo' => 48.0],
    ], $this->admin);

    // 115 con ISV = 100 sin ISV (la UN: 125); el exento queda en 48 (60). La CJ12 no se toca.
    expect($this->base->fresh()?->costo)->toBe(100.0)
        ->and($this->base->fresh()?->precio)->toBe(125.0)
        ->and($this->caja->fresh()?->costo)->toBe(960.0)
        ->and(presentacion($exento)->costo)->toBe(48.0)
        ->and(presentacion($exento)->precio)->toBe(60.0)
        ->and($compra->subtotal)->toBe(1480.0)
        ->and($compra->isv)->toBe(150.0)
        ->and($compra->total)->toBe(1630.0);
});

test('un mismo producto puede venir en varias presentaciones en la misma compra: cada una queda con su costo y su precio', function (): void {
    // El mismo jabón (mismo código) también viene en caja de 8 (sin costo todavía).
    $caja8 = Presentacion::query()->create(['producto_id' => $this->producto->id, 'unidad_medida_id' => $this->caja->unidad_medida_id, 'contenido' => 8, 'precio' => 800.0]);

    // CJ12 a L 1,380 con ISV = 1,200; CJ8 a L 828 = 720; 5 UN a L 103.50 = 90.
    $compra = app(RegistrarCompra::class)->ejecutar($this->bodega, $this->proveedor, [
        ['producto_id' => $this->producto->id, 'presentacion_id' => $this->caja->id, 'cantidad' => 1, 'costo' => 1380.0],
        ['producto_id' => $this->producto->id, 'presentacion_id' => $caja8->id, 'cantidad' => 1, 'costo' => 828.0],
        ['producto_id' => $this->producto->id, 'presentacion_id' => $this->base->id, 'cantidad' => 5, 'costo' => 103.5],
    ], $this->admin);

    // Sin existencia cada una toma el de su compra; con 20 %: CJ12 1,500, CJ8 900, UN 112.50.
    expect($this->caja->fresh()?->costo)->toBe(1200.0)
        ->and($this->caja->fresh()?->precio)->toBe(1500.0)
        ->and($caja8->fresh()?->costo)->toBe(720.0)
        ->and($caja8->fresh()?->precio)->toBe(900.0)
        ->and($this->base->fresh()?->costo)->toBe(90.0)
        ->and($this->base->fresh()?->precio)->toBe(112.5)
        ->and(Existencia::de($this->bodega->id, $this->base->id))->toBe(5)
        ->and(Existencia::de($this->bodega->id, $this->caja->id))->toBe(1)
        ->and(Existencia::de($this->bodega->id, $caja8->id))->toBe(1)
        ->and(Existencia::en($this->bodega->id, $this->producto->id))->toBe(25)
        ->and($compra->items()->orderBy('id')->pluck('unidades')->all())->toBe([5, 12, 8])
        ->and($compra->items()->orderBy('id')->pluck('costo_unidad')->all())->toBe([90.0, 100.0, 90.0])
        ->and($compra->total)->toBe(2725.5);
});

test('no se compra en una presentación desactivada ni en la de otro producto', function (): void {
    $otro = Producto::factory()->create(['proveedor_id' => $this->proveedor->id, 'categoria_id' => $this->producto->categoria_id]);
    caja($this->producto)->forceFill(['activo' => false])->save();

    foreach ([caja($this->producto)->id, caja($otro)->id] as $presentacionId) {
        expect(fn () => app(RegistrarCompra::class)->ejecutar($this->bodega, $this->proveedor, [
            ['producto_id' => $this->producto->id, 'presentacion_id' => $presentacionId, 'cantidad' => 1, 'costo' => 1380.0],
        ], $this->admin))->toThrow(ReglaNegocioException::class, 'Escoja en qué presentación viene');
    }

    expect(Compra::query()->count())->toBe(0);
});

test('el buscador de compras trae las presentaciones que se compran, con el costo y el precio de hoy de cada una', function (): void {
    Presentacion::query()->create(['producto_id' => $this->producto->id, 'unidad_medida_id' => $this->caja->unidad_medida_id, 'contenido' => 6, 'activo' => false]);
    contar($this->otra, $this->caja, 2, $this->admin);
    contar($this->otra, $this->base, 6, $this->admin);

    $this->actingAs($this->admin);

    /** @var list<array<string, mixed>> $resultados */
    $resultados = Livewire::test(CreateCompra::class)
        ->fillForm(['bodega_id' => $this->bodega->id, 'proveedor_id' => $this->proveedor->id])
        ->instance()
        ->callSchemaComponentMethod('form.lineas', 'buscarProductos', ['buscar' => $this->producto->codigo]);

    // CJ12: costo 960 + ISV = 1,104; precio 1,200 + ISV = 1,380. La CJ6 desactivada no sale. Cada una con su costo y lo que hay de ella.
    $presentaciones = $resultados[0]['presentaciones'];

    expect(array_column($presentaciones, 'sigla'))->toBe(['CJ12', 'UN'])
        ->and(array_column($presentaciones, 'costoHoy'))->toBe([1104.0, 92.0])
        ->and(array_column($presentaciones, 'precioHoy'))->toBe([1380.0, 115.0])
        ->and(array_column($presentaciones, 'costo'))->toBe([960.0, 80.0])
        ->and(array_column($presentaciones, 'existencia'))->toBe([2, 6]);
});

test('no entra un producto de otro proveedor: no se guarda nada', function (): void {
    $ajeno = Producto::factory()->create();

    expect(fn () => app(RegistrarCompra::class)->ejecutar($this->bodega, $this->proveedor, [
        ['producto_id' => $this->producto->id, 'presentacion_id' => presentacion($this->producto, 12)->id, 'cantidad' => 1, 'costo' => 1380.0],
        ['producto_id' => $ajeno->id, 'presentacion_id' => presentacion($ajeno, 12)->id, 'cantidad' => 1, 'costo' => 100.0],
    ], $this->admin))->toThrow(ReglaNegocioException::class);

    expect(Compra::query()->count())->toBe(0)
        ->and(Existencia::en($this->bodega->id, $this->producto->id))->toBe(0)
        ->and($this->caja->fresh()?->costo)->toBe(960.0);
});

test('el despacho saca cada presentación de la suya en la bodega del pedido; sin controlar la existencia puede quedar en negativo', function (): void {
    $pedido = pedidoDeInventario($this->bodega, $this->vendedor, [
        [$this->producto, 12, 1],
        [$this->producto, 1, 3],
    ]);

    app(ValidarDespacho::class)->ejecutar($pedido, [], $this->encargado);

    $salidas = MovimientoInventario::query()->where('pedido_id', $pedido->id)->orderBy('presentacion_id')->get();

    expect(Existencia::de($this->bodega->id, $this->caja->id))->toBe(-1)
        ->and(Existencia::de($this->bodega->id, $this->base->id))->toBe(-3)
        ->and(Existencia::en($this->bodega->id, $this->producto->id))->toBe(-15)
        ->and(Existencia::en($this->otra->id, $this->producto->id))->toBe(0)
        ->and($salidas->pluck('tipo')->unique()->all())->toBe([TipoMovimiento::Despacho])
        ->and($salidas->pluck('cantidad', 'presentacion_id')->all())->toBe([$this->base->id => -3, $this->caja->id => -1])
        ->and($salidas->pluck('costo_unidad', 'presentacion_id')->all())->toBe([$this->base->id => 80.0, $this->caja->id => 960.0]);
});

test('controlando la existencia no se despacha más de lo que hay; con lo que alcanza sí', function (): void {
    Configuracion::actual()->update(['controlar_existencias' => true]);
    contar($this->bodega, $this->caja, 1, $this->admin);
    contar($this->bodega, $this->base, 3, $this->admin);

    $pedido = pedidoDeInventario($this->bodega, $this->vendedor, [
        [$this->producto, 12, 2],
        [$this->producto, 1, 5],
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
        ->and($filas[0]['hay'])->toBe('1 CJ12 · 3 UN')
        ->and($filas[0]['hay_pres'])->toBe(1)
        ->and($filas[0]['sigla'])->toBe('CJ12')
        ->and($filas[1]['sale'])->toBe(3)
        ->and($filas[1]['hay_pres'])->toBe(3);

    app(ValidarDespacho::class)->ejecutar($pedido, [$caja->id => 1, $sueltas->id => 3], $this->encargado);

    expect(Existencia::en($this->bodega->id, $this->producto->id))->toBe(0)
        ->and($pedido->fresh()?->estado)->toBe(EstadoPedido::PorImprimir);
});

test('controlando la existencia, las sueltas que faltan salen de la caja que bodega dice que se abre', function (): void {
    Configuracion::actual()->update(['controlar_existencias' => true]);
    contar($this->bodega, $this->caja, 2, $this->admin);
    contar($this->bodega, $this->base, 3, $this->admin);

    $pedido = pedidoDeInventario($this->bodega, $this->vendedor, [
        [$this->producto, 12, 1],
        [$this->producto, 1, 5],
    ]);

    // Sin abrir caja no alcanzan las sueltas (hay 3 y salen 5).
    expect(fn () => app(ValidarDespacho::class)->ejecutar($pedido, [], $this->encargado))
        ->toThrow(ReglaNegocioException::class, 'No alcanza');

    // El modal deja salir todo: las 2 sueltas que faltan salen de la caja que queda.
    $filas = LineasPedido::datos($pedido, segunExistencia: true)['items'];

    expect(array_column($filas, 'sale'))->toBe([1, 5])
        ->and(LineasPedido::aperturas(['items' => [['abrir_de' => $this->caja->id, 'abrir_cajas' => 1], ['abrir_de' => null, 'abrir_cajas' => 0]]]))->toBe([$this->caja->id => 1]);

    app(ValidarDespacho::class)->ejecutar($pedido, [], $this->encargado, abrir: [$this->caja->id => 1]);

    $abiertas = MovimientoInventario::query()->where('pedido_id', $pedido->id)->where('tipo', TipoMovimiento::Apertura)->orderBy('id')->get();

    // CJ12: 2 − 1 abierta − 1 que sale = 0. UN: 3 + 12 − 5 = 10.
    expect(Existencia::de($this->bodega->id, $this->caja->id))->toBe(0)
        ->and(Existencia::de($this->bodega->id, $this->base->id))->toBe(10)
        ->and($pedido->fresh()?->estado)->toBe(EstadoPedido::PorImprimir)
        ->and($abiertas->pluck('cantidad', 'presentacion_id')->all())->toBe([$this->caja->id => -1, $this->base->id => 12]);
});

test('al corregir un pedido despachado regresa a la bodega lo que ya no sale, a su presentación', function (): void {
    contar($this->bodega, $this->caja, 8, $this->admin);

    $pedido = pedidoDeInventario($this->bodega, $this->vendedor, [[$this->producto, 12, 2]]);
    $linea = $pedido->items()->sole();

    app(ValidarDespacho::class)->ejecutar($pedido, [], $this->encargado);
    expect(Existencia::de($this->bodega->id, $this->caja->id))->toBe(6);

    app(CorregirPedido::class)->ejecutar($pedido, [$linea->id => 1], $this->encargado, 'devolvió una caja');

    $regreso = MovimientoInventario::query()->where('pedido_id', $pedido->id)->where('tipo', TipoMovimiento::Correccion)->sole();

    expect(Existencia::de($this->bodega->id, $this->caja->id))->toBe(7)
        ->and($regreso->presentacion_id)->toBe($this->caja->id)
        ->and($regreso->cantidad)->toBe(1);
});

test('precio del pedido: se respeta lo que se le dijo al cliente, salvo que quede bajo el costo', function (): void {
    $cambiar = fn (float $costo) => app(ActualizarPrecioProducto::class)->ejecutar($this->producto->fresh() ?? $this->producto, MotivoCambioPrecio::Manual, costos: [$this->base->id => $costo]);

    // Por defecto se cobra el precio del día que se despacha.
    $hoy = pedidoDeInventario($this->bodega, $this->vendedor, [[$this->producto, 1, 1]]);
    $cambiar(90.0); // precio 112.50
    app(ValidarDespacho::class)->ejecutar($hoy, [], $this->encargado);
    expect($hoy->items()->sole()->precio)->toBe(112.5);

    Configuracion::actual()->update(['precio_al_despachar' => PrecioAlDespachar::Pedido]);

    // Pedido a L 112.50; el costo sube a 100 (precio 125): se respeta 112.50.
    $respetado = pedidoDeInventario($this->bodega, $this->vendedor, [[$this->producto, 1, 1]]);
    $cambiar(100.0);
    app(ValidarDespacho::class)->ejecutar($respetado, [], $this->encargado);
    expect($respetado->items()->sole()->precio)->toBe(112.5);

    // Pedido a L 125; el costo sube a 130 (precio 162.50): 125 ya es pérdida, se cobra 162.50.
    $subio = pedidoDeInventario($this->bodega, $this->vendedor, [[$this->producto, 1, 1]]);
    $cambiar(130.0);
    app(ValidarDespacho::class)->ejecutar($subio, [], $this->encargado);
    expect($subio->items()->sole()->precio)->toBe(162.5);
});

test('el encargado registra una compra para su bodega desde el panel', function (): void {
    $this->actingAs($this->encargado);

    Livewire::test(CreateCompra::class)
        ->assertFormFieldHidden('bodega_id')
        ->fillForm([
            'proveedor_id'      => $this->proveedor->id,
            'factura_proveedor' => '000-001-01-00000077',
            'fecha'             => now()->toDateString(),
            'lineas'            => [
                ['producto_id' => $this->producto->id, 'presentacion_id' => presentacion($this->producto, 12)->id, 'cantidad' => 2, 'costo' => 1380],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $compra = Compra::query()->sole();

    expect($compra->bodega_id)->toBe($this->bodega->id)
        ->and($compra->user_id)->toBe($this->encargado->id)
        ->and($compra->factura_proveedor)->toBe('000-001-01-00000077')
        ->and($compra->total)->toBe(2760.0)
        ->and(Existencia::de($this->bodega->id, $this->caja->id))->toBe(2)
        ->and(Existencia::en($this->bodega->id, $this->producto->id))->toBe(24);

    Livewire::test(ListCompras::class)->assertCanSeeTableRecords([$compra]);
    Livewire::test(ViewCompra::class, ['record' => $compra->getRouteKey()])->assertOk();
});

test('la compra pide al menos un producto y el costo de cada uno', function (): void {
    $this->actingAs($this->encargado);

    Livewire::test(CreateCompra::class)
        ->fillForm(['proveedor_id' => $this->proveedor->id, 'fecha' => now()->toDateString()])
        ->call('create')
        ->assertHasFormErrors(['lineas' => 'required']);

    Livewire::test(CreateCompra::class)
        ->fillForm([
            'proveedor_id' => $this->proveedor->id,
            'fecha'        => now()->toDateString(),
            'lineas'       => [
                ['producto_id' => $this->producto->id, 'presentacion_id' => presentacion($this->producto, 12)->id, 'cantidad' => 2, 'costo' => ''],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['lineas']);

    expect(Compra::query()->count())->toBe(0)
        ->and(LineasCompra::sinCosto([['producto_id' => $this->producto->id, 'costo' => 0]]))->toBe([$this->producto->descripcion])
        ->and(LineasCompra::sinCosto([['producto_id' => $this->producto->id, 'costo' => 1380]]))->toBe([]);
});

test('cada bodega ve sus compras; el vendedor no entra a compras', function (): void {
    $deOtra = app(RegistrarCompra::class)->ejecutar($this->otra, $this->proveedor, [
        ['producto_id' => $this->producto->id, 'presentacion_id' => presentacion($this->producto, 12)->id, 'cantidad' => 1, 'costo' => 1380.0],
    ], $this->admin);
    $mia = app(RegistrarCompra::class)->ejecutar($this->bodega, $this->proveedor, [
        ['producto_id' => $this->producto->id, 'presentacion_id' => presentacion($this->producto, 12)->id, 'cantidad' => 1, 'costo' => 1380.0],
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
        ->callTableAction('ajustarExistencia', $this->producto, data: ['conteo' => [$this->caja->id => 2, $this->base->id => 5], 'nota' => 'conteo inicial'])
        ->assertHasNoTableActionErrors();

    $ajustes = MovimientoInventario::query()->orderBy('presentacion_id')->get();

    expect(Existencia::de($this->bodega->id, $this->caja->id))->toBe(2)
        ->and(Existencia::de($this->bodega->id, $this->base->id))->toBe(5)
        ->and(Existencia::en($this->bodega->id, $this->producto->id))->toBe(29)
        ->and($ajustes->pluck('tipo')->unique()->all())->toBe([TipoMovimiento::Ajuste])
        ->and($ajustes->pluck('cantidad', 'presentacion_id')->all())->toBe([$this->base->id => 5, $this->caja->id => 2])
        ->and($ajustes->pluck('nota')->unique()->all())->toBe(['CONTEO INICIAL'])
        ->and($this->caja->fresh()?->costo)->toBe(960.0);

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

test('el buscador de la preventa dice cuánto hay y pone primero lo que tiene; lo que no tiene sale al final, marcado', function (): void {
    Configuracion::actual()->update(['controlar_existencias' => true]);

    $this->producto->forceFill(['descripcion' => 'JABON A TOCADOR 12X100G'])->save();
    $sinExistencia = Producto::factory()->create(['descripcion' => 'JABON B SIN EXISTENCIA']);
    $enLaOtra = Producto::factory()->create(['descripcion' => 'JABON C EN LA OTRA BODEGA']);

    contar($this->bodega, $this->caja, 2, $this->admin);
    contar($this->bodega, $this->base, 5, $this->admin);
    contar($this->otra, presentacion($enLaOtra), 10, $this->admin);

    // El vendedor: su bodega. Los que no tienen salen al final (el teléfono no deja agregarlos).
    $this->actingAs($this->vendedor);
    $suyos = buscarEnPreventa('jabon');

    expect(array_column($suyos, 'id'))->toBe([$this->producto->id, $sinExistencia->id, $enLaOtra->id])
        ->and(array_column($suyos, 'hay'))->toBe([29, 0, 0])
        ->and($suyos[0]['hay_texto'])->toBe('2 CJ12 · 5 UN');

    // Administración: lo de la bodega que escogió; sin bodega, nada.
    $this->actingAs($this->admin);

    expect(buscarEnPreventa('jabon'))->toBe([])
        ->and(array_column(buscarEnPreventa('jabon', $this->otra->id), 'id'))->toBe([$enLaOtra->id, $this->producto->id, $sinExistencia->id]);

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
    contar($this->bodega, $this->caja, 2, $this->admin);
    contar($this->bodega, $this->base, 5, $this->admin);

    // Un pedido pendiente ya aparta 2 cajas (24 unidades): quedan 5 disponibles (sueltas; ninguna caja).
    $apartado = pedidoDeInventario($this->bodega, $this->vendedor, [[$this->producto, 12, 2]]);

    expect(Disponible::en($this->bodega->id, [$this->producto->id]))->toBe([$this->producto->id => 5])
        ->and(Disponible::porPresentacion($this->bodega->id, [$this->producto->id]))->toBe([$this->producto->id => [$this->base->id => 5, $this->caja->id => 0]])
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
            'items'       => [['producto_id' => $this->producto->id, 'presentacion_id' => presentacion($this->producto, 12)->id, 'cantidad' => 1]],
        ])
        ->call('create')
        ->assertHasFormErrors(['items']);

    expect(Pedido::query()->count())->toBe(1);

    // Al validar el que apartó: hay 29 en la bodega y este pedido saca 2 cajas.
    $fila = LineasPedido::datos($apartado)['items'][0];

    expect($fila['hay_unidades'])->toBe(29)
        ->and($fila['hay_pres'])->toBe(2)
        ->and($fila['es_base'])->toBeFalse()
        ->and($fila['contenido'])->toBe(12)
        ->and($fila['sigla'])->toBe('CJ12')
        ->and($fila['mueve'])->toBeTrue()
        ->and($fila['salio_unidades'])->toBe(0);

    // Bodega manda 1 de las 2 cajas: queda dicho que fue la caja la que no va completa.
    $validado = app(ValidarDespacho::class)->ejecutar($apartado, [$apartado->items()->sole()->id => 1], $this->encargado);

    expect($validado->items()->sole()->faltaEnPalabras())->toBe('No va completo: falta 1 caja de 12 (pidió 2)')
        ->and(LineasPedido::incompletas($validado))->toBe('No va completo: JABON A TOCADOR 12X100G (falta 1 caja de 12, pidió 2).')
        ->and(Disponible::en($this->bodega->id, [$this->producto->id]))->toBe([$this->producto->id => 17]);

    // Al corregirlo, lo que ya salió (1 caja) es lo que regresaría.
    expect(LineasPedido::datos($validado)['items'][0]['salio_unidades'])->toBe(12)
        ->and(LineasPedido::datos($validado)['items'][0]['salio_pres'])->toBe(1);
});

test('con precio fijo la compra mueve el costo promedio pero no el precio; si el costo lo pasa, avisa', function (): void {
    // La CJ12 fija a L 1,140; la UN sigue con el % de utilidad.
    $this->caja->forceFill(['precio_fijo' => true, 'precio' => 1140.0])->save();
    contar($this->bodega, $this->caja, 2, $this->admin);

    $this->actingAs($this->encargado);

    $comprar = fn (int $cajas, float $costoCaja) => Livewire::test(CreateCompra::class)
        ->fillForm([
            'proveedor_id' => $this->proveedor->id,
            'fecha'        => now()->toDateString(),
            'lineas'       => [
                ['producto_id' => $this->producto->id, 'presentacion_id' => $this->caja->id, 'cantidad' => $cajas, 'costo' => $costoCaja],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    // 2 cajas a L 1,380 con ISV = L 1,200: (2 × 960 + 2 × 1,200) ÷ 4 = 1,080. Se sigue vendiendo a 1,140.
    $comprar(2, 1380.0)->assertNotNotified('Hay precios fijos por debajo del costo');

    expect($this->caja->fresh()?->costo)->toBe(1080.0)
        ->and($this->caja->fresh()?->precio)->toBe(1140.0)
        ->and($this->base->fresh()?->precio)->toBe(100.0)
        ->and(Compra::query()->latest('id')->firstOrFail()->items()->sole()->precio_nuevo)->toBe(1140.0);

    // 4 cajas a L 1,794 con ISV = L 1,560: (4 × 1,080 + 4 × 1,560) ÷ 8 = 1,320, más que los 1,140 del precio.
    $comprar(4, 1794.0)->assertNotified('Hay precios fijos por debajo del costo');

    expect($this->caja->fresh()?->costo)->toBe(1320.0)
        ->and($this->caja->fresh()?->precio)->toBe(1140.0)
        ->and($this->caja->fresh()?->precio_fijo)->toBeTrue()
        ->and($this->base->fresh()?->precio)->toBe(100.0);
});
