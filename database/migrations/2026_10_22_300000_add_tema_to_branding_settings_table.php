<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tema de colores del panel (shadcn). Reemplaza al color primario libre.
     */
    public function up(): void
    {
        Schema::table('branding_settings', function (Blueprint $table): void {
            $table->string('tema', 20)->default('default')->after('primary_color');
        });
    }

    public function down(): void
    {
        Schema::table('branding_settings', function (Blueprint $table): void {
            $table->dropColumn('tema');
        });
    }
};
