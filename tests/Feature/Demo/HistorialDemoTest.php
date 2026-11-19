<?php

declare(strict_types=1);

use App\Enums\EstadoPedido;
use App\Enums\Rol;
use App\Enums\TipoMovimiento;
use App\Models\Bodega;
use App\Models\Cai;
use App\Models\Cliente;
use App\Models\ComisionPeriodo;
use App\Models\Compra;
use App\Models\Existencia;
use App\Models\Factura;
use App\Models\MovimientoInventario;
use App\Models\Municipio;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\HistorialDemo;
use Database\Seeders\Demo\LimpiarMovimientos;
use Database\Seeders\FacturacionPruebaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * La operación de demostración (php artisan demo:historial) pasa por las
 * acciones de verdad: aquí, tres semanas de una bodega chica.
 */
beforeEach(function (): void {
    $sede = Municipio::factory()->create(['nombre' => 'SANTA ROSA']);
    Municipio::factory()->count(3)->create(['departamento_id' => $sede->departamento_id]);

    $this->bodega = Bodega::factory()->create(['codigo' => 'COP', 'municipio_id' => $sede->id]);
    User::factory()->create()->assignRole(Rol::Administrador->value);
    User::factory()->create(['bodega_id' => $this->bodega->id])->assignRole(Rol::Bodega->value);
    User::factory()->create(['name' => 'ANA LÓPEZ', 'codigo' => 'V01', 'bodega_id' => $this->bodega->id])->assignRole(Rol::Vendedor->value);
    User::factory()->create(['name' => 'MOSTRADOR SANTA ROSA', 'codigo' => 'V02', 'bodega_id' => $this->bodega->id])->assignRole(Rol::Vendedor->value);

    Producto::factory()->count(4)->precio(40.0, 50.0)->create();
    Producto::factory()->count(4)->precio(8.0, 10.0)->conCaja(24)->create();

    (new FacturacionPruebaSeeder)->run();
});

test('del 1 al 19: inventario inicial, compras, pedidos validados e impresos, comisiones cerradas y los de hoy pendientes', function (): void {
    $this->travelTo('2026-09-19 12:00:00');

    app(LimpiarMovimientos::class)->ejecutar();
    app(HistorialDemo::class)->generar(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-19'), ventaMes: 120_000);

    $impresos = Pedido::query()->where('estado', EstadoPedido::Impreso->value);
    $kardex = DB::selectOne('SELECT COUNT(*) AS n FROM existencias e LEFT JOIN (SELECT bodega_id, presentacion_id, SUM(cantidad) AS suma FROM movimientos_inventario GROUP BY 1, 2) m ON m.bodega_id = e.bodega_id AND m.presentacion_id = e.presentacion_id WHERE COALESCE(m.suma, 0) <> e.cantidad');

    // Cada vendedor con su ruta y sus clientes; el mostrador, en el municipio de la bodega.
    expect(Ruta::query()->count())->toBe(2)
        ->and(Ruta::query()->where('nombre', 'like', 'RUTA V02%')->sole()->municipios()->pluck('municipios.id')->all())->toBe([$this->bodega->municipio_id])
        ->and(Cliente::query()->where('bodega_id', $this->bodega->id)->count())->toBeGreaterThanOrEqual(45)
        // Inventario inicial y compras de lunes a viernes.
        ->and(MovimientoInventario::query()->where('tipo', TipoMovimiento::Ajuste->value)->where('nota', HistorialDemo::NOTA_INICIAL)->exists())->toBeTrue()
        ->and(Compra::query()->count())->toBeGreaterThan(0)
        // Lo que salió está impreso con su factura del CAI de prueba; lo de hoy (sábado 19) queda pendiente.
        ->and((clone $impresos)->count())->toBeGreaterThan(10)
        ->and(Factura::query()->whereNull('anulada_at')->where('cai', Cai::PRUEBA)->count())->toBe((clone $impresos)->count())
        ->and(Pedido::query()->where('estado', EstadoPedido::Pendiente->value)->where('fecha', '!=', '2026-09-19')->exists())->toBeFalse()
        ->and(Factura::query()->whereDate('fecha_emision', '2026-09-15')->exists())->toBeFalse()
        // El inventario cuadra y nunca queda en negativo.
        ->and(Existencia::query()->where('cantidad', '<', 0)->exists())->toBeFalse()
        ->and((int) $kardex->n)->toBe(0)
        // La 1.ª quincena se cerró (el 16: el 15 es feriado); la 2.ª sigue abierta.
        ->and(ComisionPeriodo::query()->count())->toBe(1)
        ->and(HistorialDemo::resumen(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-19'))[0]['venta'])->toBeGreaterThan(0.0);
});

test('limpiar deja la base sin operación y el CAI de prueba en su primer número; con documentos del SAR no se usa', function (): void {
    $this->travelTo('2026-09-08 12:00:00');
    app(LimpiarMovimientos::class)->ejecutar();
    app(HistorialDemo::class)->generar(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-08'), ventaMes: 60_000);

    expect(Factura::query()->exists())->toBeTrue();

    app(LimpiarMovimientos::class)->ejecutar();

    expect(array_sum(app(LimpiarMovimientos::class)->resumen()))->toBe(0)
        ->and(Existencia::query()->exists())->toBeFalse()
        ->and(Cai::query()->where('cai', Cai::PRUEBA)->value('siguiente'))->toBe(1);

    Cai::factory()->create();

    expect(app(LimpiarMovimientos::class)->impedimento())->toContain('CAI del SAR activos');
});

test('el comando pregunta antes de borrar y en producción no corre', function (): void {
    Pedido::query()->create(['bodega_id' => $this->bodega->id, 'cliente_id' => Cliente::factory()->create(['bodega_id' => $this->bodega->id])->id, 'vendedor_id' => User::query()->where('codigo', 'V01')->value('id'), 'fecha' => now()]);

    $this->artisan('demo:historial')
        ->expectsConfirmation('¿Seguir?', 'no')
        ->expectsOutputToContain('No se hizo nada.')
        ->assertSuccessful();

    expect(Pedido::query()->count())->toBe(1);

    app()->instance('env', 'production');

    $this->artisan('demo:historial', ['--force' => true])
        ->expectsOutputToContain('Esto es producción')
        ->assertFailed();
});
