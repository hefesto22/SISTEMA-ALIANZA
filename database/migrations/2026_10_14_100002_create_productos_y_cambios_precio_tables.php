<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El precio es UNO SOLO para todas las bodegas. Costo y precio se
        // guardan por unidad y sin ISV; caja e ISV se calculan al mostrar.
        Schema::create('productos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();
            $table->foreignId('categoria_id')->constrained('categorias')->restrictOnDelete();
            $table->string('codigo', 50);
            $table->string('descripcion');
            $table->unsignedInteger('unidades_por_caja')->default(1);
            $table->boolean('gravado')->default(true);
            // Margen propio del producto; null = usa el de su categoría.
            $table->decimal('margen', 6, 4)->nullable();
            $table->decimal('costo_unidad', 14, 4)->default(0);
            $table->decimal('precio_unidad', 14, 4)->default(0);
            $table->timestamp('precio_actualizado_at')->nullable();
            $table->foreignId('precio_actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['proveedor_id', 'codigo']);
            $table->index('descripcion');
            $table->index(['proveedor_id', 'activo']);
        });

        // Historial: cada cambio de costo/precio con quién, cuándo y desde qué bodega.
        Schema::create('cambios_precio', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('bodega_id')->nullable()->constrained('bodegas')->nullOnDelete();
            $table->string('motivo', 20);
            $table->decimal('costo_anterior', 14, 4)->nullable();
            $table->decimal('costo_nuevo', 14, 4);
            $table->decimal('precio_anterior', 14, 4)->nullable();
            $table->decimal('precio_nuevo', 14, 4);
            $table->decimal('margen', 6, 4);
            $table->string('nota')->nullable();
            $table->timestamps();

            $table->index(['producto_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cambios_precio');
        Schema::dropIfExists('productos');
    }
};
