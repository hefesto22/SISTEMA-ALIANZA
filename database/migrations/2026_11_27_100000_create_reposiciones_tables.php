<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cambios por entregar: lo que se le debe llevar a un cliente por un
     * cambio por cambio. Lo de antes ya se dio al registrarse (salió de la
     * bodega en ese momento): no se crea nada para eso.
     *
     *  - reposiciones: una por devolución de cambio. A quién se le lleva (el
     *    mismo cliente o al que se le quedó debiendo porque su producto se le
     *    dio al otro), en qué está (por entregar, en ruta, entregada).
     *  - carga_reposiciones: en qué carga va y cómo terminó (en ruta,
     *    entregada o sale en otra carga).
     */
    public function up(): void
    {
        Schema::create('reposiciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('devolucion_id')->constrained('devoluciones')->cascadeOnDelete();
            $table->foreignId('bodega_id')->constrained('bodegas')->restrictOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('pedido_id')->nullable()->constrained('pedidos')->nullOnDelete();
            $table->string('estado', 15)->default('por_entregar');
            $table->string('nota')->nullable();
            $table->timestamp('entregada_at')->nullable();
            $table->timestamps();

            $table->unique('devolucion_id');
            $table->index(['bodega_id', 'estado']);
        });

        Schema::create('carga_reposiciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('carga_id')->constrained('cargas')->cascadeOnDelete();
            $table->foreignId('reposicion_id')->constrained('reposiciones')->cascadeOnDelete();
            $table->string('estado', 15)->default('en_ruta');
            $table->timestamps();

            $table->unique(['carga_id', 'reposicion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carga_reposiciones');
        Schema::dropIfExists('reposiciones');
    }
};
