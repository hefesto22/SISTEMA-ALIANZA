<?php

declare(strict_types=1);

use App\Domain\Comisiones\CerrarPeriodoComision;
use App\Domain\Comisiones\ComisionesDelPeriodo;
use App\Domain\Comisiones\Escala;
use App\Domain\Comisiones\GuardarEscalaBodega;
use App\Domain\Comisiones\GuardarGrupoComision;
use App\Domain\Comisiones\GuardarMetaComision;
use App\Domain\Comisiones\PeriodoComision;
use App\Domain\Comisiones\ReabrirPeriodoComision;
use App\Domain\Comisiones\ResumenVendedor;
use App\Domain\Devoluciones\RegistrarDevolucion;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Facturacion\AnularFactura;
use App\Domain\Pedidos\CorregirPedido;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarDespacho;
use App\Domain\Reportes\KpisVentas;
use App\Domain\Reportes\Periodo;
use App\Enums\FrecuenciaComision;
use App\Enums\ModoImpresion;
use App\Enums\Rol;
use App\Enums\TipoDevolucion;
use App\Exports\ComisionesExcel;
use App\Filament\Pages\Comisiones;
use App\Filament\Pages\KpisVentas as PaginaKpis;
use App\Filament\Pages\MisMetas;
use App\Filament\Resources\ComisionGrupos\Pages\ManageComisionGrupos;
use App\Filament\Resources\ComisionMetas\ComisionMetaResource;
use App\Filament\Widgets\MisMetasWidget;
use App\Models\Bodega;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ComisionEscalaBodega;
use App\Models\ComisionGrupo;
use App\Models\ComisionLiquidacion;
use App\Models\ComisionPeriodo;
use App\Models\Configuracion;
use App\Models\Municipio;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Comisiones por grupo sobre la venta neta FACTURADA (sin ISV, ya con
 * descuentos): se paga el % del escalón alcanzado sobre toda la venta del
 * grupo. Quincenal (1-14 y 15-fin de mes) o mensual; al cerrar queda lo
 * pagado y lo anulado después se descuenta en el siguiente. La meta es del
 * mes: la primera quincena es adelanto y la segunda completa el mes. Las
 * devoluciones con crédito bajan la venta (se paga del 1 al 5 del mes
 * siguiente para descontarlas); las de después del cierre, en el siguiente.
 *
 * LIMPIEZA: 0.5% desde 0, 1% desde 800, 2% desde 1,000 (montos chicos para
 * las cuentas). El jabón (LIMPIEZA, gravado) a L 100 sin ISV; el arroz
 * (sin grupo, exento) a L 50.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00'));
    facturacionLista();

    $this->copan = Bodega::factory()->create(['codigo' => 'COP', 'nombre' => 'BODEGA COPÁN']);
    $this->ocote = Bodega::factory()->create(['codigo' => 'OCO', 'nombre' => 'BODEGA OCOTEPEQUE']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Rol::Administrador->value);
    $this->encargado = User::factory()->create(['bodega_id' => $this->copan->id]);
    $this->encargado->assignRole(Rol::Bodega->value);

    $this->ana = vendedorDeComision('ANA', 'V01', $this->copan);
    $this->beto = vendedorDeComision('BETO', 'V02', $this->copan);
    $this->carla = vendedorDeComision('CARLA', 'V03', $this->ocote);

    $detergentes = Categoria::factory()->create(['nombre' => 'DETERGENTES']);
    $alimentos = Categoria::factory()->create(['nombre' => 'ALIMENTOS']);
    $this->jabon = Producto::factory()->precio(80.0, 100.0)->create(['categoria_id' => $detergentes->id]);
    $this->arroz = Producto::factory()->exento()->precio(40.0, 50.0)->create(['categoria_id' => $alimentos->id]);
    $this->detergentes = $detergentes;

    $this->limpieza = app(GuardarGrupoComision::class)->ejecutar(null, [
        'nombre'     => 'Limpieza',
        'escalones'  => [['desde' => 0, 'porcentaje' => 0.5], ['desde' => 800, 'porcentaje' => 1], ['desde' => 1000, 'porcentaje' => 2]],
        'categorias' => [$detergentes->id],
    ]);
});

