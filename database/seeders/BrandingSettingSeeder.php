<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BrandingSetting;
use Illuminate\Database\Seeder;

class BrandingSettingSeeder extends Seeder
{
    public function run(): void
    {
        BrandingSetting::firstOrCreate(
            [],
            [
                'logo_path'     => null,
                'favicon_path'  => null,
                'primary_color' => '#f59e0b', // de la plantilla; ya no pinta el panel
                'tema'          => 'default', // shadcn blanco y negro (App\Enums\TemaPanel)
            ]
        );

        $this->command?->info('✓ Branding setting inicializado.');
    }
}
