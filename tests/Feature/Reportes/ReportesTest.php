<?php

declare(strict_types=1);

use App\Domain\Inventario\AjustarExistencia;
use App\Domain\Inventario\MoverExistencia;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarDespacho;
use App\Domain\Precios\ActualizarPrecioProducto;
use App\Domain\Reportes\InventarioValorizado;
use App\Domain\Reportes\Periodo;
use App\Domain\Reportes\VentasDelPeriodo;
use App\Enums\MotivoCambioPrecio;
use App\Enums\Rol;
use App\Enums\TipoMovimiento;
use App\Enums\UnidadVenta;
use App\Exports\InventarioValorizadoExcel;
use App\Exports\ReporteVentasExcel;
use App\Filament\Pages\ReporteInventario;
use App\Filament\Pages\ReporteVentas;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSistemaSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as FormatoExcel;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reportes de ventas e inventario valorizado: administración ve todas las
 * bodegas o una; el encargado, solo la suya; el vendedor no entra.
 *
 * Inventario: en Copán 24 del gravado (costo 80, precio 100) y 10 del exento
 * (costo 40, precio 50); en Ocotepeque 12 del gravado.
 */
beforeEach(function (): void {
    $this->seed(RolesSistemaSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->copan = Bodega::factory()->create(['codigo' => 'COP', 'nombre' => 'BODEGA COPÁN']);
    $this->ocote = Bodega::factory()->create(['codigo' => 'OCO', 'nombre' => 'BODEGA OCOTEPEQUE']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Rol::Administrador->value);

    $this->encargado = User::factory()->create(['bodega_id' => $this->copan->id]);
    $this->encargado->assignRole(Rol::Bodega->value);

    $this->vendedor = User::factory()->create(['bodega_id' => $this->copan->id, 'codigo' => 'V01']);
    $this->vendedor->assignRole(Rol::Vendedor->value);

    $this->vendedorOco = User::factory()->create(['bodega_id' => $this->ocote->id, 'codigo' => 'V02']);
    $this->vendedorOco->assignRole(Rol::Vendedor->value);

    $this->gravado = Producto::factory()->precio(80.0, 100.0)->create(['margen' => 0.20, 'unidades_por_caja' => 12, 'gravado' => true]);
    $this->exento = Producto::factory()->exento()->precio(40.0, 50.0)->create([
        'categoria_id'      => $this->gravado->categoria_id,
        'margen'            => 0.20,
        'unidades_por_caja' => 1,
    ]);

    $ajustar = app(AjustarExistencia::class);
    $ajustar->ejecutar($this->copan->id, $this->gravado, 24, $this->admin, 'conteo');
    $ajustar->ejecutar($this->copan->id, $this->exento, 10, $this->admin, 'conteo');
    $ajustar->ejecutar($this->ocote->id, $this->gravado, 12, $this->admin, 'conteo');
});

/**
 * Pedido levantado y despachado: [[producto, unidad, cantidad], …].
 *
 * @param list<array{0: Producto, 1: UnidadVenta, 2: int}> $lineas
 */
function pedidoDeReporte(Bodega $bodega, User $vendedor, array $lineas, User $despacha, bool $despachar = true): Pedido
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

    app(RecalcularPedido::class)->ejecutar($pedido);

    return $despachar ? app(ValidarDespacho::class)->ejecutar($pedido, [], $despacha) : $pedido;
}

/**
 * Copán: 1 caja del gravado (L 1,200) + 2 del exento (L 100) → venta 1,300, costo 1,040, ISV 180.
 * Ocotepeque: 3 del gravado (L 300) → venta 300, costo 240, ISV 45. Y uno pendiente que no cuenta.
 */
