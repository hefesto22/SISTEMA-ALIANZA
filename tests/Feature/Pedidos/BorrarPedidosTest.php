<?php

declare(strict_types=1);

use App\Domain\Devoluciones\RegistrarDevolucion;
use App\Domain\Inventario\MoverExistencia;
use App\Domain\Inventario\RegistrarCompra;
use App\Domain\Pedidos\CorregirPedido;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarDespacho;
use App\Enums\EstadoFactura;
use App\Enums\FrecuenciaComision;
use App\Enums\Rol;
use App\Enums\TipoDevolucion;
use App\Enums\TipoMovimiento;
use App\Models\Bodega;
use App\Models\Cai;
use App\Models\Cliente;
use App\Models\ComisionFactura;
use App\Models\ComisionPeriodo;
use App\Models\Devolucion;
use App\Models\Existencia;
use App\Models\Factura;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\User;

/**
 * php artisan pedidos:borrar: borra pedidos de prueba como si nunca se
 * hubieran levantado. Lo que salió regresa a la bodega, el kárdex queda
 * cuadrado, las facturas del CAI de prueba se van y el CAI vuelve a su
 * primer número. Las facturas de un CAI del SAR y las que ya están en un
 * periodo de comisiones cerrado no se borran.
 */
beforeEach(function (): void {
    $this->bodega = Bodega::factory()->create(['codigo' => 'COP']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Rol::Administrador->value);
    $this->encargado = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->encargado->assignRole(Rol::Bodega->value);
    $this->vendedor = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->vendedor->assignRole(Rol::Vendedor->value);

    // Jabón exento en UN y CJ48: hay 10 CJ48 y 5 UN.
    $this->jabon = Producto::factory()->exento()->precio(6.0, 7.5)->conCaja(48)->create(['margen' => 0.20]);
    $this->un = presentacion($this->jabon);
    $this->cj48 = presentacion($this->jabon, 48);

    app(RegistrarCompra::class)->ejecutar($this->bodega, $this->jabon->proveedor, [
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->cj48->id, 'cantidad' => 10, 'costo' => 312.0],
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->un->id, 'cantidad' => 5, 'costo' => 6.5],
    ], $this->admin);

    $this->cai = facturacionLista();
    $this->cai->update(['cai' => Cai::PRUEBA]);
});

/**
 * Un pedido de $cantidad UN del jabón, pendiente.
 */
function pedidoDeJabon(Bodega $bodega, User $vendedor, Presentacion $un, int $cantidad): Pedido
{
    $pedido = Pedido::query()->create([
        'bodega_id'   => $bodega->id,
        'cliente_id'  => Cliente::factory()->create(['bodega_id' => $bodega->id])->id,
        'vendedor_id' => $vendedor->id,
        'fecha'       => now(),
    ]);
    $pedido->items()->create(['producto_id' => $un->producto_id, 'presentacion_id' => $un->id, 'cantidad' => $cantidad]);

    return app(RecalcularPedido::class)->ejecutar($pedido);
}

test('borra impresos y pendientes: lo que salió regresa, el kárdex cuadra y el CAI de prueba vuelve a empezar', function (): void {
    // Impreso: salen 8 UN (se abre 1 CJ48), se imprime, se corrige a 6 y se reimprime.
    $impreso = app(ValidarDespacho::class)->ejecutar(pedidoDeJabon($this->bodega, $this->vendedor, $this->un, 8), [], $this->encargado, abrir: [$this->cj48->id => 1]);
    imprimir($impreso, $this->encargado);
    app(CorregirPedido::class)->ejecutar($impreso, [$impreso->items()->sole()->id => 6], $this->encargado, 'SALEN MENOS');
    imprimir($impreso, $this->encargado);

    // Después, un ajuste a mano que no es de ningún pedido, y un pendiente.
    app(MoverExistencia::class)->ejecutar($this->bodega->id, $this->un, 2, TipoMovimiento::Ajuste, $this->admin);
    pedidoDeJabon($this->bodega, $this->vendedor, $this->un, 3);

    expect(Existencia::de($this->bodega->id, $this->un->id))->toBe(5 + 48 - 6 + 2)
        ->and(Existencia::de($this->bodega->id, $this->cj48->id))->toBe(9)
        ->and(Factura::query()->orderBy('id')->pluck('estado')->all())->toBe([EstadoFactura::Anulada, EstadoFactura::Vigente])
        ->and($this->cai->fresh()?->siguiente)->toBe($this->cai->rango_desde + 2);

    // Cada texto esperado no debe caber dentro de otro: el primero que coincide se queda con la línea.
    $this->artisan('pedidos:borrar')
        ->expectsOutputToContain('2 facturas: '.Factura::query()->orderBy('numero')->pluck('numero')->implode(', '))
        ->expectsConfirmation('¿Borrar los 2 pedidos? No se puede deshacer.', 'yes')
        ->expectsOutputToContain('Listo. Borrados: 2 pedidos, 2 facturas y 4 renglones del kárdex.')
        ->assertSuccessful();

    $kardex = fn (Presentacion $p): array => MovimientoInventario::query()->where('presentacion_id', $p->id)->orderBy('id')->get()
        ->map(fn (MovimientoInventario $m): array => [$m->tipo, $m->cantidad, $m->existencia])->all();

    // Como si no hubieran existido: la compra y el ajuste, con la existencia que les toca.
    expect(Pedido::query()->count())->toBe(0)
        ->and(PedidoItem::query()->count())->toBe(0)
        ->and(Factura::query()->count())->toBe(0)
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe(7)
        ->and(Existencia::de($this->bodega->id, $this->cj48->id))->toBe(10)
        ->and($kardex($this->un))->toBe([[TipoMovimiento::Compra, 5, 5], [TipoMovimiento::Ajuste, 2, 7]])
        ->and($kardex($this->cj48))->toBe([[TipoMovimiento::Compra, 10, 10]])
        ->and($this->cai->fresh()?->siguiente)->toBe($this->cai->rango_desde)
        ->and(Cliente::query()->count())->toBe(2);

    // La numeración empieza otra vez.
    expect(pedidoDeJabon($this->bodega, $this->vendedor, $this->un, 1)->numero)->toEndWith('-000001');
});

