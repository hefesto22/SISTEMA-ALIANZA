<?php

declare(strict_types=1);

use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarDespacho;
use App\Enums\EstadoPedido;
use App\Enums\Rol;
use App\Enums\UnidadVenta;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\User;
use Database\Seeders\RolesSistemaSeeder;

beforeEach(function (): void {
    $this->seed(RolesSistemaSeeder::class);

    $this->bodega = Bodega::factory()->create(['codigo' => 'COP']);
    $this->vendedor = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->vendedor->assignRole(Rol::Vendedor->value);

    $this->encargado = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->encargado->assignRole(Rol::Bodega->value);

    // Gravado: L 100 la unidad. Exento: L 50 la unidad, caja de 10 = L 500.
    $this->gravado = Producto::factory()->precio(80.0, 100.0)->create(['unidades_por_caja' => 12]);
    $this->exento = Producto::factory()->exento()->precio(40.0, 50.0)->create(['unidades_por_caja' => 10]);
});

/**
 * Pedido de 2 unidades del gravado + 1 caja del exento.
 */
function pedidoConPromo(Bodega $bodega, User $vendedor, Producto $gravado, Producto $exento): Pedido
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

/**
 * Por cada caja del exento, 1 unidad del gravado a mitad de precio.
 */
function promoCajaExento(Producto $gravado, Producto $exento): Promocion
{
    return Promocion::factory()
        ->para($exento, $gravado, porcentaje: 50, unidadCondicion: UnidadVenta::Caja)
        ->create();
}

test('cada promoción genera su propio código', function (): void {
    $primera = Promocion::factory()->create();
    $segunda = Promocion::factory()->create();

    expect($primera->codigo)->toBe('PROMO-'.str_pad((string) $primera->id, 4, '0', STR_PAD_LEFT))
        ->and($segunda->codigo)->toMatch('/^PROMO-\d{4,}$/')
        ->and($segunda->codigo)->not->toBe($primera->codigo);
});

test('solo cuentan las activas y dentro de sus fechas', function (): void {
    $siempre = Promocion::factory()->create();
    $conRango = Promocion::factory()->create(['vigente_desde' => today()->subDay(), 'vigente_hasta' => today()->addDay()]);
    $inactiva = Promocion::factory()->inactiva()->create();
    $futura = Promocion::factory()->create(['vigente_desde' => today()->addDay()]);
    $vencida = Promocion::factory()->create(['vigente_hasta' => today()->subDay()]);

    expect(Promocion::query()->vigentes()->orderBy('id')->pluck('id')->all())->toBe([$siempre->id, $conRango->id])
        ->and($siempre->estado())->toBe('Vigente')
        ->and($inactiva->estado())->toBe('Inactiva')
        ->and($futura->fresh()?->estado())->toBe('Programada')
        ->and($vencida->fresh()?->estado())->toBe('Vencida');
});

test('el resumen explica la promoción', function (): void {
    $promo = promoCajaExento($this->gravado, $this->exento);
    $tresPorDos = Promocion::factory()->para($this->gravado, $this->gravado, cantidad: 2)->create();

    expect($promo->resumen())->toBe("Compra 1 caja de {$this->exento->descripcion} → 1 unidad de {$this->gravado->descripcion} a mitad de precio")
        ->and($tresPorDos->resumen())->toBe("Compra 2 unidades de {$this->gravado->descripcion} y lleva 1 unidad más gratis");
});

test('el pedido aplica la promoción y cobra el ISV sobre lo neto', function (): void {
    $promo = promoCajaExento($this->gravado, $this->exento);

    $pedido = pedidoConPromo($this->bodega, $this->vendedor, $this->gravado, $this->exento);
    [$lineaGravada, $lineaExenta] = $pedido->items->all();

    // Gravado: 2 × 100 = 200 − 50 (1 a mitad) = 150 + 22.50 ISV = 172.50
    // Exento: 1 caja = 500, sin ISV.
    expect($lineaGravada->subtotal)->toBe(200.0)
        ->and($lineaGravada->descuento)->toBe(50.0)
        ->and($lineaGravada->isv)->toBe(22.5)
        ->and($lineaGravada->total)->toBe(172.5)
        ->and($lineaGravada->promocion_id)->toBe($promo->id)
        ->and($lineaExenta->descuento)->toBe(0.0)
        ->and($pedido->subtotal)->toBe(700.0)
        ->and($pedido->descuento)->toBe(50.0)
        ->and($pedido->isv)->toBe(22.5)
        ->and($pedido->total)->toBe(672.5);
});

