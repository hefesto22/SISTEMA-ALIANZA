<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promociones del tipo "si compra X, Y sale gratis / a mitad / con N%".
     * El descuento que deja cada promoción se guarda en la línea del pedido.
     */
    public function up(): void
    {
        Schema::create('promociones', function (Blueprint $table): void {
            $table->id();
            // PROMO-0001: se genera solo al crearla.
            $table->string('codigo', 20)->nullable()->unique();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->foreignId('producto_condicion_id')->constrained('productos')->restrictOnDelete();
            $table->unsignedInteger('cantidad_condicion')->default(1);
            $table->string('condicion_unidad', 10)->default('unidad');
            $table->foreignId('producto_beneficio_id')->constrained('productos')->restrictOnDelete();
            $table->unsignedInteger('cantidad_beneficio')->default(1);
            $table->string('beneficio_unidad', 10)->default('unidad');
            // 100 = gratis, 50 = mitad de precio.
            $table->decimal('descuento_porcentaje', 5, 2)->default(100);
            $table->boolean('activa')->default(true);
            $table->date('vigente_desde')->nullable();
            $table->date('vigente_hasta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['activa', 'vigente_desde', 'vigente_hasta']);
        });

        Schema::table('pedido_items', function (Blueprint $table): void {
            // Descuento de la línea (sin ISV) y la promoción que lo dio.
            $table->decimal('descuento', 14, 2)->default(0)->after('subtotal');
            $table->foreignId('promocion_id')->nullable()->after('descuento')->constrained('promociones')->nullOnDelete();
        });

        Schema::table('pedidos', function (Blueprint $table): void {
            $table->decimal('descuento', 14, 2)->default(0)->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table): void {
            $table->dropColumn('descuento');
        });

        Schema::table('pedido_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('promocion_id');
            $table->dropColumn('descuento');
        });

        Schema::dropIfExists('promociones');
    }
};
