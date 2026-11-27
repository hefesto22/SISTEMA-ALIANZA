<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Venta en bodega (mayoreo): un usuario de bodega le vende al cliente que
     * llega a la bodega y le puede dar un descuento a todo el pedido, hasta
     * el tope que tiene en Usuarios y nunca por debajo del costo. Los
     * vendedores de ruta no dan descuento.
     *
     *  - users.descuento_maximo: el tope (%) que puede dar ese usuario.
     *  - pedidos.en_bodega: venta en bodega (su vendedor es el usuario de
     *    bodega; no cuenta para comisiones).
     *  - pedidos.se_lo_lleva: el cliente se lo llevó; no va en camión.
     *  - pedidos.descuento_mayoreo_porcentaje: el % que se dio.
     *  - pedidos.descuento_mayoreo y pedido_items.descuento_mayoreo: cuánto
     *    fue (va dentro de `descuento`, junto a las promociones; una línea
     *    tiene una cosa o la otra).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('descuento_maximo', 5, 2)->default(0);
        });

        Schema::table('pedidos', function (Blueprint $table): void {
            $table->boolean('en_bodega')->default(false);
            $table->boolean('se_lo_lleva')->default(false);
            $table->decimal('descuento_mayoreo_porcentaje', 5, 2)->default(0);
            $table->decimal('descuento_mayoreo', 12, 2)->default(0);
        });

        Schema::table('pedido_items', function (Blueprint $table): void {
            $table->decimal('descuento_mayoreo', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('pedido_items', function (Blueprint $table): void {
            $table->dropColumn('descuento_mayoreo');
        });

        Schema::table('pedidos', function (Blueprint $table): void {
            $table->dropColumn(['en_bodega', 'se_lo_lleva', 'descuento_mayoreo_porcentaje', 'descuento_mayoreo']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('descuento_maximo');
        });
    }
};