test('con números borra solo esos y el CAI sigue después de la última factura que queda', function (): void {
    $uno = app(ValidarDespacho::class)->ejecutar(pedidoDeJabon($this->bodega, $this->vendedor, $this->un, 2), [], $this->encargado);
    $dos = app(ValidarDespacho::class)->ejecutar(pedidoDeJabon($this->bodega, $this->vendedor, $this->un, 3), [], $this->encargado);
    imprimir($uno, $this->encargado);
    imprimir($dos, $this->encargado);

    $this->artisan('pedidos:borrar', ['pedidos' => [mb_strtolower((string) $dos->numero)], '--force' => true])
        ->expectsOutputToContain('Listo. Borrados: 1 pedido, 1 factura y 1 renglón del kárdex.')
        ->assertSuccessful();

    expect(Pedido::query()->pluck('id')->all())->toBe([$uno->id])
        ->and(Factura::query()->sole()->pedido_id)->toBe($uno->id)
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe(3)
        ->and($this->cai->fresh()?->siguiente)->toBe($this->cai->rango_desde + 1);

    $this->artisan('pedidos:borrar', ['pedidos' => ['COP-999999']])
        ->expectsOutputToContain('No se encontraron: COP-999999.')
        ->assertFailed();
});

test('si dice que no, no borra nada', function (): void {
    pedidoDeJabon($this->bodega, $this->vendedor, $this->un, 2);

    $this->artisan('pedidos:borrar')
        ->expectsConfirmation('¿Borrar el pedido? No se puede deshacer.', 'no')
        ->expectsOutputToContain('No se borró nada.')
        ->assertSuccessful();

    expect(Pedido::query()->count())->toBe(1);
});

test('no borra facturas de un CAI del SAR ni las que ya están en un periodo de comisiones cerrado', function (): void {
    $pedido = app(ValidarDespacho::class)->ejecutar(pedidoDeJabon($this->bodega, $this->vendedor, $this->un, 2), [], $this->encargado);
    imprimir($pedido, $this->encargado);

    $periodo = ComisionPeriodo::query()->create(['desde' => today()->startOfMonth(), 'hasta' => today()->endOfMonth(), 'frecuencia' => FrecuenciaComision::Mensual, 'cerrado_at' => now()]);
    ComisionFactura::query()->create(['comision_periodo_id' => $periodo->id, 'factura_id' => Factura::query()->sole()->id, 'vendedor_id' => $this->vendedor->id, 'tipo' => ComisionFactura::VENTA, 'neta' => 15.0]);

    $this->artisan('pedidos:borrar', ['--force' => true])
        ->expectsOutputToContain('reabra ese periodo en Comisiones')
        ->assertFailed();

    $periodo->delete();
    Factura::query()->update(['cai' => 'ABCDEF-123456-ABCDEF-123456-ABCDEF-12']);

    $this->artisan('pedidos:borrar', ['--force' => true])
        ->expectsOutputToContain('CAI del SAR')
        ->assertFailed();

    expect(Pedido::query()->count())->toBe(1)
        ->and(Factura::query()->count())->toBe(1)
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe(3);
});

test('las notas de crédito del CAI de prueba se borran con su pedido y ese CAI vuelve a empezar; las de un CAI del SAR no', function (): void {
    $notas = Cai::query()->create([
        'cai'             => Cai::PRUEBA_NOTAS,
        'establecimiento' => '000',
        'punto_emision'   => '999',
        'tipo_documento'  => Cai::NOTA_CREDITO,
        'rango_desde'     => 1,
        'rango_hasta'     => 5000,
        'fecha_limite'    => today()->addYear(),
    ]);

    // Salen 4 UN y regresa 1 buena, a crédito: sale la nota del CAI de prueba.
    $pedido = app(ValidarDespacho::class)->ejecutar(pedidoDeJabon($this->bodega, $this->vendedor, $this->un, 4), [], $this->encargado);
    imprimir($pedido, $this->encargado);
    $devolucion = app(RegistrarDevolucion::class)->ejecutar($pedido, TipoDevolucion::Credito, [$pedido->items()->sole()->id => ['buenas' => 1]], $this->encargado);

    expect($devolucion->nota_numero)->toBe('000-999-07-00000001')
        ->and($notas->fresh()?->siguiente)->toBe(2)
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe(2);

    // Si la nota fuera de un CAI del SAR, no se borra.
    Devolucion::query()->update(['cai' => 'ABCDEF-123456-ABCDEF-123456-ABCDEF-07']);

    $this->artisan('pedidos:borrar', ['--force' => true])
        ->expectsOutputToContain('nota de crédito')
        ->assertFailed();

    Devolucion::query()->update(['cai' => Cai::PRUEBA_NOTAS]);

    $this->artisan('pedidos:borrar', ['--force' => true])
        ->assertSuccessful();

    expect(Pedido::query()->count())->toBe(0)
        ->and(Devolucion::query()->count())->toBe(0)
        ->and($notas->fresh()?->siguiente)->toBe(1)
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe(5);
});
