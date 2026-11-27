<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orden de cambio: lo que se le debe llevar a un cliente por un cambio
     * por cambio (reposiciones) ahora es un documento interno, sin valor
     * fiscal, con número (CAM-000001). Sale en Pedidos → Por imprimir; ya
     * impresa pasa a Impreso y se sube a la carga como las facturas; el
     * cliente firma al recibir.
     *
     *  - reposiciones.numero: CAM-000001 (el id).
     *  - reposiciones.impresa_at: la primera vez que se imprimió (solo las
     *    impresas se suben a una carga).
     *  - reposiciones.impresiones: cuántas veces (de la segunda en adelante
     *    dice COPIA).
     *
     * Las que ya están en ruta o entregadas quedan como impresas (ya
     * salieron); las que esperan carga quedan por imprimir.
     */
    public function up(): void
    {
        Schema::table('reposiciones', function (Blueprint $table): void {
            $table->string('numero', 20)->nullable()->unique();
            $table->timestamp('impresa_at')->nullable();
            $table->unsignedInteger('impresiones')->default(0);
        });

        DB::table('reposiciones')->update(['numero' => DB::raw("'CAM-' || LPAD(id::text, 6, '0')")]);
        DB::table('reposiciones')
            ->where('estado', '!=', 'por_entregar')
            ->update(['impresa_at' => DB::raw('created_at'), 'impresiones' => 1]);
    }

    public function down(): void
    {
        Schema::table('reposiciones', function (Blueprint $table): void {
            $table->dropUnique(['numero']);
            $table->dropColumn(['numero', 'impresa_at', 'impresiones']);
        });
    }
};
