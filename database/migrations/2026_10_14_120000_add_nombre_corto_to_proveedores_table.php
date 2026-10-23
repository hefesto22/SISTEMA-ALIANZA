<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nombre corto para pestañas y listados (Unilever, MERHONSA, DCASA…).
     */
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table): void {
            $table->string('nombre_corto', 40)->nullable()->after('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table): void {
            $table->dropColumn('nombre_corto');
        });
    }
};
