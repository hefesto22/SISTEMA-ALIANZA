<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * En qué papel imprime las facturas cada bodega (App\Enums\FormatoFactura):
 * papel continuo media carta para la Epson LX-350 (el de siempre) o carta
 * para una impresora normal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bodegas', function (Blueprint $table): void {
            $table->string('formato_factura', 20)->default('continuo')->after('telefono');
        });
    }

    public function down(): void
    {
        Schema::table('bodegas', function (Blueprint $table): void {
            $table->dropColumn('formato_factura');
        });
    }
};