function vendedorDeComision(string $nombre, string $codigo, Bodega $bodega): User
{
    $vendedor = User::factory()->create(['name' => $nombre, 'codigo' => $codigo, 'bodega_id' => $bodega->id]);
    $vendedor->assignRole(Rol::Vendedor->value);

    return $vendedor;
}

/**
 * Pedido del vendedor, despachado e impreso (con su factura): [[producto, unidades], …].
 *
 * @param list<array{0: Producto, 1: int}> $lineas
 */
function facturaDeComision(object $t, User $vendedor, array $lineas, ?Cliente $cliente = null): Pedido
{
    $pedido = Pedido::query()->create([
        'bodega_id'   => $vendedor->bodega_id,
        'cliente_id'  => ($cliente ?? Cliente::factory()->create(['bodega_id' => $vendedor->bodega_id]))->id,
        'vendedor_id' => $vendedor->id,
        'fecha'       => now(),
    ]);

    foreach ($lineas as [$producto, $cantidad]) {
        $pedido->items()->create(['producto_id' => $producto->id, 'presentacion_id' => presentacion($producto)->id, 'cantidad' => $cantidad]);
    }

    app(RecalcularPedido::class)->ejecutar($pedido);
    $pedido = app(ValidarDespacho::class)->ejecutar($pedido, [], $t->admin);
    imprimir($pedido, $t->admin, ModoImpresion::Originales);

    return $pedido->fresh() ?? $pedido;
}

function comisionDe(PeriodoComision $periodo, User $vendedor): ResumenVendedor
{
    foreach ((new ComisionesDelPeriodo($periodo))->vendedores() as $resumen) {
        if ($resumen->id === $vendedor->id) {
            return $resumen;
        }
    }

    throw new RuntimeException("{$vendedor->name} no está en las comisiones del {$periodo->texto()}.");
}

function quincena(string $fecha): PeriodoComision
{
    return PeriodoComision::para(CarbonImmutable::parse($fecha), FrecuenciaComision::Quincenal, 14);
}

test('la escala: el % del escalón alcanzado sobre toda la venta, la meta y lo que falta', function (): void {
    $escala = Escala::de([['desde' => '100,000', 'porcentaje' => 2], ['desde' => 0, 'porcentaje' => 0.5], ['desde' => 80000, 'porcentaje' => 1]]);

    expect($escala->comision(105000))->toBe(2100.0)
        ->and($escala->porcentaje(99999.99))->toBe(1.0)
        ->and($escala->comision(70000))->toBe(350.0)
        ->and($escala->meta())->toBe(100000.0)
        ->and($escala->siguiente(85000))->toBe(['desde' => 100000.0, 'porcentaje' => 2.0])
        ->and($escala->texto())->toBe('0.5% · 1% desde L. 80,000 · 2% desde L. 100,000')
        ->and(Escala::error([['desde' => 0, 'porcentaje' => 1], ['desde' => '0', 'porcentaje' => 2]]))->toBe('Hay dos escalones desde L. 0.')
        ->and(Escala::error([]))->toBe('Agregue al menos un escalón.');
});

