<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devoluciones de un pedido ya entregado (App\Domain\Devoluciones\RegistrarDevolucion):
 * lo bueno regresa a la bodega y lo dañado queda marcado. En un cambio se le
 * repone lo mismo al cliente; con crédito se le abona lo que pagó, con nota
 * de crédito (CAI tipo 07) si está encendida en Configuración o interno si no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion', function (Blueprint $table): void {
            $table->boolean('notas_credito')->default(false)->after('controlar_existencias');
        });

        Schema::create('devoluciones', function (Blueprint $table): void {
            $table->id();
            $table->string('numero', 12)->nullable()->unique();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->foreignId('factura_id')->nullable()->constrained('facturas')->nullOnDelete();
            $table->foreignId('bodega_id')->constrained('bodegas')->restrictOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('fecha');
            $table->string('tipo', 10);
            $table->string('motivo')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('importe_exento', 14, 2)->default(0);
            $table->decimal('importe_gravado', 14, 2)->default(0);
            $table->decimal('isv', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            // Nota de crédito: lo que se imprime, como quedó al emitirla.
            $table->string('nota_numero', 19)->nullable()->unique();
            $table->foreignId('cai_id')->nullable()->constrained('cais')->restrictOnDelete();
            $table->unsignedInteger('correlativo')->nullable();
            $table->string('cai', 37)->nullable();
            $table->string('rango_desde', 19)->nullable();
            $table->string('rango_hasta', 19)->nullable();
            $table->date('fecha_limite')->nullable();
            $table->string('cliente_nombre')->nullable();
            $table->string('cliente_rtn', 20)->nullable();
            $table->timestamps();

            $table->index(['bodega_id', 'fecha']);
            $table->unique(['cai_id', 'correlativo']);
        });

        Schema::create('devolucion_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('devolucion_id')->constrained('devoluciones')->cascadeOnDelete();
            $table->foreignId('pedido_item_id')->nullable()->constrained('pedido_items')->nullOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->foreignId('presentacion_id')->constrained('presentaciones')->restrictOnDelete();
            $table->unsignedInteger('cantidad');
            $table->boolean('danado')->default(false);
            $table->decimal('precio', 14, 4)->default(0);
            $table->boolean('gravado')->default(true);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('isv', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('costo', 14, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devolucion_items');
        Schema::dropIfExists('devoluciones');

        Schema::table('configuracion', function (Blueprint $table): void {
            $table->dropColumn('notas_credito');
        });
    }
};
