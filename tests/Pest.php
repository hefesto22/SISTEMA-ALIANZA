<?php

declare(strict_types=1);
use App\Domain\Facturacion\ImprimirFacturas;
use App\Domain\Facturacion\ResultadoImpresion;
use App\Domain\ValueObjects\Monto;
use App\Enums\ModoImpresion;
use App\Models\Cai;
use App\Models\DatosFiscales;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Expectation;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Asigna a qué TestCase apunta cada carpeta. Feature usa el TestCase
| que corre con RefreshDatabase + Laravel app boot. Unit usa el base
| (sin DB) — más rápido, ideal para Value Objects y lógica pura.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Feature/Filament');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
| Custom expectations específicas del dominio Olympo.
*/

expect()->extend('toBeMonto', function (float $valor, string $moneda = 'HNL'): Expectation {
    /** @var Monto $monto */
    $monto = $this->value;

    expect($monto)->toBeInstanceOf(Monto::class);
    expect($monto->valor)->toBe($valor);
    expect($monto->moneda)->toBe($moneda);

    return $this;
});

expect()->extend('toBeValidRTN', function (): Expectation {
    expect((string) $this->value)->toMatch('/^\d{14}$/');

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function actingAsAdmin(): User
{
    /** @var User $user */
    $user = User::factory()->create([
        'is_active' => true,
    ]);

    test()->actingAs($user);

    return $user;
}

/**
 * Datos fiscales completos y un CAI global vigente: lo mínimo para que
 * bodega pueda imprimir (emitir) facturas.
 */
function facturacionLista(): Cai
{
    DatosFiscales::actual()->update([
        'razon_social' => 'DISTRIBUIDORA DE PRUEBA S. DE R.L.',
        'rtn'          => '05019000123456',
        'direccion'    => 'BARRIO EL CENTRO, SANTA ROSA DE COPÁN',
    ]);

    return Cai::factory()->create();
}

/**
 * Imprime como el botón Imprimir (emite la factura, cuenta la impresión) y
 * devuelve el resultado; la hoja se ve con $this->get($resultado->url()).
 *
 * @param list<Pedido>|Pedido $pedidos
 */
function imprimir(array|Pedido $pedidos, User $usuario, ModoImpresion $modo = ModoImpresion::Seleccionados): ResultadoImpresion
{
    $lista = $pedidos instanceof Pedido ? [$pedidos] : $pedidos;

    return app(ImprimirFacturas::class)->ejecutar(array_map(fn (Pedido $pedido): int => $pedido->id, $lista), $usuario, $modo);
}
