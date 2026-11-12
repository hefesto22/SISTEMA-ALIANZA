<?php

declare(strict_types=1);

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Productos\GuardarPresentaciones;
use App\Enums\MotivoCambioPrecio;
use App\Enums\Rol;
use App\Filament\Resources\Productos\Pages\CreateProducto;
use App\Filament\Resources\Productos\Pages\EditProducto;
use App\Filament\Resources\Productos\Pages\ViewProducto;
use App\Filament\Resources\UnidadesMedida\Pages\ManageUnidadesMedida;
use App\Models\CambioPrecio;
use App\Models\Categoria;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\UnidadMedida;
use App\Models\User;
use Database\Seeders\RolesSistemaSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

/**
 * Presentaciones del mismo producto (mismo código): la base (UN, en lo que
 * se cuenta la existencia) y las que traen varias (CJ de 8, CJ de 12, FD de
 * 6…), cada una con su precio.
 */
beforeEach(function (): void {
    $this->seed(RolesSistemaSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Rol::Administrador->value);

    $this->vendedor = User::factory()->create();
    $this->vendedor->assignRole(Rol::Vendedor->value);

    $this->un = UnidadMedida::query()->where('codigo', 'UN')->valueOrFail('id');
    $this->cj = UnidadMedida::query()->where('codigo', 'CJ')->valueOrFail('id');
    $this->fd = UnidadMedida::query()->where('codigo', 'FD')->valueOrFail('id');
});

test('el sistema trae las unidades de medida de siempre y cada producto nace con su UN', function (): void {
    $producto = Producto::factory()->conCaja(1)->create();

    expect(UnidadMedida::query()->whereIn('codigo', ['UN', 'CJ', 'FD', 'SC', 'BT'])->count())->toBe(5)
        ->and($producto->unidad_medida_id)->toBe($this->un)
        ->and($producto->presentaciones()->count())->toBe(1)
        ->and($producto->presentacionBase()->sigla())->toBe('UN')
        ->and($producto->presentacionBase()->contenido)->toBe(1)
        ->and($producto->presentacionMayor())->toBeNull();
});

test('cada presentación se dice corta y en palabras', function (): void {
    $producto = Producto::factory()->conPresentacion('FD', 6)->create();

    expect(presentacion($producto)->sigla())->toBe('UN')
        ->and(presentacion($producto, 12)->sigla())->toBe('CJ12')
        ->and(presentacion($producto, 6)->sigla())->toBe('FD6')
        ->and(presentacion($producto, 12)->nombre())->toBe('Caja de 12')
        ->and(presentacion($producto, 12)->etiqueta())->toBe('CJ12 · Caja de 12')
        ->and(presentacion($producto, 6)->enPalabras(3))->toBe('3 fardos de 6')
        ->and(presentacion($producto)->enPalabras(1))->toBe('1 unidad')
        ->and($producto->presentacionMayor()?->sigla())->toBe('CJ12')
        ->and($producto->existenciaEnTexto(29))->toBe('2 CJ12 + 5 UN');
});

