<?php

declare(strict_types=1);

use App\Enums\Rol;
use App\Filament\Resources\Clientes\Pages\ManageClientes;
use App\Filament\Schemas\Components\UbicacionEnMapa;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Departamento;
use App\Models\Ruta;
use App\Models\User;
use App\Support\Mapa\BuscarLugar;
use Database\Seeders\DivisionPoliticaSeeder;
use Database\Seeders\RolesSistemaSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Ubicación del cliente en el mapa: el punto se guarda y de él salen el
 * departamento, el municipio y la referencia (BuscarLugar, con Nominatim).
 * Aquí Nominatim es de mentira: contesta lo que contestó el real para esos
 * puntos.
 */
beforeEach(function (): void {
    $this->seed([RolesSistemaSeeder::class, DivisionPoliticaSeeder::class]);
    Filament::setCurrentPanel('admin');

    $copan = Departamento::query()->where('codigo', '04')->firstOrFail();
    $this->santaRosa = $copan->municipios()->where('nombre', 'SANTA ROSA DE COPÁN')->firstOrFail();
    $this->distritoCentral = Departamento::query()->where('codigo', '08')->firstOrFail()->municipios()->where('nombre', 'DISTRITO CENTRAL')->firstOrFail();
    $this->sanAndres = Departamento::query()->where('codigo', '13')->firstOrFail()->municipios()->where('nombre', 'SAN ANDRÉS')->firstOrFail();

    $this->bodega = Bodega::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Rol::Administrador->value);
});

/**
 * Lo que contestó Nominatim para cada punto, por latitud.
 *
 * @return array<string, array<string, string>>
 */
function direccionesDePrueba(): array
{
    return [
        // Santa Rosa de Copán, centro.
        '14.7667' => ['road' => 'Avenida Real Factoría', 'neighbourhood' => 'Barrio Mercedes', 'village' => 'El Carrizal', 'city' => 'Santa Rosa de Copán', 'state' => 'Copán', 'postcode' => '12001', 'country' => 'Honduras', 'country_code' => 'hn'],
        // Otra cuadra de Santa Rosa.
        '14.7701' => ['road' => 'Calle Centenario', 'neighbourhood' => 'Barrio El Centro', 'city' => 'Santa Rosa de Copán', 'state' => 'Copán', 'country' => 'Honduras', 'country_code' => 'hn'],
        // Comayagüela: el municipio viene como "municipality".
        '14.0723' => ['road' => 'Avenida 26', 'neighbourhood' => 'Distrito Kennedy', 'town' => 'Comayagüela', 'city' => 'Tegucigalpa', 'municipality' => 'Distrito Central', 'state' => 'Francisco Morazán', 'postcode' => '11100', 'country' => 'Honduras', 'country_code' => 'hn'],
        // Aldea de Lempira: el municipio viene como "town".
        '14.3' => ['village' => 'Sosoal', 'town' => 'San Andrés', 'state' => 'Lempira', 'country' => 'Honduras', 'country_code' => 'hn'],
        // Guatemala.
        '14.6' => ['town' => 'Esquipulas', 'state' => 'Chiquimula', 'country' => 'Guatemala', 'country_code' => 'gt'],
    ];
}

function nominatimDePrueba(): void
{
    Http::fake(function (Request $request) {
        $lat = $request->data()['lat'] ?? null;
        $direccion = direccionesDePrueba()[is_scalar($lat) ? (string) $lat : ''] ?? null;

        return $direccion === null
            ? Http::response(['error' => 'Unable to geocode'])
            : Http::response(['place_id' => 1, 'address' => $direccion]);
    });
}

/**
 * La clave con la que el navegador llama a ubicar() en el formulario abierto.
 */
function claveDelMapaAbierto(Testable $livewire): string
{
    /** @var ManageClientes $pagina */
    $pagina = $livewire->instance();
    $esquema = $pagina->getSchema((string) $pagina->getMountedActionSchemaName());
    $mapa = collect($esquema?->getFlatComponents(withHidden: true) ?? [])
        ->first(fn (mixed $componente): bool => $componente instanceof UbicacionEnMapa);

    if (! $mapa instanceof UbicacionEnMapa) {
        throw new RuntimeException('El formulario no tiene el mapa.');
    }

    return (string) $mapa->getKey();
}

test('de un punto salen el departamento, el municipio y la referencia', function (): void {
    nominatimDePrueba();
    $buscar = app(BuscarLugar::class);

    $santaRosa = $buscar->en(14.766701, -88.779202);
    $tegucigalpa = $buscar->en(14.0723, -87.1921);
    $aldea = $buscar->en(14.3, -88.6);

    expect($santaRosa?->municipioId)->toBe($this->santaRosa->id)
        ->and($santaRosa?->departamentoId)->toBe($this->santaRosa->departamento_id)
        ->and($santaRosa?->referencia)->toBe('AVENIDA REAL FACTORÍA, BARRIO MERCEDES, EL CARRIZAL')
        ->and($santaRosa?->descripcion)->toBe('SANTA ROSA DE COPÁN · COPÁN')
        ->and($tegucigalpa?->municipioId)->toBe($this->distritoCentral->id)
        ->and($tegucigalpa?->referencia)->toBe('AVENIDA 26, DISTRITO KENNEDY, COMAYAGÜELA, TEGUCIGALPA')
        ->and($aldea?->municipioId)->toBe($this->sanAndres->id)
        ->and($aldea?->referencia)->toBe('SOSOAL');

    // Se identifica ante Nominatim y en español.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/reverse?')
        && $request->hasHeader('User-Agent')
        && ($request->data()['accept-language'] ?? null) === 'es');
});

