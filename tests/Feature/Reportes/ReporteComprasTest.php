<?php

declare(strict_types=1);

use App\Domain\Inventario\RegistrarCompra;
use App\Domain\Reportes\ComprasDelPeriodo;
use App\Domain\Reportes\Periodo;
use App\Enums\Rol;
use App\Exports\ReporteComprasExcel;
use App\Filament\Pages\ReporteCompras;
use App\Models\Bodega;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSistemaSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as FormatoExcel;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reporte de compras por proveedor, hoy 18/11/2026 (quincenas del 1 al 14
 * y del 15 a fin de mes).
 *
 *  - 05/11 Copán, MERHONSA (factura 101): 10 CJ12 de jabón a L 1,150 con
 *    ISV (1,000 sin ISV) y 50 UN de arroz exento a L 20 → L 12,500.
 *  - 16/11 Ocotepeque, MERHONSA (factura 188): 2 CJ12 de jabón → L 2,300.
 *  - 17/11 Copán, otro proveedor: 4 CJ12 de axion a L 552 → L 2,208.
 *  - 30/10 Copán, MERHONSA: 1 CJ12 de jabón (otro mes, no cuenta en este).
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00'));
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

    $this->jabon = Producto::factory()->create(['gravado' => true, 'margen' => 0.20]);
    $this->mer = $this->jabon->proveedor;
    $this->mer->update(['nombre_corto' => 'MERHONSA', 'razon_social' => 'MERCANTIL HONDUREÑA S.A.', 'rtn' => '08019001234567']);
    $this->arroz = Producto::factory()->exento()->conCaja(1)->create(['categoria_id' => $this->jabon->categoria_id, 'margen' => 0.20]);
    $this->axion = Producto::factory()->create(['gravado' => true, 'margen' => 0.20]);
    $this->axion->proveedor->update(['nombre_corto' => 'UNILEVER']);

    comprarParaReporte($this->copan, $this->mer, [[$this->jabon, 12, 10, 1150.0], [$this->arroz, 1, 50, 20.0]], $this->admin, '2026-11-05', '000-001-01-00000101');
    comprarParaReporte($this->ocote, $this->mer, [[$this->jabon, 12, 2, 1150.0]], $this->admin, '2026-11-16', '000-001-01-00000188');
    comprarParaReporte($this->copan, $this->axion->proveedor, [[$this->axion, 12, 4, 552.0]], $this->admin, '2026-11-17');
    comprarParaReporte($this->copan, $this->mer, [[$this->jabon, 12, 1, 1150.0]], $this->admin, '2026-10-30');
});

/**
 * Compra registrada como en la pantalla: [[producto, lo que trae la
 * presentación (1 = UN, 12 = CJ12), cantidad, costo con ISV], …].
 *
 * @param list<array{0: Producto, 1: int, 2: int, 3: float}> $lineas
 */
function comprarParaReporte(Bodega $bodega, Proveedor $proveedor, array $lineas, User $usuario, string $fecha, ?string $factura = null): void
{
    app(RegistrarCompra::class)->ejecutar($bodega, $proveedor, array_map(fn (array $l): array => [
        'producto_id'     => $l[0]->id,
        'presentacion_id' => presentacion($l[0], $l[1])->id,
        'cantidad'        => $l[2],
        'costo'           => $l[3],
    ], $lineas), $usuario, $factura, Carbon::parse($fecha));
}

test('el mes con todos: cuánto a cada proveedor y a cada bodega, sin ISV, ISV y total', function (): void {
    $reporte = new ComprasDelPeriodo(Periodo::de('mes'), null);
    $total = $reporte->totales();

    expect($total->compras)->toBe(3)
        ->and($total->total)->toBe(17008.0)
        ->and($total->subtotal)->toBe(14920.0)
        ->and($total->isv)->toBe(2088.0)
        ->and($reporte->cuantosProveedores())->toBe(2)
        ->and(array_map(fn ($f) => [$f->nombre, $f->compras, $f->total], $reporte->porProveedor()))->toBe([['MERHONSA', 2, 14800.0], ['UNILEVER', 1, 2208.0]])
        ->and(array_map(fn ($f) => [$f->nombre, $f->total], $reporte->porBodega()))->toBe([['BODEGA COPÁN', 14708.0], ['BODEGA OCOTEPEQUE', 2300.0]]);
});

test('con un proveedor: sus productos en la presentación en que entraron y sus facturas', function (): void {
    $reporte = new ComprasDelPeriodo(Periodo::de('mes'), null, $this->mer);
    $productos = $reporte->porProducto();
    $compras = $reporte->porCompra();

    expect($reporte->titulo('todo'))->toBe('Compras a MERHONSA')
        ->and($reporte->fichaProveedor())->toBe('MERCANTIL HONDUREÑA S.A. · RTN 08019001234567')
        ->and($reporte->totales()->total)->toBe(14800.0)
        ->and(array_map(fn ($f) => [$f->codigo, $f->presentacion, $f->cantidad, $f->unidades, $f->total], $productos))->toBe([
            [$this->jabon->codigo, 'CJ12', 12, 144, 13800.0],
            [$this->arroz->codigo, 'UN', 50, 50, 1000.0],
        ])
        ->and($productos[0]->costoPromedio())->toBe(1000.0)
        ->and($productos[1]->isv)->toBe(0.0)
        ->and(array_map(fn ($f) => [$f->fecha, $f->codigo, $f->bodega, $f->total], $compras))->toBe([
            ['05/11/2026', '000-001-01-00000101', 'BODEGA COPÁN', 12500.0],
            ['16/11/2026', '000-001-01-00000188', 'BODEGA OCOTEPEQUE', 2300.0],
        ])
        ->and(count($reporte->porCategoria()))->toBe(1)
        ->and($reporte->nombreArchivo('productos'))->toBe('compras-merhonsa-por-producto-todas-2026-11-01_2026-11-18');
});