test('se cuenta la venta sin ISV y el % del escalón alcanzado se paga sobre toda la venta del grupo', function (): void {
    // ANA: 10 jabones (1,000 + 150 de ISV) y 4 arroces (200, sin grupo). BETO: 7 jabones (700).
    facturaDeComision($this, $this->ana, [[$this->jabon, 10], [$this->arroz, 4]]);
    facturaDeComision($this, $this->beto, [[$this->jabon, 7]]);

    $periodo = PeriodoComision::actual();
    $ana = comisionDe($periodo, $this->ana);
    $beto = comisionDe($periodo, $this->beto);

    expect($ana->ventaTotal)->toBe(1200.0)
        ->and($ana->filas)->toHaveCount(1)
        ->and($ana->filas[0]->base)->toBe(1000.0)
        ->and($ana->filas[0]->porcentaje)->toBe(2.0)
        ->and($ana->filas[0]->comision)->toBe(20.0)
        ->and($ana->filas[0]->llegoALaMeta())->toBeTrue()
        ->and($beto->filas[0]->porcentaje)->toBe(0.5)
        ->and($beto->filas[0]->comision)->toBe(3.5)
        ->and($beto->filas[0]->faltante())->toBe(100.0)
        ->and((new ComisionesDelPeriodo($periodo))->totales())->toBe(['venta' => 1900.0, 'base' => 1700.0, 'comision' => 23.5, 'facturas' => 2, 'vendedores' => 2])
        // CARLA no vendió: aparece en cero (ve sus metas).
        ->and(comisionDe($periodo, $this->carla)->comision())->toBe(0.0)
        // Solo Copán.
        ->and(array_map(fn (ResumenVendedor $v): string => (string) $v->codigo, (new ComisionesDelPeriodo($periodo, $this->copan->id))->vendedores()))->toBe(['V01', 'V02']);
});

test('un vendedor puede tener su propia escala; el encargado solo se la pone a los de su bodega', function (): void {
    app(GuardarMetaComision::class)->ejecutar(null, $this->limpieza->id, $this->beto->id, [['desde' => 0, 'porcentaje' => 1], ['desde' => 500, 'porcentaje' => 3]], $this->encargado);

    expect(fn () => app(GuardarMetaComision::class)->ejecutar(null, $this->limpieza->id, $this->carla->id, [['desde' => 0, 'porcentaje' => 1]], $this->encargado))
        ->toThrow(ReglaNegocioException::class, 'su bodega')
        ->and(fn () => app(GuardarMetaComision::class)->ejecutar(null, $this->limpieza->id, $this->beto->id, [['desde' => 0, 'porcentaje' => 2]], $this->admin))
        ->toThrow(ReglaNegocioException::class, 'ya tiene su meta');

    facturaDeComision($this, $this->beto, [[$this->jabon, 7]]);
    $beto = comisionDe(PeriodoComision::actual(), $this->beto);

    expect($beto->filas[0]->propia)->toBeTrue()
        ->and($beto->filas[0]->porcentaje)->toBe(3.0)
        ->and($beto->filas[0]->comision)->toBe(21.0);
});

test('cada bodega pone sus montos del grupo (si no, usa los del grupo); el encargado solo los de su bodega; la meta propia manda', function (): void {
    // Copán: 1% desde 0 y 3% desde 500 (allí se vende menos). Ocotepeque usa la del grupo.
    $this->actingAs($this->encargado);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(ManageComisionGrupos::class)
        ->callTableAction('bodega', $this->limpieza, data: [
            'propia'    => true,
            'escalones' => [['desde' => '0', 'porcentaje' => 1], ['desde' => '500', 'porcentaje' => 3]],
        ])
        ->assertHasNoTableActionErrors();

    $undoRepeaterFake();

    expect(ComisionEscalaBodega::query()->sole()->bodega_id)->toBe($this->copan->id)
        ->and(fn () => app(GuardarEscalaBodega::class)->ejecutar($this->limpieza->id, $this->ocote->id, [['desde' => 0, 'porcentaje' => 9]], $this->encargado))
        ->toThrow(ReglaNegocioException::class, 'su bodega');

    // ANA (Copán), CARLA (Ocotepeque) y BETO (Copán, con meta propia) venden 700 cada uno.
    app(GuardarMetaComision::class)->ejecutar(null, $this->limpieza->id, $this->beto->id, [['desde' => 0, 'porcentaje' => 2]], $this->admin);
    facturaDeComision($this, $this->ana, [[$this->jabon, 7]]);
    facturaDeComision($this, $this->carla, [[$this->jabon, 7]]);
    facturaDeComision($this, $this->beto, [[$this->jabon, 7]]);

    $periodo = PeriodoComision::actual();
    $ana = comisionDe($periodo, $this->ana)->filas[0];
    $carla = comisionDe($periodo, $this->carla)->filas[0];
    $beto = comisionDe($periodo, $this->beto)->filas[0];

    expect([$ana->porcentaje, $ana->comision, $ana->deBodega, $ana->meta])->toBe([3.0, 21.0, true, 500.0])
        ->and([$carla->porcentaje, $carla->deBodega])->toBe([0.5, false])
        ->and([$beto->porcentaje, $beto->propia, $beto->deBodega])->toBe([2.0, true, false]);

    // Administración la quita: Copán vuelve a la del grupo.
    app(GuardarEscalaBodega::class)->ejecutar($this->limpieza->id, $this->copan->id, null, $this->admin);

    expect(ComisionEscalaBodega::query()->count())->toBe(0)
        ->and(comisionDe($periodo, $this->ana)->filas[0]->porcentaje)->toBe(0.5);
});