test('un punto ya consultado no se vuelve a preguntar', function (): void {
    nominatimDePrueba();

    app(BuscarLugar::class)->en(14.766701, -88.779202);
    app(BuscarLugar::class)->en(14.766704, -88.779198);

    Http::assertSentCount(1);
});

test('un punto fuera de Honduras no llena departamento ni municipio', function (): void {
    nominatimDePrueba();

    $guatemala = app(BuscarLugar::class)->en(14.6, -89.35);

    expect($guatemala?->enHonduras)->toBeFalse()
        ->and($guatemala?->municipioId)->toBeNull()
        ->and($guatemala?->descripcion)->toBe('Esquipulas, Chiquimula, Guatemala');
});

test('si Nominatim no contesta no hay lugar, y no se guarda la falla', function (): void {
    Http::fake(['*' => Http::sequence()->push('', 503)->push(['address' => direccionesDePrueba()['14.7667']])]);

    expect(app(BuscarLugar::class)->en(14.7667, -88.7792))->toBeNull()
        ->and(app(BuscarLugar::class)->en(14.7667, -88.7792)?->municipioId)->toBe($this->santaRosa->id);
});

test('al marcar el negocio en el mapa se llenan departamento, municipio y referencia, y se guarda el punto', function (): void {
    nominatimDePrueba();
    $this->actingAs($this->admin);

    $livewire = Livewire::test(ManageClientes::class)
        ->mountAction('create')
        ->fillForm([
            'nombre'    => 'pulpería la bendición',
            'bodega_id' => $this->bodega->id,
            'ubicacion' => ['lat' => 14.7667, 'lng' => -88.7792],
        ]);

    $livewire->call('callSchemaComponentMethod', claveDelMapaAbierto($livewire), 'ubicar')
        ->assertSchemaStateSet([
            'departamento_id' => $this->santaRosa->departamento_id,
            'municipio_id'    => $this->santaRosa->id,
            'direccion'       => 'AVENIDA REAL FACTORÍA, BARRIO MERCEDES, EL CARRIZAL',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $cliente = Cliente::query()->where('nombre', 'PULPERÍA LA BENDICIÓN')->sole();

    expect($cliente->ubicacion)->toBe(['lat' => 14.7667, 'lng' => -88.7792])
        ->and($cliente->municipio_id)->toBe($this->santaRosa->id)
        ->and($cliente->direccion)->toBe('AVENIDA REAL FACTORÍA, BARRIO MERCEDES, EL CARRIZAL')
        ->and($cliente->enlaceMapa())->toBe('https://www.google.com/maps/search/?api=1&query=14.7667,-88.7792');
});

test('la referencia que puso el mapa cambia al mover el pin; la que escribió el usuario no', function (): void {
    nominatimDePrueba();
    $this->actingAs($this->admin);

    $livewire = Livewire::test(ManageClientes::class)
        ->mountAction('create')
        ->fillForm(['ubicacion' => ['lat' => 14.7667, 'lng' => -88.7792]]);
    $clave = claveDelMapaAbierto($livewire);

    $livewire->call('callSchemaComponentMethod', $clave, 'ubicar')
        ->assertSchemaStateSet(['direccion' => 'AVENIDA REAL FACTORÍA, BARRIO MERCEDES, EL CARRIZAL'])
        ->fillForm(['ubicacion' => ['lat' => 14.7701, 'lng' => -88.7781]])
        ->call('callSchemaComponentMethod', $clave, 'ubicar')
        ->assertSchemaStateSet(['direccion' => 'CALLE CENTENARIO, BARRIO EL CENTRO'])
        ->fillForm(['direccion' => 'Frente a la iglesia católica'])
        ->fillForm(['ubicacion' => ['lat' => 14.7667, 'lng' => -88.7792]])
        ->call('callSchemaComponentMethod', $clave, 'ubicar')
        ->assertSchemaStateSet([
            'direccion'    => 'Frente a la iglesia católica',
            'municipio_id' => $this->santaRosa->id,
        ]);
});

test('al vendedor no se le pone un municipio que no es de sus rutas', function (): void {
    nominatimDePrueba();

    $vendedor = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $vendedor->assignRole(Rol::Vendedor->value);
    Ruta::factory()->cubre($this->santaRosa)->para($vendedor)->create(['bodega_id' => $this->bodega->id]);

    $this->actingAs($vendedor);

    $livewire = Livewire::test(ManageClientes::class)
        ->mountAction('create')
        ->fillForm(['ubicacion' => ['lat' => 14.0723, 'lng' => -87.1921]]);

    $livewire->call('callSchemaComponentMethod', claveDelMapaAbierto($livewire), 'ubicar')
        ->assertNotified('Ese punto queda en DISTRITO CENTRAL · FRANCISCO MORAZÁN, que no está en sus rutas')
        ->assertSchemaStateSet(['municipio_id' => null]);
});
