<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cargas del camión y su liquidación.
     *
     * Una carga es lo que sale en un camión un día: quién sale (el vendedor
     * que entrega, no tiene que ser el que vendió), el camión y las facturas
     * impresas que lleva. Al regresar se liquida: lo que se entregó, lo que
     * no (se anula o sale en otra carga), lo devuelto, y el efectivo, los
     * cheques y las transferencias que trajo. La diferencia queda guardada.
     *
     *  - carga_factura: cada factura de la carga y cómo terminó (en_ruta,
     *    entregada, anulada, otra_carga). Una factura está en ruta o
     *    entregada en una sola carga (índice único parcial).
     *  - carga_pagos: los cheques y las transferencias, uno por uno.
     *  - devoluciones.carga_id: la devolución que se registró mientras la
     *    factura iba en el camión se descuenta en esa liquidación.
     */
    public function up(): void
    {
        Schema::create('cargas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bodega_id')->constrained('bodegas')->restrictOnDelete();
            $table->date('fecha');
            $table->foreignId('repartidor_id')->constrained('users')->restrictOnDelete();
            $table->string('camion', 40)->nullable();
            $table->string('estado', 20)->default('en_ruta');
            // Con lo que salió (las facturas vigentes al armarla).
            $table->decimal('total_carga', 14, 2)->default(0);
            // Al liquidar: lo que debía traer, lo que trajo y la diferencia.
            $table->decimal('entregado', 14, 2)->nullable();
            $table->decimal('no_entregado', 14, 2)->nullable();
            $table->decimal('devuelto', 14, 2)->nullable();
            $table->decimal('debe_traer', 14, 2)->nullable();
            $table->decimal('efectivo', 14, 2)->nullable();
            $table->decimal('cheques', 14, 2)->nullable();
            $table->decimal('transferencias', 14, 2)->nullable();
            $table->decimal('trajo', 14, 2)->nullable();
            $table->decimal('diferencia', 14, 2)->nullable();
            $table->text('nota')->nullable();
            $table->timestamp('liquidada_at')->nullable();
            $table->foreignId('liquidada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['bodega_id', 'fecha']);
            $table->index(['repartidor_id', 'fecha']);
            $table->index('estado');
        });

        Schema::create('carga_factura', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('carga_id')->constrained('cargas')->cascadeOnDelete();
            $table->foreignId('factura_id')->constrained('facturas')->restrictOnDelete();
            $table->string('estado', 20)->default('en_ruta');
            $table->timestamps();

            $table->unique(['carga_id', 'factura_id']);
            $table->index('factura_id');
        });

        // Una factura va en ruta o queda entregada en una sola carga.
        DB::statement("CREATE UNIQUE INDEX carga_factura_una_carga ON carga_factura (factura_id) WHERE estado IN ('en_ruta', 'entregada')");

        Schema::create('carga_pagos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('carga_id')->constrained('cargas')->cascadeOnDelete();
            $table->string('tipo', 20);
            $table->foreignId('factura_id')->nullable()->constrained('facturas')->nullOnDelete();
            $table->string('banco', 80)->nullable();
            $table->string('referencia', 80)->nullable();
            $table->decimal('monto', 14, 2);
            $table->timestamps();

            $table->index(['carga_id', 'tipo']);
        });

        Schema::table('devoluciones', function (Blueprint $table): void {
            $table->foreignId('carga_id')->nullable()->after('factura_id')->constrained('cargas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('devoluciones', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('carga_id');
        });

        Schema::dropIfExists('carga_pagos');
        Schema::dropIfExists('carga_factura');
        Schema::dropIfExists('cargas');
    }
};