test('quincenas del 1 al 14 y del 15 a fin de mes (por la fecha de la factura); o mensual', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 23:30:00'));
    facturaDeComision($this, $this->ana, [[$this->jabon, 10]]);
    $this->travelTo(CarbonImmutable::parse('2026-11-15 08:00:00'));
    facturaDeComision($this, $this->ana, [[$this->jabon, 5]]);

    expect(comisionDe(quincena('2026-11-01'), $this->ana)->filas[0]->base)->toBe(1000.0)
        ->and(comisionDe(quincena('2026-11-20'), $this->ana)->filas[0]->base)->toBe(500.0)
        ->and(PeriodoComision::actual()->texto())->toBe('15 al 30 de noviembre de 2026');

    Configuracion::actual()->update(['comision_frecuencia' => FrecuenciaComision::Mensual]);

    expect(PeriodoComision::actual()->clave())->toBe('2026-11-01_2026-11-30')
        ->and(comisionDe(PeriodoComision::actual(), $this->ana)->comision())->toBe(30.0);
});

test('al cerrar queda lo pagado; lo que se anula después se descuenta en el siguiente y la factura que la sustituye entra en el siguiente', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 09:00:00'));
    $pedido = facturaDeComision($this, $this->ana, [[$this->jabon, 10]]);
    $q1 = quincena('2026-11-01');

    $this->travelTo(CarbonImmutable::parse('2026-11-14 17:00:00'));
    $cierre = app(CerrarPeriodoComision::class)->ejecutar($q1, $this->admin, 'pagado');

    expect($cierre->comision)->toBe(20.0)
        ->and(ComisionLiquidacion::query()->where('comision_periodo_id', $cierre->id)->sole()->porcentaje)->toBe(2.0);

    // Cambiar la escala no toca lo pagado.
    $this->limpieza->update(['escalones' => [['desde' => 0, 'porcentaje' => 10]]]);
    expect(comisionDe($q1, $this->ana)->comision())->toBe(20.0);
    $this->limpieza->update(['escalones' => [['desde' => 0, 'porcentaje' => 0.5], ['desde' => 800, 'porcentaje' => 1], ['desde' => 1000, 'porcentaje' => 2]]]);

    // Esa misma tarde (ya cerrado) regresan 2 jabones que no se entregaron: se
    // corrige el pedido, se anula la factura (1,000, ya pagada) y sale otra por 800.
    $this->travelTo(CarbonImmutable::parse('2026-11-14 18:00:00'));
    app(CorregirPedido::class)->ejecutar($pedido, [$pedido->items()->sole()->id => 8], $this->admin, 'no se entregaron 2');
    imprimir($pedido->fresh() ?? $pedido, $this->admin, ModoImpresion::Originales);
    // Y el 20 vende 15 más (1,500).
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00'));
    facturaDeComision($this, $this->ana, [[$this->jabon, 15]]);

    $q2 = PeriodoComision::actual();
    $ana = comisionDe($q2, $this->ana);

    // 800 (rezagada: con fecha del 14, se emitió después de cerrar) + 1,500 − 1,000 = 1,300 → 2%.
    expect($ana->filas[0]->venta)->toBe(2300.0)
        ->and($ana->filas[0]->anulado)->toBe(1000.0)
        ->and($ana->filas[0]->base)->toBe(1300.0)
        ->and($ana->filas[0]->comision)->toBe(26.0)
        ->and((new ComisionesDelPeriodo($q2))->anuladas())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-11-30 17:00:00'));
    app(CerrarPeriodoComision::class)->ejecutar($q2, $this->admin);

    // Ya descontada: no se vuelve a descontar.
    expect((new ComisionesDelPeriodo(quincena('2026-12-01')))->anuladas())->toBe(0);
});

