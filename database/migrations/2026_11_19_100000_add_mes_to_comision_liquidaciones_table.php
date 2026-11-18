<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La meta y los escalones de comisión son del MES: la segunda quincena suma
 * lo que se vendió en la primera y paga lo que gana en el mes menos el
 * adelanto que ya se dio. Al cerrar se guarda con qué se calculó:
 *
 *  - base_antes: lo que ya llevaba vendido el mes (la primera quincena).
 *  - pagado_antes: lo que ya se le había pagado en el mes (el adelanto).
 *
 * El escalón (porcentaje) sale de base + base_antes; comision es lo que se
 * pagó en el periodo (lo del mes menos pagado_antes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comision_liquidaciones', function (Blueprint $table): void {
            $table->decimal('base_antes', 14, 2)->default(0);
            $table->decimal('pagado_antes', 14, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('comision_liquidaciones', function (Blueprint $table): void {
            $table->dropColumn(['base_antes', 'pagado_antes']);
        });
    }
};
