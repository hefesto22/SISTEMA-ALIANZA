<?php

declare(strict_types=1);

use App\Enums\EstadoPedido;
use App\Enums\Rol;
use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Models\Bodega;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Producto;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Database\Seeders\BodegaSeeder;
use Database\Seeders\ClientesPruebaSeeder;
use Database\Seeders\DivisionPoliticaSeeder;
use Database\Seeders\PedidosPruebaSeeder;
use Database\Seeders\RolesSistemaSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Livewire;

/**
 * Pedidos de prueba (seeder) y la lista de pedidos en tarjetas.
 */
beforeEach(function (): void {
    $this->seed([RolesSistemaSeeder::class, DivisionPoliticaSeeder::class, BodegaSeeder::class, ClientesPruebaSeeder::class]);
    Filament::setCurrentPanel('admin');

    foreach (['COP', 'OCO', 'SB'] as $codigo) {
        $vendedor = User::factory()->create(['bodega_id' => Bodega::query()->where('codigo', $codigo)->value('id')]);
        $vendedor->assignRole(Rol::Vendedor->value);
    }

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Utils::getSuperAdminName());

    Producto::factory()->count(8)->create();

    $this->seed(PedidosPruebaSeeder::class);
});

test('el seeder crea 10 pedidos pendientes con distintos productos y al repetirlo los cambia', function (): void {
    $antes = Pedido::query()->pluck('id')->all();

    $this->seed(PedidosPruebaSeeder::class);

    expect(Pedido::query()->count())->toBe(10)
        ->and(Pedido::query()->where('estado', EstadoPedido::Pendiente)->count())->toBe(10)
        ->and(Pedido::query()->whereKey($antes)->exists())->toBeFalse()
        ->and(Pedido::query()->where('fuera_de_ruta', true)->whereNotNull('motivo_fuera_ruta')->count())->toBe(1)
        ->and(Pedido::query()->where('total', '<=', 0)->count())->toBe(0)
        ->and(PedidoItem::query()->count())->toBe(37)
        ->and(PedidoItem::query()->distinct()->count('producto_id'))->toBeGreaterThanOrEqual(6);
});

test('los pedidos se ven en tarjetas, sin texto de resumen arriba, y se buscan por cliente', function (): void {
    $this->actingAs($this->admin);

    $fuera = Pedido::query()->with('cliente')->where('fuera_de_ruta', true)->firstOrFail();
    $otro = Pedido::query()->where('cliente_id', '!=', $fuera->cliente_id)->firstOrFail();

    Livewire::test(ListPedidos::class)
        ->assertOk()
        ->assertDontSee('10 pendientes')
        ->assertDontSee('por validar')
        ->assertSee($fuera->cliente->nombre)
        ->assertSee((string) $fuera->numero)
        ->assertSee('Fuera de ruta')
        ->searchTable((string) $fuera->cliente->codigo)
        ->assertCanSeeTableRecords([$fuera])
        ->assertCanNotSeeTableRecords([$otro]);
});

test('se pueden ver solo los pedidos de un cliente', function (): void {
    $this->actingAs($this->admin);

    $pedido = Pedido::query()->orderBy('id')->firstOrFail();
    $otro = Pedido::query()->where('cliente_id', '!=', $pedido->cliente_id)->firstOrFail();

    Livewire::test(ListPedidos::class)
        ->set('activeTab', 'todos')
        ->filterTable('cliente_id', $pedido->cliente_id)
        ->assertCanSeeTableRecords([$pedido])
        ->assertCanNotSeeTableRecords([$otro]);
});

/**
 * Encargado de bodega de COP (Santa Rosa).
 */
function encargadoDeCop(): User
{
    $encargado = User::factory()->create(['bodega_id' => Bodega::query()->where('codigo', 'COP')->value('id')]);
    $encargado->assignRole(Rol::Bodega->value);

    return $encargado;
}

/**
 * @return Builder<Pedido>
 */
function pedidosDeVendedoresDeCop(bool $deCop = true): Builder
{
    $cop = (int) Bodega::query()->where('codigo', 'COP')->value('id');

    return Pedido::query()->whereHas('vendedor', fn (Builder $q): Builder => $q->where('bodega_id', $deCop ? '=' : '!=', $cop));
}

test('el encargado ve solo los pedidos de los vendedores de su bodega; administración ve todos', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Rol::Administrador->value);

    $deCop = pedidosDeVendedoresDeCop()->get();
    $deOtras = pedidosDeVendedoresDeCop(false)->get();

    $this->actingAs(encargadoDeCop());

    Livewire::test(ListPedidos::class)
        ->assertCanSeeTableRecords($deCop)
        ->assertCanNotSeeTableRecords($deOtras);

    expect($deCop)->toHaveCount(5)
        ->and($deOtras)->toHaveCount(5)
        ->and(Pedido::query()->visiblePara($admin)->count())->toBe(10);
});

test('al despachar desde la tarjeta se quita lo que no hay y se baja lo que no alcanzó', function (): void {
    $pedido = pedidosDeVendedoresDeCop()->has('items', '>=', 3)->orderBy('id')->firstOrFail();
    $items = $pedido->items()->orderBy('id')->get();

    // Se queda con todas menos la última (no había); de la primera sale 1.
    $filas = $items->slice(0, -1)->values()
        ->map(fn (PedidoItem $item, int $i): array => ['sale' => $i === 0 ? 1 : $item->cantidad])
        ->all();

    $this->actingAs(encargadoDeCop());
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(ListPedidos::class)
        ->callTableAction('validarDespacho', $pedido, data: ['items' => $filas])
        ->assertHasNoTableActionErrors();

    $undoRepeaterFake();

    $items = $pedido->items()->orderBy('id')->get();

    expect($pedido->fresh()?->estado)->toBe(EstadoPedido::PorImprimir)
        ->and($items->first()?->cantidad_despachada)->toBe(1)
        ->and($items->get(1)?->cantidad_despachada)->toBe($items->get(1)?->cantidad)
        ->and($items->last()?->cantidad_despachada)->toBe(0);
});

test('no se puede despachar quitando todo: para eso se anula', function (): void {
    $pedido = pedidosDeVendedoresDeCop()->orderBy('id')->firstOrFail();

    $this->actingAs(encargadoDeCop());
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(ListPedidos::class)
        ->callTableAction('validarDespacho', $pedido, data: ['items' => []])
        ->assertHasTableActionErrors(['items']);

    $undoRepeaterFake();

    expect($pedido->fresh()?->estado)->toBe(EstadoPedido::Pendiente);
});