test('las devoluciones con crédito bajan la venta: las del mes si se registran antes de cerrar (del 1 al 5) y las de después en el siguiente cierre; un cambio no', function (): void {
    Configuracion::actual()->update(['notas_credito' => false, 'comision_frecuencia' => FrecuenciaComision::Mensual]);
    $noviembre = PeriodoComision::para(CarbonImmutable::parse('2026-11-01'), FrecuenciaComision::Mensual, 14);
    $pedido = facturaDeComision($this, $this->ana, [[$this->jabon, 12]]);
    $linea = $pedido->items()->sole();

    // El 2 de diciembre, antes de cerrar noviembre, regresan 3 dañados con crédito y 1 por cambio.
    $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00:00'));
    app(RegistrarDevolucion::class)->ejecutar($pedido, TipoDevolucion::Credito, [$linea->id => ['danadas' => 3]], $this->admin);
    app(RegistrarDevolucion::class)->ejecutar($pedido, TipoDevolucion::Cambio, [$linea->id => ['danadas' => 1]], $this->admin);

    // 1,200 − 300 = 900 → 1 %.
    $ana = comisionDe($noviembre, $this->ana);
    expect($ana->filas[0]->venta)->toBe(1200.0)
        ->and($ana->filas[0]->devuelto)->toBe(300.0)
        ->and($ana->filas[0]->base)->toBe(900.0)
        ->and($ana->filas[0]->comision)->toBe(9.0)
        ->and($ana->ventaTotal)->toBe(900.0)
        ->and((new ComisionesDelPeriodo($noviembre))->devoluciones())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-12-03 09:00:00'));
    $cierre = app(CerrarPeriodoComision::class)->ejecutar($noviembre, $this->admin);
    $ana = comisionDe($noviembre, $this->ana);

    expect($cierre->comision)->toBe(9.0)
        ->and(ComisionLiquidacion::query()->where('comision_periodo_id', $cierre->id)->sole()->devuelto)->toBe(300.0)
        ->and($ana->filas[0]->devuelto)->toBe(300.0)
        ->and($ana->ventaTotal)->toBe(900.0)
        ->and((new ComisionesDelPeriodo($noviembre))->devoluciones())->toBe(1);

    // Ya cerrado, el 10 regresan 2 más con crédito y vende 5 (500): esos 200 se descuentan en diciembre.
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00'));
    app(RegistrarDevolucion::class)->ejecutar($pedido, TipoDevolucion::Credito, [$linea->id => ['danadas' => 2]], $this->admin);
    facturaDeComision($this, $this->ana, [[$this->jabon, 5]]);

    $diciembre = PeriodoComision::actual();
    $ana = comisionDe($diciembre, $this->ana);

    expect($diciembre->clave())->toBe('2026-12-01_2026-12-31')
        ->and($ana->filas[0]->venta)->toBe(500.0)
        ->and($ana->filas[0]->devuelto)->toBe(200.0)
        ->and($ana->filas[0]->base)->toBe(300.0)
        ->and($ana->filas[0]->comision)->toBe(1.5)
        ->and((new ComisionesDelPeriodo($diciembre))->devoluciones())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2027-01-02 09:00:00'));
    app(CerrarPeriodoComision::class)->ejecutar($diciembre, $this->admin);

    // Ya descontadas: no se vuelven a descontar.
    expect((new ComisionesDelPeriodo(PeriodoComision::actual()))->devoluciones())->toBe(0);
});