function ventasDeReporte(object $t): void
{
    pedidoDeReporte($t->copan, $t->vendedor, [[$t->gravado, UnidadVenta::Caja, 1], [$t->exento, UnidadVenta::Unidad, 2]], $t->admin);
    pedidoDeReporte($t->ocote, $t->vendedorOco, [[$t->gravado, UnidadVenta::Unidad, 3]], $t->admin);
    pedidoDeReporte($t->copan, $t->vendedor, [[$t->gravado, UnidadVenta::Caja, 5]], $t->admin, despachar: false);
}

test('ventas: totales, costo con el promedio del día que salió y ganancia', function (): void {
    ventasDeReporte($this);

    // Después del despacho el costo sube: el reporte sigue con el de ese día (80).
    app(ActualizarPrecioProducto::class)->ejecutar($this->gravado->fresh() ?? $this->gravado, 90.0, MotivoCambioPrecio::Compra);

    $todas = new VentasDelPeriodo(Periodo::de('hoy'), null);
    $total = $todas->totales();

    expect($total->pedidos)->toBe(2)
        ->and($total->clientes)->toBe(2)
        ->and($total->venta)->toBe(1600.0)
        ->and($total->isv)->toBe(225.0)
        ->and($total->total)->toBe(1825.0)
        ->and($total->costo)->toBe(1280.0)
        ->and($total->ganancia())->toBe(320.0)
        ->and($total->margen())->toBe(0.2)
        ->and($todas->pedidosEstimados())->toBe(0);

    $copan = (new VentasDelPeriodo(Periodo::de('hoy'), $this->copan))->totales();

    expect($copan->pedidos)->toBe(1)
        ->and($copan->venta)->toBe(1300.0)
        ->and($copan->costo)->toBe(1040.0);

    // Ayer no hubo nada.
    expect((new VentasDelPeriodo(Periodo::de('ayer'), null))->totales()->pedidos)->toBe(0);
});

test('ventas por bodega, vendedor, producto, cliente y día', function (): void {
    ventasDeReporte($this);
    $reporte = new VentasDelPeriodo(Periodo::de('mes'), null);

    $bodegas = $reporte->porBodega();
    $vendedores = $reporte->porVendedor();
    $productos = $reporte->porProducto();
    $clientes = $reporte->porCliente();
    $dias = $reporte->porDia();

    expect(array_map(fn ($f) => [$f->nombre, $f->venta], $bodegas))->toBe([['BODEGA COPÁN', 1300.0], ['BODEGA OCOTEPEQUE', 300.0]])
        ->and(array_map(fn ($f) => [$f->codigo, $f->venta, $f->costo], $vendedores))->toBe([['V01', 1300.0, 1040.0], ['V02', 300.0, 240.0]])
        ->and($productos[0]->codigo)->toBe($this->gravado->codigo)
        ->and($productos[0]->unidades)->toBe(15)
        ->and($productos[0]->cantidad)->toBe('1 CJ + 3 UN')
        ->and($productos[0]->venta)->toBe(1500.0)
        ->and($productos[0]->costo)->toBe(1200.0)
        ->and($productos[1]->cantidad)->toBe('2 UN')
        ->and($productos[1]->ganancia())->toBe(20.0)
        ->and(count($clientes))->toBe(2)
        ->and($clientes[0]->venta)->toBe(1300.0)
        ->and($clientes[0]->detalle)->toBe('Último: '.now()->format('d/m/Y'))
        ->and(count($dias))->toBe(1)
        ->and($dias[0]->venta)->toBe(1600.0);
});

test('ventas sin salida en el kárdex: el costo se estima con el de hoy y se avisa', function (): void {
    ventasDeReporte($this);

    $pedido = Pedido::query()->where('bodega_id', $this->ocote->id)->sole();
    MovimientoInventario::query()->where('pedido_id', $pedido->id)->delete();

    $reporte = new VentasDelPeriodo(Periodo::de('hoy'), null);

    expect($reporte->pedidosEstimados())->toBe(1)
        ->and($reporte->totales()->costo)->toBe(1280.0);
});

