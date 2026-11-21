<?php

declare(strict_types=1);

use App\Models\Configuracion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las notas de crédito se emiten desde el sistema, con el CAI de notas de
 * crédito (tipo 07) de la bodega: encendido de fábrica y en la configuración
 * que ya existe. Se puede apagar en Configuración → Devoluciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion', function (Blueprint $table): void {
            $table->boolean('notas_credito')->default(true)->change();
        });

        DB::table('configuracion')->update(['notas_credito' => true]);
        Cache::forget(Configuracion::CACHE);
    }

    public function down(): void
    {
        Schema::table('configuracion', function (Blueprint $table): void {
            $table->boolean('notas_credito')->default(false)->change();
        });

        Cache::forget(Configuracion::CACHE);
    }
};