test('si se anula una factura pagada que ya tenía una devolución descontada, solo se descuenta lo que quedaba', function (): void {
    Configuracion::actual()->update(['notas_credito' => false]);
    $pedido = facturaDeComision($this, $this->ana, [[$this->jabon, 12]]);
    app(RegistrarDevolucion::class)->ejecutar($pedido, TipoDevolucion::Credito, [$pedido->items()->sole()->id => ['danadas' => 3]], $this->admin);

    $this->travelTo(CarbonImmutable::parse('2026-11-14 17:00:00'));
    app(CerrarPeriodoComision::class)->ejecutar(quincena('2026-11-01'), $this->admin);

    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00'));
    app(AnularFactura::class)->ejecutar($pedido->factura ?? throw new RuntimeException('Sin factura.'), $this->admin, 'prueba');
    facturaDeComision($this, $this->ana, [[$this->jabon, 15]]);

    // 1,500 − (1,200 − 300) = 600; el mes: 900 + 600 = 1,500 → 2 % = 30, menos los 9 adelantados.
    $ana = comisionDe(quincena('2026-11-20'), $this->ana);
    expect($ana->filas[0]->anulado)->toBe(900.0)
        ->and($ana->filas[0]->devuelto)->toBe(0.0)
        ->and($ana->filas[0]->base)->toBe(600.0)
        ->and($ana->filas[0]->comision)->toBe(21.0);
});

test('la meta es del mes: la primera quincena se paga como adelanto y a fin de mes se completa con el escalón del mes', function (): void {
    // Ya se venían cerrando comisiones (octubre cerrado): la primera quincena se tiene que cerrar antes.
    app(CerrarPeriodoComision::class)->ejecutar(quincena('2026-10-20'), $this->admin);

    // Del 1 al 14 vende 300 (0.5%); del 15 al 30, 800: el mes va en 1,100 → 2%.
    facturaDeComision($this, $this->ana, [[$this->jabon, 3]]);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00'));
    facturaDeComision($this, $this->ana, [[$this->jabon, 8]]);

    $q1 = quincena('2026-11-01');
    $q2 = quincena('2026-11-15');
    $delMes = comisionDe($q2, $this->ana)->filas[0];

    expect(comisionDe($q1, $this->ana)->filas[0]->comision)->toBe(1.5)
        // La primera todavía no se cierra: se suma así como va.
        ->and((new ComisionesDelPeriodo($q2))->anteriorDelMes()['estado'] ?? null)->toBe(ComisionesDelPeriodo::ANTERIOR_POR_CERRAR)
        ->and([$delMes->base, $delMes->baseMes(), $delMes->porcentaje, $delMes->comisionMes(), $delMes->comision])->toBe([800.0, 1100.0, 2.0, 22.0, 20.5])
        ->and($delMes->llegoALaMeta())->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-11-30 17:00:00'));

    expect(fn () => app(CerrarPeriodoComision::class)->ejecutar($q2, $this->admin))
        ->toThrow(ReglaNegocioException::class, 'Primero cierre la quincena del 1 al 14 de noviembre de 2026');

    $primera = app(CerrarPeriodoComision::class)->ejecutar($q1, $this->admin);
    $segunda = app(CerrarPeriodoComision::class)->ejecutar($q2, $this->admin);
    $liquidacion = ComisionLiquidacion::query()->where('comision_periodo_id', $segunda->id)->sole();

    expect($primera->comision)->toBe(1.5)
        ->and($segunda->comision)->toBe(20.5)
        ->and([$liquidacion->base, $liquidacion->base_antes, $liquidacion->pagado_antes, $liquidacion->porcentaje])->toBe([800.0, 300.0, 1.5, 2.0])
        // En el mes se le pagó el 2% de 1,100.
        ->and($primera->comision + $segunda->comision)->toBe(22.0);

    $this->actingAs($this->ana);
    Livewire::test(MisMetas::class)
        ->fillForm(['periodo' => $q2->clave()])
        ->assertSee('L. 1,100.00')
        ->assertSee('ya se le adelantaron');
});

