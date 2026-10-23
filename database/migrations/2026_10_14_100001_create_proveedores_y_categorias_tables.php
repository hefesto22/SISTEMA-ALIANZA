<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 20)->nullable()->unique();
            $table->string('nombre');
            $table->string('razon_social')->nullable();
            $table->string('rtn', 20)->nullable();
            $table->string('telefono', 60)->nullable();
            $table->string('direccion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('nombre');
        });

        // Cada proveedor maneja sus propias categorías con su % de utilidad.
        // El margen es sobre el precio de venta: precio = costo / (1 - margen).
        Schema::create('categorias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();
            $table->string('nombre');
            $table->decimal('margen', 6, 4);
            $table->timestamps();

            $table->unique(['proveedor_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias');
        Schema::dropIfExists('proveedores');
    }
};
