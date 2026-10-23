<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AdminUserSeeder::class,
            RolesSistemaSeeder::class,
            BrandingSettingSeeder::class,
            DivisionPoliticaSeeder::class,
            BodegaSeeder::class,
            ProveedorSeeder::class,
            CatalogoSeeder::class,
            PersonalSeeder::class,
        ]);
    }
}