test('lo que se factura después de cerrar con fecha de ese periodo pasa al siguiente', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00'));
    facturaDeComision($this, $this->ana, [[$this->jabon, 10]]);
    app(CerrarPeriodoComision::class)->ejecutar(quincena('2026-11-01'), $this->admin);

    $this->travelTo(CarbonImmutable::parse('2026-11-14 16:00:00'));
    facturaDeComision($this, $this->ana, [[$this->jabon, 3]]);

    $q2 = new ComisionesDelPeriodo(quincena('2026-11-15'));

    expect($q2->rezagadas())->toBe(1)
        ->and(comisionDe(quincena('2026-11-15'), $this->ana)->filas[0]->base)->toBe(300.0)
        ->and(comisionDe(quincena('2026-11-01'), $this->ana)->filas[0]->base)->toBe(1000.0);
});

test('no se cierra antes del último día, dos veces ni uno anterior a otro cerrado; se reabre solo el último', function (): void {
    $q1 = quincena('2026-11-01');

    expect(fn () => app(CerrarPeriodoComision::class)->ejecutar($q1, $this->admin))
        ->toThrow(ReglaNegocioException::class, 'se cierra ese día o después');

    $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00:00'));
    $cierre1 = app(CerrarPeriodoComision::class)->ejecutar($q1, $this->admin);
    $cierre2 = app(CerrarPeriodoComision::class)->ejecutar(quincena('2026-11-15'), $this->admin);

    expect(fn () => app(CerrarPeriodoComision::class)->ejecutar($q1, $this->admin))->toThrow(ReglaNegocioException::class, 'ya está cerrado')
        ->and(fn () => app(CerrarPeriodoComision::class)->ejecutar(quincena('2026-10-20'), $this->admin))->toThrow(ReglaNegocioException::class, 'periodo posterior')
        ->and(fn () => app(ReabrirPeriodoComision::class)->ejecutar($cierre1))->toThrow(ReglaNegocioException::class, 'último');

    app(ReabrirPeriodoComision::class)->ejecutar($cierre2);

    expect(ComisionPeriodo::query()->count())->toBe(1);
});

test('KPIs: venta sin ISV, ranking de vendedores con su cobertura, grupos y zonas', function (): void {
    $municipio = Municipio::factory()->create();
    Ruta::factory()->cubre($municipio)->create(['bodega_id' => $this->copan->id])->vendedores()->attach($this->ana->id);
    $clientes = Cliente::factory()->count(4)->create(['bodega_id' => $this->copan->id, 'municipio_id' => $municipio->id]);

    facturaDeComision($this, $this->ana, [[$this->jabon, 10]], $clientes[0]);
    facturaDeComision($this, $this->ana, [[$this->arroz, 4]], $clientes[1]);
    facturaDeComision($this, $this->carla, [[$this->jabon, 3]]);

    $kpis = new KpisVentas(Periodo::de('mes'), null);
    $vendedores = $kpis->vendedores();

    expect($kpis->resumen()['venta'])->toBe(1500.0)
        ->and($kpis->resumen()['facturas'])->toBe(3)
        ->and($kpis->resumen()['ticket'])->toBe(500.0)
        ->and(array_column($vendedores, 'nombre'))->toBe(['ANA', 'CARLA'])
        ->and($vendedores[0]['cartera'])->toBe(4)
        ->and($vendedores[0]['cobertura'])->toBe(0.5)
        ->and(array_map(fn (array $g): array => [$g['nombre'], $g['venta']], $kpis->grupos()))->toBe([['LIMPIEZA', 1300.0], ['SIN GRUPO', 200.0]])
        ->and($kpis->zonas()[0]['venta'])->toBe(1200.0)
        ->and((new KpisVentas(Periodo::de('mes'), $this->ocote))->resumen()['venta'])->toBe(300.0);
});

