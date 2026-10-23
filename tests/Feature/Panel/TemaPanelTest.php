<?php

declare(strict_types=1);

use App\Enums\TemaPanel;
use App\Filament\Pages\BrandingSettingsPage;
use App\Models\BrandingSetting;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Database\Seeders\RolesSistemaSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Openplain\FilamentShadcnTheme\Color as Shadcn;

test('cada tema trae su paleta shadcn completa', function (): void {
    foreach (TemaPanel::cases() as $tema) {
        expect($tema->paleta())->toHaveKeys([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950]);
    }

    expect(TemaPanel::Default->paleta())->toBe(Shadcn::Default);
});

test('el super admin cambia el tema desde Configuración', function (): void {
    $this->seed(RolesSistemaSeeder::class);
    Filament::setCurrentPanel('admin');

    // El panel lee BrandingSetting::current() al arrancar y lo deja en caché,
    // antes de que la prueba limpie la base: se borra para leer la fila de verdad.
    Cache::flush();

    $admin = User::factory()->create();
    $admin->assignRole(Utils::getSuperAdminName());
    $this->actingAs($admin);

    Livewire::test(BrandingSettingsPage::class)
        ->assertOk()
        ->fillForm(['tema' => TemaPanel::Azul->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(BrandingSetting::query()->firstOrFail()->tema)->toBe('blue');
});
