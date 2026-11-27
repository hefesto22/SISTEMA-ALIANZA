<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * En la ruta, lo del camión se va sacando cliente por cliente. Si a uno
     * se le cambió producto dañado con lo que llevaba el camión, al último
     * le falta; y a veces algo se daña en el camión o no se subió.
     *
     *  - devoluciones.falto: no es una devolución, al cliente le faltó
     *    producto de su factura (se le da crédito o se le lleva después).
     *  - devoluciones.del_camion: cambio que se le dio en el momento con lo
     *    que llevaba el camión (no queda nada por entregarle).
     *  - devolucion_items.faltante: lo que no recibió porque se le dio a
     *    otro cliente en un cambio (no regresa a la bodega ni es pérdida).
     */
    public function up(): void
    {
        Schema::table('devoluciones', function (Blueprint $table): void {
            $table->boolean('falto')->default(false)->after('tipo');
            $table->boolean('del_camion')->default(false)->after('falto');
        });

        Schema::table('devolucion_items', function (Blueprint $table): void {
            $table->boolean('faltante')->default(false)->after('danado');
        });
    }

    public function down(): void
    {
        Schema::table('devolucion_items', function (Blueprint $table): void {
            $table->dropColumn('faltante');
        });

        Schema::table('devoluciones', function (Blueprint $table): void {
            $table->dropColumn(['falto', 'del_camion']);
        });
    }
};