test('pantallas: administración cierra y descarga el Excel; el encargado ve a sus vendedores; el vendedor solo sus metas', function (): void {
    facturaDeComision($this, $this->ana, [[$this->jabon, 10]]);
    facturaDeComision($this, $this->carla, [[$this->jabon, 3]]);
    $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00:00'));
    $q1 = quincena('2026-11-01');
    Excel::fake();

    $this->actingAs($this->encargado);
    Livewire::test(Comisiones::class)
        ->fillForm(['periodo' => $q1->clave()])
        ->assertOk()
        ->assertSee('V01 · ANA')
        ->assertDontSee('V03 · CARLA')
        ->assertActionHidden('cerrar');

    $this->actingAs($this->admin);
    Livewire::test(Comisiones::class)
        ->fillForm(['periodo' => $q1->clave()])
        ->assertSee('V03 · CARLA')
        ->assertSee('L. 20.00')
        ->callAction('excel')
        ->callAction('cerrar', data: ['nota' => 'pagado el 15'])
        ->assertActionHidden('cerrar')
        ->assertActionVisible('reabrir');

    Excel::assertDownloaded('comisiones-2026-11-01_2026-11-14.xlsx', fn (ComisionesExcel $excel): bool => count($excel->sheets()) === 2);
    expect(ComisionPeriodo::query()->sole()->nota)->toBe('pagado el 15');

    $this->actingAs($this->ana);
    $this->get(Comisiones::getUrl())->assertForbidden();
    $this->get(PaginaKpis::getUrl())->assertForbidden();
    $this->get(MisMetas::getUrl())->assertOk();
    Livewire::test(MisMetas::class)
        ->fillForm(['periodo' => $q1->clave()])
        ->assertSee('LIMPIEZA')
        ->assertSee('L. 20.00')
        ->assertDontSee('CARLA');
    // En el escritorio: la quincena en curso (todavía sin venta).
    Livewire::test(MisMetasWidget::class)
        ->assertSee('Mis metas · 15 al 30 de noviembre de 2026')
        ->assertSee('LIMPIEZA');
});

test('grupos: se crean con sus categorías y su escala; una categoría va en un solo grupo', function (): void {
    $this->actingAs($this->admin);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(ManageComisionGrupos::class)
        ->callAction('create', data: [
            'nombre'     => 'Hogar',
            'activo'     => true,
            'categorias' => [$this->detergentes->id],
            // El monto llega con el separador de miles del campo.
            'escalones' => [['desde' => '0', 'porcentaje' => 1], ['desde' => '5,000', 'porcentaje' => 1.5]],
        ])
        ->assertHasNoActionErrors();

    Livewire::test(ManageComisionGrupos::class)
        ->callAction('create', data: [
            'nombre'    => 'Repetido',
            'activo'    => true,
            'escalones' => [['desde' => 0, 'porcentaje' => 1], ['desde' => 0, 'porcentaje' => 2]],
        ])
        ->assertHasActionErrors(['escalones']);

    $undoRepeaterFake();

    $hogar = ComisionGrupo::query()->where('nombre', 'HOGAR')->sole();

    expect($hogar->escala()->texto())->toBe('1% · 1.5% desde L. 5,000')
        // Se pasó de LIMPIEZA a HOGAR.
        ->and($this->detergentes->fresh()?->comision_grupo_id)->toBe($hogar->id)
        ->and($this->limpieza->categorias()->count())->toBe(0)
        // El encargado solo ve en Metas a los vendedores de su bodega.
        ->and((function (): array {
            $this->actingAs($this->encargado);

            return array_values(ComisionMetaResource::vendedores());
        })())->toBe(['V01 · ANA', 'V02 · BETO']);
});
