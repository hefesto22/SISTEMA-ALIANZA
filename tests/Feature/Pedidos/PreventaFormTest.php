<?php

declare(strict_types=1);

use App\Domain\Pedidos\RecalcularPedido;
use App\Enums\Rol;
use App\Enums\UnidadVenta;
use App\Filament\Resources\Pedidos\Pages\CreatePedido;
use App\Filament\Resources\Pedidos\Pages\EditPedido;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Database\Seeders\RolesSistemaSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

/**
 * Formulario de preventa pensado para el teléfono: cada producto en su
 * tarjeta (unidad o "Caja de N", cantidad, subtotal) y abajo la barra con
 * el total y Guardar.
 */
beforeEach(function (): void {
    $this->seed(RolesSistemaSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->bodega = Bodega::factory()->create(['codigo' => 'COP']);

    $this->vendedor = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->vendedor->assignRole(Rol::Vendedor->value);

    $this->encargado = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->encargado->assignRole(Rol::Bodega->value);

    // L 100 la unidad sin ISV; la caja trae 12.
    $this->producto = Producto::factory()->precio(80.0, 100.0)->create(['unidades_por_caja' => 12]);
});

test('la barra de abajo lleva el total, cuántos productos y el botón Guardar', function (): void {
    $this->actingAs($this->encargado);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(CreatePedido::class)
        ->assertSee('Guardar pedido')
        ->assertSee('Agregue productos')
        ->fillForm([
            'items' => [
                ['producto_id' => $this->producto->id, 'unidad' => UnidadVenta::Caja->value, 'cantidad' => 2],
            ],
        ])
        // 2 cajas × 12 × L 100 = L 2,400 + 15% ISV = L 2,760
        ->assertSee('Caja de 12')
        ->assertSee('2 cajas')
        ->assertSee(lempiras(2760.0))
        ->assertSee('1 producto');

    $undoRepeaterFake();
});

test('al editar, la barra dice Guardar cambios', function (): void {
    $pedido = Pedido::query()->create([
        'bodega_id'   => $this->bodega->id,
        'cliente_id'  => Cliente::factory()->create(['bodega_id' => $this->bodega->id])->id,
        'vendedor_id' => $this->vendedor->id,
        'fecha'       => now(),
    ]);
    $pedido->items()->create(['producto_id' => $this->producto->id, 'unidad' => UnidadVenta::Unidad, 'cantidad' => 3]);
    app(RecalcularPedido::class)->ejecutar($pedido);

    $this->actingAs($this->vendedor);

    // 3 × L 100 = L 300 + 15% ISV = L 345
    Livewire::test(EditPedido::class, ['record' => $pedido->getRouteKey()])
        ->assertSee('Guardar cambios')
        ->assertDontSee('Guardar pedido')
        ->assertSee('3 unidades')
        ->assertSee(lempiras(345.0));
});
