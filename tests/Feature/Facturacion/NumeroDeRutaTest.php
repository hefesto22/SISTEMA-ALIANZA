<?php

declare(strict_types=1);

use App\Domain\Inventario\RegistrarCompra;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarDespacho;
use App\Enums\ModoImpresion;
use App\Enums\Rol;
use App\Filament\Resources\Cargas\Pages\CreateCarga;
use App\Filament\Resources\Facturas\Pages\ListFacturas;
use App\Filament\Resources\Rutas\Pages\ManageRutas;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Factura;
use App\Models\Municipio;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Número de ruta (la ruta 22 de la bodega): sale en la factura, queda fijo
 * en el pedido desde su primera factura, y con él se ven y se filtran las
 * facturas y se arman las cargas.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $this->bodega = Bodega::factory()->create(['codigo' => 'COP']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Rol::Administrador->value);
    $this->encargado = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->encargado->assignRole(Rol::Bodega->value);
    $this->vendedor = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->vendedor->assignRole(Rol::Vendedor->value);

    $this->jabon = Producto::factory()->precio(8.0, 10.0)->create(['margen' => 0.20]);
    $this->un = presentacion($this->jabon);
    app(RegistrarCompra::class)->ejecutar($this->bodega, $this->jabon->proveedor, [
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->un->id, 'cantidad' => 100, 'costo' => 9.2],
    ], $this->admin);

    facturacionLista();
    Configuracion::actual()->update(['notas_credito' => false]);
});

/**
 * Un pedido validado (por imprimir) de 2 UN de jabón al cliente.
 */
function pedidoDeRuta(object $prueba, Cliente $cliente, ?User $vendedor = null): Pedido
{
    $pedido = Pedido::query()->create([
        'bodega_id'   => $prueba->bodega->id,
        'cliente_id'  => $cliente->id,
        'vendedor_id' => ($vendedor ?? $prueba->vendedor)->id,
        'fecha'       => now(),
    ]);
    $pedido->items()->create(['producto_id' => $prueba->jabon->id, 'presentacion_id' => $prueba->un->id, 'cantidad' => 2]);
    app(ValidarDespacho::class)->ejecutar(app(RecalcularPedido::class)->ejecutar($pedido), [], $prueba->encargado);

    return $pedido->fresh();
}

test('cada ruta lleva su número, único en la bodega; si no se pone, toma el siguiente libre', function (): void {
    $otra = Bodega::factory()->create();

    expect(Ruta::factory()->create(['bodega_id' => $this->bodega->id, 'numero' => 22])->numero)->toBe(22)
        ->and(Ruta::factory()->create(['bodega_id' => $this->bodega->id])->numero)->toBe(23)
        ->and(Ruta::factory()->create(['bodega_id' => $otra->id])->numero)->toBe(1)
        ->and(Ruta::siguienteNumero($this->bodega->id))->toBe(24);

    $this->actingAs($this->admin);

    Livewire::test(ManageRutas::class)
        ->callAction('create', data: [
            'bodega_id'  => $this->bodega->id,
            'numero'     => 22,
            'nombre'     => 'Ruta repetida',
            'municipios' => [Municipio::factory()->create()->id],
        ])
        ->assertHasActionErrors(['numero']);
});

test('la factura dice la ruta del pedido y queda fija aunque después cambien las rutas', function (): void {
    $cliente = Cliente::factory()->create(['bodega_id' => $this->bodega->id]);
    $ruta = Ruta::factory()->cubre($cliente->municipio)->para($this->vendedor)->create(['bodega_id' => $this->bodega->id, 'numero' => 22, 'nombre' => 'Ruta centro']);
    $pedido = pedidoDeRuta($this, $cliente);

    expect($pedido->ruta_id)->toBeNull();

    $this->actingAs($this->encargado);

    $this->get(imprimir($pedido, $this->encargado)->url())
        ->assertOk()
        ->assertSee('N° 22')
        ->assertSee('RUTA CENTRO');

    expect($pedido->fresh()->ruta_id)->toBe($ruta->id);

    // Al vendedor le cambian la ruta: la factura de ese pedido sigue diciendo la 22.
    $ruta->vendedores()->detach();
    Ruta::factory()->cubre($cliente->municipio)->para($this->vendedor)->create(['bodega_id' => $this->bodega->id, 'numero' => 30]);

    $this->get(imprimir($pedido->fresh(), $this->encargado, ModoImpresion::Copia)->url())
        ->assertOk()
        ->assertSee('N° 22')
        ->assertDontSee('N° 30');
});

test('en Facturas se ve y se filtra por ruta, y al cargar el camión salen por ruta', function (): void {
    $marlon = User::factory()->create(['bodega_id' => $this->bodega->id, 'name' => 'MARLON']);
    $marlon->assignRole(Rol::Vendedor->value);
    $centro = Cliente::factory()->create(['bodega_id' => $this->bodega->id]);
    $afuera = Cliente::factory()->create(['bodega_id' => $this->bodega->id]);
    $veintidos = Ruta::factory()->cubre($centro->municipio)->para($this->vendedor)->create(['bodega_id' => $this->bodega->id, 'numero' => 22]);
    Ruta::factory()->cubre($afuera->municipio)->para($marlon)->create(['bodega_id' => $this->bodega->id, 'numero' => 7]);

    $uno = pedidoDeRuta($this, $centro);
    $dos = pedidoDeRuta($this, $afuera, $marlon);
    imprimir([$uno, $dos], $this->encargado);

    $deUno = Factura::query()->where('pedido_id', $uno->id)->sole();
    $deDos = Factura::query()->where('pedido_id', $dos->id)->sole();

    $this->actingAs($this->encargado);

    Livewire::test(ListFacturas::class)
        ->assertCanSeeTableRecords([$deUno, $deDos])
        ->assertSee('N° 22')
        ->filterTable('ruta', $veintidos->id)
        ->assertCanSeeTableRecords([$deUno])
        ->assertCanNotSeeTableRecords([$deDos]);

    // En la carga, una tarjeta por ruta y vendedor, en orden de ruta.
    Livewire::test(CreateCarga::class)
        ->assertOk()
        ->assertSeeInOrder(['Ruta 7', 'MARLON', 'Ruta 22']);
});