test('el periodo: atajos, fechas al revés y el texto', function (): void {
    $hoy = CarbonImmutable::parse('2026-11-11');

    expect(Periodo::de('mes', hoy: $hoy)->texto())->toBe('01/11/2026 al 11/11/2026')
        ->and(Periodo::de('mes_pasado', hoy: $hoy)->texto())->toBe('01/10/2026 al 31/10/2026')
        ->and(Periodo::de('semana', hoy: $hoy)->texto())->toBe('09/11/2026 al 11/11/2026')
        ->and(Periodo::de('ayer', hoy: $hoy)->texto())->toBe('10/11/2026')
        ->and(Periodo::de('otro', '2026-11-20', '2026-11-05', $hoy)->texto())->toBe('05/11/2026 al 20/11/2026')
        ->and(Periodo::de('otro', 'no es fecha', null, $hoy)->texto())->toBe('01/11/2026 al 11/11/2026');
});

test('administración ve todas o una bodega en pantalla y descarga el Excel', function (): void {
    ventasDeReporte($this);
    $this->actingAs($this->admin);
    Excel::fake();

    $pantalla = Livewire::test(ReporteVentas::class)
        ->assertOk()
        ->assertSee('L. 1,600.00')
        ->assertSee('BODEGA OCOTEPEQUE')
        ->fillForm(['bodega_id' => $this->copan->id])
        ->assertSee('L. 1,300.00')
        ->assertDontSee('L. 1,600.00');

    $pantalla->fillForm(['bodega_id' => null, 'ver' => 'vendedores'])->callAction('excel');

    Excel::assertDownloaded('ventas-vendedores-todas-'.Periodo::de('mes')->enArchivo().'.xlsx', function (ReporteVentasExcel $excel): bool {
        $hojas = $excel->sheets();

        return count($hojas) === 1 && $hojas[0]->title() === 'Por vendedor';
    });
});

test('el encargado solo ve su bodega, aunque pida otra en el enlace del PDF; el vendedor no entra', function (): void {
    ventasDeReporte($this);

    $this->actingAs($this->encargado);

    Livewire::test(ReporteVentas::class)
        ->assertOk()
        ->assertFormFieldHidden('bodega_id')
        ->assertSee('L. 1,300.00')
        ->assertDontSee('L. 1,600.00');

    $this->get(route('reportes.ventas.imprimir', [
        'desde'  => now()->toDateString(),
        'hasta'  => now()->toDateString(),
        'bodega' => $this->ocote->id,
        'ver'    => 'todo',
    ]))
        ->assertOk()
        ->assertSee('BODEGA COPÁN')
        ->assertSee('L. 1,300.00')
        ->assertDontSee('L. 300.00');

    $this->actingAs($this->vendedor);

    $this->get(ReporteVentas::getUrl())->assertForbidden();
    $this->get(ReporteInventario::getUrl())->assertForbidden();
    $this->get(route('reportes.ventas.imprimir'))->assertForbidden();
    $this->get(route('reportes.inventario.imprimir'))->assertForbidden();
});

