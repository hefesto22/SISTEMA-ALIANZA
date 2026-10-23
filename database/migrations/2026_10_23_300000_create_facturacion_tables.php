<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Facturación con CAI (Acuerdo 481-2017 del SAR):
     *
     *  - datos_fiscales: una sola fila con los datos del obligado tributario.
     *  - cais: autorizaciones con su rango; `siguiente` es el próximo correlativo.
     *  - cai_bodega: a qué bodegas sirve cada CAI (sin filas = global).
     *  - facturas: una por impresión original; si el pedido se corrige después
     *    de impreso, la factura queda anulada y se emite otra que la sustituye.
     */
    public function up(): void
    {
        Schema::create('datos_fiscales', function (Blueprint $table): void {
            $table->id();
            $table->string('razon_social', 150)->nullable();
            $table->string('nombre_comercial', 150)->nullable();
            $table->string('rtn', 14)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->string('telefono', 40)->nullable();
            $table->string('correo', 120)->nullable();
            // Pie de la factura (datos del autoimpresor o imprenta, leyendas).
            $table->text('leyenda')->nullable();
            $table->timestamps();
        });

        Schema::create('cais', function (Blueprint $table): void {
            $table->id();
            $table->string('cai', 37)->unique();
            $table->char('establecimiento', 3);
            $table->char('punto_emision', 3);
            $table->char('tipo_documento', 2)->default('01');
            $table->unsignedInteger('rango_desde');
            $table->unsignedInteger('rango_hasta');
            $table->unsignedInteger('siguiente');
            $table->date('fecha_limite');
            $table->boolean('activo')->default(true);
            $table->string('notas', 255)->nullable();
            $table->timestamps();

            $table->index(['activo', 'fecha_limite']);
        });

        Schema::create('cai_bodega', function (Blueprint $table): void {
            $table->foreignId('cai_id')->constrained('cais')->cascadeOnDelete();
            $table->foreignId('bodega_id')->constrained('bodegas')->cascadeOnDelete();

            $table->primary(['cai_id', 'bodega_id']);
        });

        Schema::create('facturas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->restrictOnDelete();
            $table->foreignId('bodega_id')->constrained('bodegas')->restrictOnDelete();
            $table->foreignId('cai_id')->constrained('cais')->restrictOnDelete();
            $table->string('numero', 19)->unique();
            $table->unsignedInteger('correlativo');
            // Copia de la autorización al emitir: si después se edita el CAI, la factura no cambia.
            $table->string('cai', 37);
            $table->string('rango_desde', 19);
            $table->string('rango_hasta', 19);
            $table->date('fecha_limite');
            $table->timestamp('fecha_emision');
            $table->string('cliente_nombre', 150);
            $table->string('cliente_rtn', 14)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('descuento', 14, 2)->default(0);
            $table->decimal('importe_exento', 14, 2)->default(0);
            $table->decimal('importe_gravado', 14, 2)->default(0);
            $table->decimal('isv', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->unsignedSmallInteger('impresiones')->default(0);
            $table->string('estado', 10)->default('vigente');
            $table->foreignId('reemplaza_a_id')->nullable()->constrained('facturas')->nullOnDelete();
            $table->foreignId('emitida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulada_at')->nullable();
            $table->foreignId('anulada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_anulacion', 255)->nullable();
            $table->timestamps();

            $table->unique(['cai_id', 'correlativo']);
            $table->index(['pedido_id', 'estado']);
            $table->index(['bodega_id', 'fecha_emision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturas');
        Schema::dropIfExists('cai_bodega');
        Schema::dropIfExists('cais');
        Schema::dropIfExists('datos_fiscales');
    }
};
