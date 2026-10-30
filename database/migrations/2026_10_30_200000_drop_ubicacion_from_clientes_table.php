<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Se quitó el mapa del cliente: la ubicación se escribe a mano
     * (departamento, municipio y referencia). La columna se había agregado en
     * 2026_10_30_100000; nombre_clave se queda.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('clientes', 'ubicacion')) {
            return;
        }

        Schema::table('clientes', function (Blueprint $table): void {
            $table->dropColumn('ubicacion');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('clientes', 'ubicacion')) {
            return;
        }

        Schema::table('clientes', function (Blueprint $table): void {
            $table->json('ubicacion')->nullable()->after('direccion');
        });
    }
};
