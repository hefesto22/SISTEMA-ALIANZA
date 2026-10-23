<?php

declare(strict_types=1);

use App\Models\Cliente;
use Database\Seeders\BodegaSeeder;
use Database\Seeders\ClientesPruebaSeeder;
use Database\Seeders\DivisionPoliticaSeeder;
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
