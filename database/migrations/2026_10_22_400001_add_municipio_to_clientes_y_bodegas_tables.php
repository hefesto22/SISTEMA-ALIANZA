<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cliente y bodega quedan en un municipio (y por él, en un departamento).
     *
     * El cliente ya no lleva un vendedor: lo ven los vendedores de las rutas
     * que cubren su municipio. Los clientes que ya existen quedan sin
     * municipio hasta que administración se lo ponga (filtro "Sin zona").
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->foreignId('municipio_id')->nullable()->after('direccion')->constrained('municipios')->restrictOnDelete();
            $table->dropConstrainedForeignId('vendedor_id');

            $table->index(['municipio_id', 'activo']);
        });

        Schema::table('bodegas', function (Blueprint $table): void {
            $table->foreignId('municipio_id')->nullable()->after('direccion')->constrained('municipios')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bodegas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('municipio_id');
        });

        Schema::table('clientes', function (Blueprint $table): void {
            $table->dropIndex(['municipio_id', 'activo']);
            $table->dropConstrainedForeignId('municipio_id');
            $table->foreignId('vendedor_id')->nullable()->after('bodega_id')->constrained('users')->nullOnDelete();
        });
    }
};
