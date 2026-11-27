<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Las comisiones se pagan del 1 al 5 del mes siguiente para descontar las
     * devoluciones con crédito (se le devolvió el dinero al cliente) de las
     * facturas del mes: bajan la venta del vendedor en el grupo de cada
     * producto. Las que se registran después de cerrar se descuentan en el
     * siguiente cierre.
     *
     *  - comision_facturas.devolucion_id: la devolución que se descontó
     *    (tipo "devolucion", neta en negativo).
     *  - comision_liquidaciones.devuelto: lo que se descontó por devoluciones.
     */
    public function up(): void
    {
        Schema::table('comision_facturas', function (Blueprint $table): void {
            $table->foreignId('devolucion_id')->nullable()->after('factura_id')->constrained('devoluciones')->nullOnDelete();
            $table->index('devolucion_id');
        });

        Schema::table('comision_liquidaciones', function (Blueprint $table): void {
            $table->decimal('devuelto', 14, 2)->default(0)->after('anulado');
        });
    }

    public function down(): void
    {
        Schema::table('comision_liquidaciones', function (Blueprint $table): void {
            $table->dropColumn('devuelto');
        });

        Schema::table('comision_facturas', function (Blueprint $table): void {
            $table->dropIndex(['devolucion_id']);
            $table->dropConstrainedForeignId('devolucion_id');
        });
    }
};
