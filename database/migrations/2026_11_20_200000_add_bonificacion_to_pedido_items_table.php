<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La línea de un regalo de promoción gratis (bonificación): la agrega sola
 * RecalcularPedido, con 100 % de descuento. No la escribe el vendedor ni la
 * cuenta la condición de ninguna promoción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedido_items', function (Blueprint $table): void {
            $table->boolean('bonificacion')->default(false)->after('promocion_id');
        });
    }

    public function down(): void
    {
        Schema::table('pedido_items', function (Blueprint $table): void {
            $table->dropColumn('bonificacion');
        });
    }
};
