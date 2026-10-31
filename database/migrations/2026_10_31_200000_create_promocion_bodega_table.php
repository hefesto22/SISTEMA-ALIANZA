<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bodegas donde vale cada promoción. Sin filas vale en todas (así quedan
     * las que ya existían). Administración escoge una o varias; el encargado
     * crea las de su bodega.
     */
    public function up(): void
    {
        Schema::create('promocion_bodega', function (Blueprint $table): void {
            $table->foreignId('promocion_id')->constrained('promociones')->cascadeOnDelete();
            $table->foreignId('bodega_id')->constrained('bodegas')->cascadeOnDelete();

            $table->primary(['promocion_id', 'bodega_id']);
            $table->index('bodega_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promocion_bodega');
    }
};