test('semana, quincena y mes: el que va (hasta hoy) y el pasado', function (): void {
    $total = fn (string $periodo): float => (new ComprasDelPeriodo(Periodo::de($periodo), null))->totales()->total;

    expect(Periodo::de('semana')->texto())->toBe('16/11/2026 al 18/11/2026')
        ->and(Periodo::de('semana_pasada')->texto())->toBe('09/11/2026 al 15/11/2026')
        ->and(Periodo::de('quincena')->texto())->toBe('15/11/2026 al 18/11/2026')
        ->and(Periodo::de('quincena_pasada')->texto())->toBe('01/11/2026 al 14/11/2026')
        ->and(Periodo::de('quincena_pasada', hoy: CarbonImmutable::parse('2026-11-10'))->texto())->toBe('15/10/2026 al 31/10/2026')
        ->and($total('semana'))->toBe(4508.0)
        ->and($total('semana_pasada'))->toBe(0.0)
        ->and($total('quincena'))->toBe(4508.0)
        ->and($total('quincena_pasada'))->toBe(12500.0)
        ->and($total('mes_pasado'))->toBe(1150.0);
});

test('administración escoge el proveedor y descarga el Excel; desde Proveedores llega ya escogido', function (): void {
    $this->actingAs($this->admin);
    Excel::fake();

    $pantalla = Livewire::test(ReporteCompras::class)
        ->assertOk()
        ->assertSee('L. 17,008.00')
        ->assertSee('UNILEVER')
        ->fillForm(['proveedor_id' => $this->mer->id])
        ->assertSee('L. 14,800.00')
        ->assertSee('000-001-01-00000101')
        ->assertDontSee('L. 17,008.00');

    $pantalla->fillForm(['ver' => 'productos'])->callAction('excel');

    Excel::assertDownloaded('compras-merhonsa-por-producto-todas-'.Periodo::de('mes')->enArchivo().'.xlsx', function (ReporteComprasExcel $excel): bool {
        $hojas = $excel->sheets();

        return count($hojas) === 1 && $hojas[0]->title() === 'Por producto';
    });

    $this->get(ReporteCompras::delProveedor($this->mer))
        ->assertOk()
        ->assertSee('L. 14,800.00')
        ->assertDontSee('L. 17,008.00');
});

test('el encargado solo ve su bodega, también en la hoja de imprimir; el vendedor no entra', function (): void {
    $this->actingAs($this->encargado);

    Livewire::test(ReporteCompras::class)
        ->assertOk()
        ->assertFormFieldHidden('bodega_id')
        ->assertSee('L. 14,708.00')
        ->assertDontSee('L. 17,008.00');

    $this->get(route('reportes.compras.imprimir', [
        'desde'     => '2026-11-01',
        'hasta'     => '2026-11-18',
        'bodega'    => $this->ocote->id,
        'proveedor' => $this->mer->id,
    ]))
        ->assertOk()
        ->assertSee('Compras a MERHONSA')
        ->assertSee('RTN 08019001234567')
        ->assertSee('BODEGA COPÁN')
        ->assertSee('L. 12,500.00')
        ->assertDontSee('000-001-01-00000188');

    $this->actingAs($this->vendedor);

    $this->get(ReporteCompras::getUrl())->assertForbidden();
    $this->get(route('reportes.compras.imprimir'))->assertForbidden();
});

test('el Excel del proveedor se arma de verdad: códigos y facturas como texto, montos como número', function (): void {
    $archivo = tempnam(sys_get_temp_dir(), 'compras').'.xlsx';
    file_put_contents($archivo, Excel::raw(new ReporteComprasExcel(new ComprasDelPeriodo(Periodo::de('mes'), null, $this->mer), 'todo'), FormatoExcel::XLSX));

    $libro = IOFactory::load($archivo);
    $resumen = $libro->getSheetByName('Resumen');
    $productos = $libro->getSheetByName('Por producto');
    $compras = $libro->getSheetByName('Por compra');

    // Con proveedor el encabezado lleva su ficha: 4 líneas, títulos en la 6, datos desde la 7.
    expect($libro->getSheetNames())->toBe(['Resumen', 'Por categoría', 'Por bodega', 'Por producto', 'Por compra'])
        ->and($resumen?->getCell('A2')->getValue())->toBe('MERCANTIL HONDUREÑA S.A. · RTN 08019001234567')
        ->and($resumen?->getCell('B12')->getValue())->toEqual(14800)
        ->and($productos?->getCell('A7')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($productos?->getCell('A7')->getValue())->toBe($this->jabon->codigo)
        ->and($productos?->getCell('D7')->getValue())->toBe('CJ12')
        ->and($productos?->getCell('J7')->getValue())->toEqual(13800)
        ->and($compras?->getCell('B7')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($compras?->getCell('B7')->getValue())->toBe('000-001-01-00000101');

    unlink($archivo);
});