test('el Excel de ventas se arma de verdad: códigos como texto y montos como número', function (): void {
    ventasDeReporte($this);

    $archivo = tempnam(sys_get_temp_dir(), 'ventas').'.xlsx';
    file_put_contents($archivo, Excel::raw(new ReporteVentasExcel(new VentasDelPeriodo(Periodo::de('hoy'), null), 'todo'), FormatoExcel::XLSX));

    $libro = IOFactory::load($archivo);
    $productos = $libro->getSheetByName('Por producto');
    $resumen = $libro->getSheetByName('Resumen');

    // Encabezado en 1-3, títulos en la 5, datos desde la 6.
    expect($libro->getSheetNames())->toBe(['Resumen', 'Por día', 'Por bodega', 'Por vendedor', 'Por producto', 'Por cliente'])
        ->and($productos?->getCell('A6')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($productos?->getCell('A6')->getValue())->toBe($this->gravado->codigo)
        ->and($productos?->getCell('H6')->getValue())->toEqual(1500)
        ->and($resumen?->getCell('B8')->getValue())->toEqual(1600);

    @unlink($archivo);
});

test('inventario valorizado: cuánto hay en cada bodega, al costo y a precio de venta', function (): void {
    $todas = new InventarioValorizado(null);
    $total = $todas->totales();

    // Gravado: 36 × 80 = 2,880 (con ISV 3,312); exento: 10 × 40 = 400. A precio de venta: 3,600 + 500.
    expect($total->productos)->toBe(2)
        ->and($total->unidades)->toBe(46)
        ->and($total->invertido)->toBe(3280.0)
        ->and($total->invertidoConIsv)->toBe(3712.0)
        ->and($total->valorVenta)->toBe(4100.0)
        ->and($total->ganancia())->toBe(820.0)
        ->and(array_map(fn ($f) => [$f->nombre, $f->productos, $f->invertido], $todas->porBodega()))->toBe([
            ['BODEGA COPÁN', 2, 2320.0],
            ['BODEGA OCOTEPEQUE', 1, 960.0],
        ]);

    $copan = new InventarioValorizado($this->copan);
    $gravado = collect($copan->productos())->firstWhere('codigo', $this->gravado->codigo);

    expect($copan->totales()->invertido)->toBe(2320.0)
        ->and($gravado?->existencia)->toBe('2 CJ')
        ->and($gravado?->invertido)->toBe(1920.0)
        ->and($gravado?->valorVenta)->toBe(2400.0)
        ->and($copan->porBodega())->toHaveCount(1);
});

test('inventario: lo negativo no se suma y se avisa; se puede buscar y ver lo que no tiene existencia', function (): void {
    $sinExistencia = Producto::factory()->create(['categoria_id' => $this->gravado->categoria_id]);
    $negativo = Producto::factory()->create(['categoria_id' => $this->gravado->categoria_id]);
    app(MoverExistencia::class)->ejecutar($this->copan->id, $negativo, -5, TipoMovimiento::Ajuste, $this->admin);

    $reporte = new InventarioValorizado($this->copan);

    expect($reporte->enNegativo())->toBe(1)
        ->and($reporte->totales()->invertido)->toBe(2320.0)
        ->and(collect($reporte->productos())->pluck('codigo')->all())->not->toContain($sinExistencia->codigo);

    $todos = new InventarioValorizado($this->copan, soloConExistencia: false);
    $buscado = new InventarioValorizado(null, buscar: $this->exento->codigo);

    expect(collect($todos->productos())->pluck('codigo')->all())->toContain($sinExistencia->codigo)
        ->and($buscado->productos())->toHaveCount(1)
        ->and($buscado->totales()->invertido)->toBe(400.0);
});

test('inventario en pantalla, Excel y PDF: el encargado solo ve el de su bodega', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(ReporteInventario::class)
        ->assertOk()
        ->assertSee('L. 3,280.00')
        ->fillForm(['bodega_id' => $this->ocote->id])
        ->assertSee('L. 960.00')
        ->assertDontSee('L. 3,280.00');

    $sheets = (new InventarioValorizadoExcel(new InventarioValorizado(null)))->sheets();

    expect(array_map(fn ($hoja) => $hoja->title(), $sheets))->toBe(['Productos', 'Por bodega', 'Por proveedor']);

    $this->actingAs($this->encargado);

    Livewire::test(ReporteInventario::class)
        ->assertOk()
        ->assertFormFieldHidden('bodega_id')
        ->assertSee('L. 2,320.00')
        ->assertDontSee('L. 3,280.00');

    $this->get(route('reportes.inventario.imprimir', ['bodega' => $this->ocote->id]))
        ->assertOk()
        ->assertSee('BODEGA COPÁN')
        ->assertSee('L. 2,320.00')
        ->assertDontSee('L. 960.00');
});