test('al crear un producto se arman sus presentaciones; el costo de la caja se reparte y los precios salen con el %', function (): void {
    $categoria = Categoria::factory()->create(['margen' => 0.16]);
    $this->actingAs($this->admin);
    $undoRepeaterFake = Repeater::fake();

    // CJ8 a L 92 con ISV = L 10 la unidad sin ISV. La UN se vende a L 20 con ISV (escrito);
    // la CJ8 y la CJ12 salen del costo con 16 %: 80 ÷ 0.84 y 120 ÷ 0.84.
    Livewire::test(CreateProducto::class)
        ->fillForm([
            'proveedor_id'     => $categoria->proveedor_id,
            'categoria_id'     => $categoria->id,
            'codigo'           => 'JAB-001',
            'unidad_medida_id' => $this->un,
            'descripcion'      => 'jabon de tocador 100g',
            'gravado'          => true,
            'base_activa'      => true,
            'base_precio'      => 20,
            'otras'            => [
                ['unidad_medida_id' => $this->cj, 'contenido' => 8, 'activo' => true, 'costo' => 92],
                ['unidad_medida_id' => $this->cj, 'contenido' => 12, 'activo' => true],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $undoRepeaterFake();

    $producto = Producto::query()->where('codigo', 'JAB-001')->sole();

    expect($producto->descripcion)->toBe('JABON DE TOCADOR 100G')
        ->and($producto->costo_unidad)->toBe(10.0)
        ->and($producto->presentaciones->map(fn (Presentacion $p): string => $p->sigla())->all())->toBe(['UN', 'CJ8', 'CJ12'])
        ->and(presentacion($producto)->precio)->toBe(17.3913)
        ->and(presentacion($producto, 8)->precio)->toBe(95.2381)
        ->and(presentacion($producto, 12)->precio)->toBe(142.8571)
        ->and(CambioPrecio::query()->where('producto_id', $producto->id)->count())->toBe(3)
        ->and(CambioPrecio::query()->where('producto_id', $producto->id)->first()?->nota)->toBe('PRODUCTO NUEVO');

    Livewire::test(ViewProducto::class, ['record' => $producto->getRouteKey()])
        ->assertOk()
        ->assertSee('CJ8')
        ->assertSee('CJ12');
});

test('al crear, una presentación repetida o que trae menos de 2 no se acepta', function (): void {
    $categoria = Categoria::factory()->create();
    $this->actingAs($this->admin);
    $undoRepeaterFake = Repeater::fake();

    $crear = fn (array $otras) => Livewire::test(CreateProducto::class)
        ->fillForm([
            'proveedor_id'     => $categoria->proveedor_id,
            'categoria_id'     => $categoria->id,
            'codigo'           => 'JAB-002',
            'unidad_medida_id' => $this->un,
            'descripcion'      => 'JABON REPETIDO',
            'otras'            => $otras,
        ])
        ->call('create');

    $crear([
        ['unidad_medida_id' => $this->cj, 'contenido' => 12, 'activo' => true],
        ['unidad_medida_id' => $this->cj, 'contenido' => 12, 'activo' => true],
    ])->assertNotified('La presentación CJ12 está repetida.');

    $crear([['unidad_medida_id' => $this->cj, 'contenido' => 1, 'activo' => true]])
        ->assertHasFormErrors(['otras.0.contenido']);

    $undoRepeaterFake();

    expect(Producto::query()->where('codigo', 'JAB-002')->exists())->toBeFalse();
});

test('al editar se agrega una presentación (sale del costo con el %) y la UN deja de venderse suelta', function (): void {
    $producto = Producto::factory()->create();
    $caja = caja($producto);
    $this->actingAs($this->admin);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(EditProducto::class, ['record' => $producto->getRouteKey()])
        ->assertFormSet(['base_activa' => true, 'otras.0.id' => $caja->id, 'otras.0.unidad_medida_id' => $this->cj, 'otras.0.contenido' => 12])
        ->fillForm([
            'base_activa' => false,
            'otras'       => [
                ['id' => $caja->id, 'unidad_medida_id' => $this->cj, 'contenido' => 12, 'activo' => true],
                ['id' => null, 'unidad_medida_id' => $this->fd, 'contenido' => 6, 'activo' => true],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $undoRepeaterFake();

    // Costo 50 por unidad: FD6 = 300 ÷ 0.84 = 357.1429. La CJ12 no se toca.
    $fardo = presentacion($producto, 6);
    $cambio = CambioPrecio::query()->where('presentacion_id', $fardo->id)->sole();

    expect(presentacion($producto)->activo)->toBeFalse()
        ->and($fardo->sigla())->toBe('FD6')
        ->and($fardo->precio)->toBe(357.1429)
        ->and($cambio->motivo)->toBe(MotivoCambioPrecio::Manual)
        ->and($cambio->nota)->toBe('PRESENTACIÓN NUEVA')
        ->and($caja->fresh()?->precio)->toBe(714.2856)
        ->and(CambioPrecio::query()->where('presentacion_id', $caja->id)->exists())->toBeFalse();
});

test('lo que trae una presentación que ya se usó no se cambia; si se quita, se desactiva; la que no se usó se borra', function (): void {
    $producto = Producto::factory()->conPresentacion('FD', 6)->create();
    $caja = caja($producto);
    $fardo = presentacion($producto, 6);
    Promocion::factory()->para($producto, Producto::factory()->create(), enCaja: true)->create();

    expect($caja->enUso())->toBeTrue()
        ->and($fardo->enUso())->toBeFalse()
        ->and(fn () => app(GuardarPresentaciones::class)->ejecutar($producto, true, [
            ['id' => $caja->id, 'unidad_medida_id' => $this->cj, 'contenido' => 10, 'activo' => true],
        ]))->toThrow(ReglaNegocioException::class, 'lo que trae no se cambia');

    app(GuardarPresentaciones::class)->ejecutar($producto, true, []);

    expect($caja->fresh()?->activo)->toBeFalse()
        ->and(Presentacion::query()->whereKey($fardo->id)->exists())->toBeFalse()
        ->and(presentacion($producto)->activo)->toBeTrue();

    // Si se vuelve a poner, es la misma (activa otra vez), no una nueva.
    $guardadas = app(GuardarPresentaciones::class)->ejecutar($producto, true, [
        ['id' => null, 'unidad_medida_id' => $this->cj, 'contenido' => 12, 'activo' => true],
    ]);

    expect($guardadas['otras'][0]->id)->toBe($caja->id)
        ->and($caja->fresh()?->activo)->toBeTrue();
});

test('administración agrega unidades de medida; el vendedor no entra', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(ManageUnidadesMedida::class)
        ->assertOk()
        ->assertSee('FARDO')
        ->callAction('create', data: ['codigo' => 'gr', 'nombre' => 'garrafa', 'plural' => 'garrafas', 'activo' => true])
        ->assertHasNoActionErrors();

    $garrafa = UnidadMedida::query()->where('codigo', 'GR')->sole();

    expect($garrafa->nombre)->toBe('GARRAFA')
        ->and(UnidadMedida::opciones())->toHaveKey($garrafa->id)
        ->and($this->admin->can('delete', $garrafa))->toBeFalse();

    Livewire::test(ManageUnidadesMedida::class)
        ->callAction('create', data: ['codigo' => 'CJ', 'nombre' => 'CAJA', 'plural' => 'CAJAS'])
        ->assertHasActionErrors(['codigo' => 'unique']);

    $this->actingAs($this->vendedor);

    $this->get(ManageUnidadesMedida::getUrl())->assertForbidden();
});
