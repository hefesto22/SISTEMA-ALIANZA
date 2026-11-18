<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los grupos de comisión son de todas las bodegas, pero cada bodega puede
 * poner sus propios montos (en una no llegan ni a 50,000): la escala de un
 * grupo en una bodega. Sin ella, la bodega usa la del grupo.
 *
 * Para cada vendedor manda, en este orden: su meta propia (comision_metas),
 * la escala de su bodega (comision_escalas_bodega) y la del grupo.
 *
 * comision_liquidaciones.escala_bodega: al cerrar, si se pagó con la escala
 * de la bodega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comision_escalas_bodega', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comision_grupo_id')->constrained('comision_grupos')->cascadeOnDelete();
            $table->foreignId('bodega_id')->constrained('bodegas')->cascadeOnDelete();
            $table->json('escalones');
            $table->timestamps();

            $table->unique(['comision_grupo_id', 'bodega_id']);
            $table->index('bodega_id');
        });

        Schema::table('comision_liquidaciones', function (Blueprint $table): void {
            $table->boolean('escala_bodega')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('comision_liquidaciones', function (Blueprint $table): void {
            $table->dropColumn('escala_bodega');
        });

        Schema::dropIfExists('comision_escalas_bodega');
    }
};
