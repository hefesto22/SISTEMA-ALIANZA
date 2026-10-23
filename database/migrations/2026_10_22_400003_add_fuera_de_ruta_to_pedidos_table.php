<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido que un vendedor levantó a un cliente que no es de sus rutas:
     * se permite, pero queda marcado y con el motivo para bodega y administración.
     */
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table): void {
            $table->boolean('fuera_de_ruta')->default(false)->after('notas');
            $table->string('motivo_fuera_ruta')->nullable()->after('fuera_de_ruta');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table): void {
            $table->dropColumn(['fuera_de_ruta', 'motivo_fuera_ruta']);
        });
    }
};
