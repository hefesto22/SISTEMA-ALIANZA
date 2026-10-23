<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 30)->nullable()->unique();
            $table->string('nombre');
            $table->string('rtn', 14)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('direccion')->nullable();
            $table->foreignId('bodega_id')->nullable()->constrained('bodegas')->nullOnDelete();
            $table->foreignId('vendedor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('nombre');
            $table->index(['bodega_id', 'activo']);
        });

        Schema::create('pedidos', function (Blueprint $table): void {
            $table->id();
            $table->string('numero', 30)->nullable()->unique();
            $table->foreignId('bodega_id')->constrained('bodegas')->restrictOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('vendedor_id')->constrained('users')->restrictOnDelete();
            $table->string('estado', 20)->default('pendiente');
            $table->date('fecha');
            $table->text('notas')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('isv', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->foreignId('validado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validado_at')->nullable();
            $table->timestamps();

            $table->index(['bodega_id', 'estado']);
            $table->index(['vendedor_id', 'fecha']);
        });

        Schema::create('pedido_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->string('unidad', 10)->default('unidad');
            $table->unsignedInteger('cantidad');
            // null mientras bodega no valida; 0 = no salió.
            $table->unsignedInteger('cantidad_despachada')->nullable();
            // Precio por unidad de venta (unidad o caja), sin ISV.
            $table->decimal('precio', 14, 4)->default(0);
            $table->boolean('gravado')->default(true);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('isv', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_items');
        Schema::dropIfExists('pedidos');
        Schema::dropIfExists('clientes');
    }
};