test('una promoción desactivada no se aplica en la preventa', function (): void {
    promoCajaExento($this->gravado, $this->exento)->update(['activa' => false]);

    $pedido = pedidoConPromo($this->bodega, $this->vendedor, $this->gravado, $this->exento);

    expect($pedido->descuento)->toBe(0.0)
        ->and($pedido->total)->toBe(730.0);
});

test('al despachar se respeta la promoción aunque la hayan desactivado', function (): void {
    $promo = promoCajaExento($this->gravado, $this->exento);
    $pedido = pedidoConPromo($this->bodega, $this->vendedor, $this->gravado, $this->exento);

    $promo->update(['activa' => false]);

    $despachado = app(ValidarDespacho::class)->ejecutar($pedido, [], $this->encargado);

    expect($despachado->estado)->toBe(EstadoPedido::PorImprimir)
        ->and($despachado->descuento)->toBe(50.0)
        ->and($despachado->total)->toBe(672.5);
});

test('si al despachar ya no se cumple la condición, el producto se cobra completo', function (): void {
    promoCajaExento($this->gravado, $this->exento);
    $pedido = pedidoConPromo($this->bodega, $this->vendedor, $this->gravado, $this->exento);
    [$lineaGravada, $lineaExenta] = $pedido->items->all();

    // No hay del exento: ya no hay caja que dé la promoción.
    $despachado = app(ValidarDespacho::class)->ejecutar($pedido, [$lineaExenta->id => 0], $this->encargado);

    expect($despachado->descuento)->toBe(0.0)
        ->and($despachado->subtotal)->toBe(200.0)
        ->and($despachado->isv)->toBe(30.0)
        ->and($despachado->total)->toBe(230.0)
        ->and($lineaGravada->fresh()?->promocion_id)->toBeNull();
});

test('administración cambia todas; el encargado crea y cambia solo las de su bodega; el vendedor solo las ve', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Rol::Administrador->value);
    $otra = Bodega::factory()->create();

    $deSuBodega = promoCajaExento($this->gravado, $this->exento);
    $deSuBodega->bodegas()->sync([$this->bodega->id]);
    $deVarias = promoCajaExento($this->gravado, $this->exento);
    $deVarias->bodegas()->sync([$this->bodega->id, $otra->id]);
    $deTodas = promoCajaExento($this->gravado, $this->exento);
    $deOtra = promoCajaExento($this->gravado, $this->exento);
    $deOtra->bodegas()->sync([$otra->id]);

    expect($admin->can('create', Promocion::class))->toBeTrue()
        ->and($admin->can('update', $deVarias))->toBeTrue()
        ->and($this->encargado->can('create', Promocion::class))->toBeTrue()
        ->and($this->encargado->can('update', $deSuBodega))->toBeTrue()
        ->and($this->encargado->can('delete', $deSuBodega))->toBeTrue()
        ->and($this->encargado->can('update', $deVarias))->toBeFalse()
        ->and($this->encargado->can('update', $deTodas))->toBeFalse()
        ->and($this->encargado->can('view', $deTodas))->toBeTrue()
        ->and($this->encargado->can('view', $deOtra))->toBeFalse()
        ->and($this->vendedor->can('viewAny', Promocion::class))->toBeTrue()
        ->and($this->vendedor->can('update', $deSuBodega))->toBeFalse();

    // Lo que ve cada uno: administración todas; los demás, las que valen en su bodega.
    expect(Promocion::query()->visiblesPara($this->vendedor)->orderBy('id')->pluck('id')->all())->toBe([$deSuBodega->id, $deVarias->id, $deTodas->id])
        ->and(Promocion::query()->visiblesPara($admin)->count())->toBe(4);
});

test('cada bodega aplica solo sus promociones (y las de todas las bodegas)', function (): void {
    $otra = Bodega::factory()->create();
    $promo = promoCajaExento($this->gravado, $this->exento);
    $promo->bodegas()->sync([$otra->id]);

    $pedido = pedidoConPromo($this->bodega, $this->vendedor, $this->gravado, $this->exento);

    expect($pedido->descuento)->toBe(0.0);

    $promo->bodegas()->sync([$this->bodega->id, $otra->id]);

    expect(app(RecalcularPedido::class)->ejecutar($pedido)->descuento)->toBe(50.0);

    $promo->bodegas()->sync([]);

    expect(app(RecalcularPedido::class)->ejecutar($pedido)->descuento)->toBe(50.0);
});
