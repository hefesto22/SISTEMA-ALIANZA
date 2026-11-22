<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Planes de promociones que manda el proveedor (los "OPL" del mes): cada
     * línea autorizada ("24+1 de NÉCTAR 200, 1,000 unidades") queda como una
     * promoción del plan. Las bodegas del plan pasan a sus promociones
     * (ninguna = todas).
     *
     * En promociones:
     *  - plan_id: el plan del que sale (null = promoción propia).
     *  - compra_unidades: N de "N+M" contando UNIDADES de cualquiera de los
     *    productos de la condición, mezclados (null = presentación exacta,
     *    como hasta ahora).
     *  - tope_unidades: cuántas unidades de regalo autorizó el proveedor
     *    (null = sin tope). Lo que pase de ahí no lo reconoce.
     *  - porcentaje_autorizado: el % de la hoja, para compararlo con el que
     *    da la mecánica.
     */
    public function up(): void
    {
        Schema::create('planes_promocion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();
            $table->string('nombre');
            $table->date('vigente_desde');
            $table->date('vigente_hasta');
            // La hoja que mandó el proveedor (imagen o PDF), de respaldo.
            $table->string('archivo')->nullable();
            $table->text('notas')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vigente_desde', 'vigente_hasta']);
        });

        Schema::create('plan_promocion_bodega', function (Blueprint $table): void {
            $table->foreignId('plan_promocion_id')->constrained('planes_promocion')->cascadeOnDelete();
            $table->foreignId('bodega_id')->constrained('bodegas')->cascadeOnDelete();
            $table->primary(['plan_promocion_id', 'bodega_id']);
            $table->index('bodega_id');
        });

        Schema::table('promociones', function (Blueprint $table): void {
            $table->foreignId('plan_id')->nullable()->after('id')->constrained('planes_promocion')->cascadeOnDelete();
            $table->unsignedInteger('compra_unidades')->nullable()->after('descripcion');
            $table->unsignedInteger('tope_unidades')->nullable()->after('descuento_porcentaje');
            $table->decimal('porcentaje_autorizado', 5, 2)->nullable()->after('tope_unidades');
        });
    }

    public function down(): void
    {
        Schema::table('promociones', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn(['compra_unidades', 'tope_unidades', 'porcentaje_autorizado']);
        });

        Schema::dropIfExists('plan_promocion_bodega');
        Schema::dropIfExists('planes_promocion');
    }
};
