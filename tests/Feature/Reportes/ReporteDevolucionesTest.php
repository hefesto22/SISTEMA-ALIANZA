<?php

declare(strict_types=1);

use App\Domain\Devoluciones\RegistrarDevolucion;
use App\Domain\Inventario\RegistrarCompra;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarDespacho;
use App\Domain\Reportes\DevolucionesDelPeriodo;
use App\Domain\Reportes\Periodo;
use App\Enums\Rol;
use App\Enums\TipoDevolucion;
use App\Exports\HojaReporte;
use App\Exports\ReporteDevolucionesExcel;
use App\Filament\Pages\ReporteDevoluciones;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Reporte de devoluciones y pérdidas. El jabón cuesta L 8 la UN y L 96 la
 * CJ12 (sin ISV) y se vende a L 10 y L 120 más ISV. Del pedido entregado
 * (10 UN y 1 CJ12):
 *
 *  - Cambio: 1 UN buena y 2 UN dañadas → pérdida L 16, regresa L 8 y se reponen 3 UN (L 24).
 *  - Crédito: 1 CJ12 buena → regresa L 96 y se le devuelven L 138 (120 + ISV).
 */
beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $this->bodega = Bodega::factory()->create(['codigo' => 'COP', 'nombre' => 'BODEGA COPÁN']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Rol::Administrador->value);
    $this->encargado = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->encargado->assignRole(Rol::Bodega->value);
    $this->vendedor = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->vendedor->assignRole(Rol::Vendedor->value);

    $this->jabon = Producto::factory()->precio(8.0, 10.0)->create(['margen' => 0.20, 'descripcion' => 'JABON BEST 250G']);
    $this->jabon->proveedor->update(['nombre_corto' => 'MERHONSA']);
    $this->un = presentacion($this->jabon);
    $this->cj12 = presentacion($this->jabon, 12);
    app(RegistrarCompra::class)->ejecutar($this->bodega, $this->jabon->proveedor, [
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->un->id, 'cantidad' => 12, 'costo' => 9.2],
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->cj12->id, 'cantidad' => 3, 'costo' => 110.4],
    ], $this->admin);

    facturacionLista();
    Configuracion::actual()->update(['notas_credito' => false]);

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
    $pedido = $pedido->fresh();

    $lineaUn = $pedido->items()->where('presentacion_id', $this->un->id)->sole();
    $lineaCaja = $pedido->items()->where('presentacion_id', $this->cj12->id)->sole();

    $this->cambio = app(RegistrarDevolucion::class)->ejecutar($pedido, TipoDevolucion::Cambio, [$lineaUn->id => ['buenas' => 1, 'danadas' => 2]], $this->encargado, 'venían golpeados');
    $this->credito = app(RegistrarDevolucion::class)->ejecutar($pedido, TipoDevolucion::Credito, [$lineaCaja->id => ['buenas' => 1]], $this->encargado);
});

test('pérdidas (lo dañado, a costo), cambio por cambio y cada devolución', function (): void {
    $reporte = new DevolucionesDelPeriodo(Periodo::de('mes'), null);

    expect($reporte->totales())->toBe([
        'devoluciones'       => 2,
        'cambios'            => 1,
        'creditos'           => 1,
        'perdida'            => 16.0,
        'productos_perdidos' => 1,
        'regreso'            => 104.0,
        'repuesto'           => 24.0,
        'credito'            => 138.0,
    ])
        ->and($reporte->perdidasPorProveedor())->toBe([['proveedor' => 'MERHONSA', 'productos' => 1, 'perdida' => 16.0]])
        ->and(array_map(fn (array $f): array => [$f['presentacion'], $f['cantidad'], $f['costo'], $f['perdida']], $reporte->perdidas()))->toBe([['UN', 2, 8.0, 16.0]])
        ->and(array_map(fn (array $f): array => [$f['presentacion'], $f['buenas'], $f['danadas'], $f['repuestas'], $f['costo']], $reporte->cambios()))->toBe([['UN', 1, 2, 3, 24.0]])
        ->and(array_map(fn (array $f): array => [$f['numero'], $f['tipo'], $f['regreso'], $f['perdida'], $f['credito']], $reporte->devoluciones()))->toBe([
            [$this->cambio->numero, TipoDevolucion::Cambio, 8.0, 16.0, null],
            [$this->credito->numero, TipoDevolucion::Credito, 96.0, 0.0, 138.0],
        ]);

    // La semana que viene no tiene nada.
    expect((new DevolucionesDelPeriodo(Periodo::de('otro', now()->addWeek()->toDateString(), now()->addWeeks(2)->toDateString()), null))->totales()['devoluciones'])->toBe(0);
});

test('el encargado lo ve de su bodega, lo baja en Excel y lo imprime; el vendedor no entra', function (): void {
    $this->actingAs($this->encargado);
    Excel::fake();

    Livewire::test(ReporteDevoluciones::class)
        ->assertOk()
        ->assertFormFieldHidden('bodega_id')
        ->assertSee('Pérdidas por proveedor')
        ->assertSee('L. 16.00')
        ->assertSee('VENÍAN GOLPEADOS')
        ->fillForm(['ver' => 'perdidas'])
        ->assertDontSee('en el orden en que se registraron')
        ->callAction('excel');

    Excel::assertDownloaded('devoluciones-perdidas-cop-'.Periodo::de('mes')->enArchivo().'.xlsx', function (ReporteDevolucionesExcel $excel): bool {
        return array_map(fn (HojaReporte $hoja): string => $hoja->title(), $excel->sheets()) === ['Pérdidas por proveedor', 'Pérdidas'];
    });

    $this->get(route('reportes.devoluciones.imprimir', ['desde' => now()->startOfMonth()->toDateString(), 'hasta' => now()->toDateString()]))
        ->assertOk()
        ->assertSee('Devoluciones y pérdidas')
        ->assertSee('BODEGA COPÁN')
        ->assertSee('L. 138.00');

    $this->actingAs($this->vendedor);

    $this->get(ReporteDevoluciones::getUrl())->assertForbidden();
    $this->get(route('reportes.devoluciones.imprimir'))->assertForbidden();
});
