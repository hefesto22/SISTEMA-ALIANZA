<?php

declare(strict_types=1);

use App\Enums\Rol;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Ruta;
use App\Models\User;
use Database\Seeders\BodegaSeeder;
use Database\Seeders\ClientesPruebaSeeder;
use Database\Seeders\DivisionPoliticaSeeder;
use Database\Seeders\RutasPruebaSeeder;
use Illuminate\Database\Eloquent\Builder;

test('el seeder de prueba crea los clientes una sola vez, con código y en su bodega', function (): void {
    $this->seed([DivisionPoliticaSeeder::class, BodegaSeeder::class, ClientesPruebaSeeder::class]);

    $total = Cliente::query()->count();

    // Correrlo otra vez no repite nada.
    $this->seed(ClientesPruebaSeeder::class);

    $bendicion = Cliente::query()->where('nombre', 'PULPERÍA LA BENDICIÓN')->with('municipio')->get();

    expect($total)->toBe(69)
        ->and(Cliente::query()->count())->toBe(69)
        ->and(Cliente::query()->whereNull('codigo')->count())->toBe(0)
        ->and(Cliente::query()->whereNull('municipio_id')->count())->toBe(0)
        ->and(Cliente::query()->where('activo', false)->count())->toBe(4)
        ->and(Cliente::query()->whereHas('bodega', fn (Builder $q): Builder => $q->where('codigo', 'OCO'))->count())->toBe(16)
        // La sucursal: mismo nombre y RTN, otro municipio.
        ->and($bendicion)->toHaveCount(2)
        ->and($bendicion->pluck('rtn')->unique()->count())->toBe(1)
        ->and($bendicion->pluck('municipio.nombre')->sort()->values()->all())->toBe(['DOLORES', 'SANTA ROSA DE COPÁN']);
});

test('las rutas de prueba de Santa Rosa les dan a sus vendedores los clientes de su ruta, sin repetirse', function (): void {
    $this->seed([DivisionPoliticaSeeder::class, BodegaSeeder::class, ClientesPruebaSeeder::class]);
    $cop = Bodega::query()->where('codigo', 'COP')->value('id');
    $marlon = User::factory()->create(['codigo' => 'HO46', 'bodega_id' => $cop]);
    $marlon->assignRole(Rol::Vendedor->value);
    $alvaro = User::factory()->create(['codigo' => 'HO01', 'bodega_id' => $cop]);
    $alvaro->assignRole(Rol::Vendedor->value);

    // Correrlo dos veces deja lo mismo.
    $this->seed(RutasPruebaSeeder::class);
    $this->seed(RutasPruebaSeeder::class);

    $rutas = Ruta::query()->with(['municipios', 'vendedores'])->orderBy('numero')->get();

    expect($rutas->pluck('nombre')->all())->toBe(['SANTA ROSA CENTRO', 'SANTA ROSA OCCIDENTE'])
        ->and($rutas[0]->municipios)->toHaveCount(6)
        ->and($rutas[1]->municipios)->toHaveCount(5)
        ->and($rutas[0]->vendedores->pluck('codigo')->all())->toBe(['HO01'])
        ->and($rutas[1]->vendedores->pluck('codigo')->all())->toBe(['HO46'])
        // Marlon: los 6 de Santa Rosa y 2 en cada uno de los otros 4 municipios de su ruta.
        ->and(Cliente::query()->whereIn('municipio_id', $marlon->municipiosDeRuta() ?? [])->count())->toBe(14);
});
