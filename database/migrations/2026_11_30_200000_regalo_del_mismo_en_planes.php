<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * En las líneas del plan del proveedor cada producto cuenta aparte y se
     * regala el producto que dice la hoja ("Producto a bonificar": 24 de
     * néctar pera → 1 de néctar manzana).
     *  - se_mezclan: la línea sí deja juntar sus productos (suavizante de
     *    dos aromas: 6 + 6 = 12); se regala el producto de la hoja.
     *  - regala_el_mismo: cada producto regala de sí mismo (20 de Intensa →
     *    1 de Intensa); producto_beneficio_id es solo el primero de la línea.
     */
    public function up(): void
    {
        Schema::table('promociones', function (Blueprint $table): void {
            $table->boolean('se_mezclan')->default(false)->after('compra_unidades');
            $table->boolean('regala_el_mismo')->default(false)->after('se_mezclan');
        });
    }

    public function down(): void
    {
        Schema::table('promociones', function (Blueprint $table): void {
            $table->dropColumn(['se_mezclan', 'regala_el_mismo']);
        });
    }
};
