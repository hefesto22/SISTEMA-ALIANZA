<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inventario por bodega y compras a proveedores.
     *
     * - configuracion: una sola fila con las reglas globales (costo promedio o
     *   último, qué precio se cobra al despachar, si se controla la existencia).
     * - existencias: cuánto hay de cada producto en cada bodega, en UNIDADES.
     * - movimientos_inventario: el kárdex; cada entrada y salida con la
     *   existencia que quedó, el costo de ese momento y de dónde vino.
     * - compras / compra_items: lo que entra de un proveedor a una bodega.
     */
    public function up(): void
    {
        Schema::create('configuracion', function (Blueprint $table): void {
            $table->id();
            $table->string('metodo_costo', 20)->default('promedio');
            $table->string('precio_al_despachar', 20)->default('despacho');
            $table->boolean('controlar_existencias')->default(false);
            $table->timestamps();
        });

        // La única fila, ya con los valores de inicio: así nunca se crea a
        // medias dentro de una transacción que después se deshace.
        DB::table('configuracion')->insert([
            'metodo_costo'          => 'promedio',
            'precio_al_despachar'   => 'despacho',
            'controlar_existencias' => false,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        Schema::create('existencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bodega_id')->constrained('bodegas')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            // En unidades; puede quedar negativa si no se controla la existencia.
            $table->integer('cantidad')->default(0);
            $table->timestamps();

            $table->unique(['bodega_id', 'producto_id']);
            $table->index('producto_id');
        });

        Schema::create('compras', function (Blueprint $table): void {
            $table->id();
            $table->string('numero', 30)->nullable()->unique();
            $table->foreignId('bodega_id')->constrained('bodegas')->restrictOnDelete();
            $table->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();
            $table->string('factura_proveedor', 60)->nullable();
            $table->date('fecha');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('isv', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notas')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['bodega_id', 'fecha']);
            $table->index('proveedor_id');
        });

        Schema::create('compra_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('compra_id')->constrained('compras')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->string('unidad', 10);
            $table->unsignedInteger('cantidad');
            // Lo mismo en unidades (cajas × unidades por caja).
            $table->unsignedInteger('unidades');
            // Como se escribió: por caja o por unidad, con ISV si es gravado.
            $table->decimal('costo_ingresado', 14, 4);
            // Por unidad y sin ISV, como se guarda el costo del producto.
            $table->decimal('costo_unidad', 14, 4);
            $table->boolean('gravado')->default(true);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('isv', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            // Cómo quedó el producto con esta compra.
            $table->decimal('costo_anterior', 14, 4)->nullable();
            $table->decimal('costo_nuevo', 14, 4);
            $table->decimal('precio_nuevo', 14, 4);
            $table->timestamps();
        });

        Schema::create('movimientos_inventario', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bodega_id')->constrained('bodegas')->restrictOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->string('tipo', 20);
            // En unidades: positivo entra, negativo sale.
            $table->integer('cantidad');
            // Lo que quedó en la bodega después de este movimiento.
            $table->integer('existencia');
            // Por unidad y sin ISV.
            $table->decimal('costo_unidad', 14, 4)->default(0);
            $table->foreignId('compra_id')->nullable()->constrained('compras')->nullOnDelete();
            $table->foreignId('pedido_id')->nullable()->constrained('pedidos')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nota')->nullable();
            $table->timestamps();

            $table->index(['producto_id', 'bodega_id', 'id']);
            $table->index(['pedido_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
        Schema::dropIfExists('compra_items');
        Schema::dropIfExists('compras');
        Schema::dropIfExists('existencias');
        Schema::dropIfExists('configuracion');
    }
};
