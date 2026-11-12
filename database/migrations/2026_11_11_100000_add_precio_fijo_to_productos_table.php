<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Precio fijo: el precio de venta lo pone la empresa a mano (para estar
     * mejor que la competencia) y ni las compras —que cambian el costo
     * promedio— ni un cambio de margen lo tocan. Apagado, que es lo normal,
     * el precio sale del costo promedio con el % de utilidad.
     */
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table): void {
            $table->boolean('precio_fijo')->default(false)->after('margen');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table): void {
            $table->dropColumn('precio_fijo');
        });
    }
};
