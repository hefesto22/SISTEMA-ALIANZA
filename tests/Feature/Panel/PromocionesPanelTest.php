<?php

declare(strict_types=1);

use App\Enums\Rol;
use App\Enums\UnidadVenta;
use App\Filament\Resources\Pedidos\Pages\CreatePedido;
use App\Filament\Resources\Promociones\Pages\ManagePromociones;
use App\Filament\Resources\Promociones\PromocionResource;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\User;
use Database\Seeders\RolesSistemaSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesSistemaSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->bodega = Bodega::factory()->create(['codigo' => 'COP']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Rol::Administrador->value);

    $this->vendedor = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->vendedor->assignRole(Rol::Vendedor->value);

    $this->toffee = Producto::factory()->precio(20.0, 30.0)->create(['descripcion' => 'BUTTER TOFFEE 12X100G']);
    $this->nutrine = Producto::factory()->precio(20.0, 25.30)->create(['descripcion' => 'BABY NUTRINE 24X50G']);
});

test('administración crea un combo y se le genera el código', function (): void {
    $baby = Producto::factory()->precio(10.0, 18.0)->create(['descripcion' => 'BABY CREMA 12X100ML']);
    $this->actingAs($this->admin);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(ManagePromociones::class)
        ->assertOk()
        ->callAction('create', data: [
            'nombre'      => 'Toffee + Nutrine',
            'condiciones' => [
                ['producto_id' => $this->toffee->id, 'cantidad' => 2, 'unidad' => UnidadVenta::Caja->value],
                ['producto_id' => $this->nutrine->id, 'cantidad' => 1, 'unidad' => UnidadVenta::Caja->value],
            ],
            'producto_beneficio_id' => $baby->id,
            'cantidad_beneficio'    => 1,
            'beneficio_unidad'      => UnidadVenta::Unidad->value,
            'tipo'                  => 'mitad',
            'activa'                => true,
        ])
        ->assertHasNoActionErrors();

    $undoRepeaterFake();

    $promo = Promocion::query()->latest('id')->firstOrFail();

    expect($promo->codigo)->toBe('PROMO-'.str_pad((string) $promo->id, 4, '0', STR_PAD_LEFT))
        ->and($promo->descuento_porcentaje)->toBe(50.0)
        ->and($promo->created_by)->toBe($this->admin->id)
        ->and($promo->activa)->toBeTrue()
        ->and($promo->condiciones->pluck('producto_id')->all())->toBe([$this->toffee->id, $this->nutrine->id])
        ->and($promo->resumen())->toBe('Compra 2 cajas de BUTTER TOFFEE 12X100G + 1 caja de BABY NUTRINE 24X50G → 1 unidad de BABY CREMA 12X100ML a mitad de precio');
});

test('se activa y desactiva con el interruptor', function (): void {
    $promo = Promocion::factory()->para($this->toffee, $this->nutrine)->create();
    $this->actingAs($this->admin);

    Livewire::test(ManagePromociones::class)
        ->call('updateTableColumnState', 'activa', (string) $promo->getKey(), false);

    expect($promo->fresh()?->activa)->toBeFalse();
});

test('el vendedor solo ve promociones cuando hay vigentes', function (): void {
    $this->actingAs($this->vendedor);

    $inactiva = Promocion::factory()->para($this->toffee, $this->nutrine)->inactiva()->create();

    expect(PromocionResource::shouldRegisterNavigation())->toBeFalse();

    $activa = Promocion::factory()->para($this->toffee, $this->nutrine, porcentaje: 50)->create();

    expect(PromocionResource::shouldRegisterNavigation())->toBeTrue();

    Livewire::test(ManagePromociones::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$activa])
        ->assertCanNotSeeTableRecords([$inactiva])
        ->assertActionHidden('create');
});

test('sin promociones la preventa se ve normal', function (): void {
    $this->actingAs($this->vendedor);

    Livewire::test(CreatePedido::class)
        ->assertOk()
        ->assertDontSee('Promociones de hoy');
});

test('la preventa muestra las promociones y el pedido sale con el descuento', function (): void {
    $promo = Promocion::factory()->para($this->toffee, $this->nutrine, porcentaje: 50)->create();
    $cliente = Cliente::factory()->create(['bodega_id' => $this->bodega->id]);

    $this->actingAs($this->vendedor);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(CreatePedido::class)
        ->assertSee('Promociones de hoy')
        ->assertSee((string) $promo->codigo)
        ->fillForm([
            'cliente_id' => $cliente->id,
            'fecha'      => now()->toDateString(),
            'items'      => [
                ['producto_id' => $this->toffee->id, 'unidad' => UnidadVenta::Unidad->value, 'cantidad' => 1],
                ['producto_id' => $this->nutrine->id, 'unidad' => UnidadVenta::Unidad->value, 'cantidad' => 1],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $undoRepeaterFake();

    $pedido = Pedido::query()->latest('id')->firstOrFail();

    // 30 + 25.30 = 55.30 − 12.65 (nutrine a mitad) = 42.65 + 15% ISV (4.50 + 1.90) = 49.05
    expect($pedido->subtotal)->toBe(55.3)
        ->and($pedido->descuento)->toBe(12.65)
        ->and($pedido->isv)->toBe(6.4)
        ->and($pedido->total)->toBe(49.05);
});
