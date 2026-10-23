<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rutas de cada bodega: los municipios que cubre y los vendedores que
     * la trabajan. Un vendedor solo ve los clientes de sus rutas.
     */
    public function up(): void
    {
        Schema::create('rutas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bodega_id')->constrained('bodegas')->restrictOnDelete();
            $table->string('nombre', 120);
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['bodega_id', 'nombre']);
        });

        Schema::create('ruta_municipio', function (Blueprint $table): void {
            $table->foreignId('ruta_id')->constrained('rutas')->cascadeOnDelete();
            $table->foreignId('municipio_id')->constrained('municipios')->restrictOnDelete();

            $table->primary(['ruta_id', 'municipio_id']);
            $table->index('municipio_id');
        });

        Schema::create('ruta_vendedor', function (Blueprint $table): void {
            $table->foreignId('ruta_id')->constrained('rutas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->primary(['ruta_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ruta_vendedor');
        Schema::dropIfExists('ruta_municipio');
        Schema::dropIfExists('rutas');
    }
};
