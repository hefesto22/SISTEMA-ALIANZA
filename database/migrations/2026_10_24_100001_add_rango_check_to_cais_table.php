<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El próximo número de un CAI siempre está dentro de su rango autorizado
     * (o uno después del último, cuando ya se acabó). Si algo intentara
     * dejarlo fuera, la base de datos lo rechaza.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Los que se hayan editado antes de este arreglo: siguiente al inicio del rango si no tienen facturas.
        DB::statement('UPDATE cais SET siguiente = rango_desde WHERE siguiente < rango_desde AND NOT EXISTS (SELECT 1 FROM facturas WHERE facturas.cai_id = cais.id)');

        DB::statement('ALTER TABLE cais ADD CONSTRAINT cais_rango_valido CHECK (rango_desde <= rango_hasta)');
        DB::statement('ALTER TABLE cais ADD CONSTRAINT cais_siguiente_en_rango CHECK (siguiente >= rango_desde AND siguiente <= rango_hasta + 1)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE cais DROP CONSTRAINT IF EXISTS cais_siguiente_en_rango');
        DB::statement('ALTER TABLE cais DROP CONSTRAINT IF EXISTS cais_rango_valido');
    }
};
