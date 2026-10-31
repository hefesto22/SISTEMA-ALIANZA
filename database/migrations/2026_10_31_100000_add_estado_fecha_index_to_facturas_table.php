<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lista de facturas de administración: vigentes (o anuladas) de la más
     * nueva a la más vieja. Con este índice sale igual de rápido con mil que
     * con cien mil. (Las de una bodega ya usan bodega_id + fecha_emision.)
     */
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table): void {
            $table->index(['estado', 'fecha_emision']);
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table): void {
            $table->dropIndex(['estado', 'fecha_emision']);
        });
    }
};
