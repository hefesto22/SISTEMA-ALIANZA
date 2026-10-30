<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "No salió" (impresora atascada, se canceló): se vuelve a imprimir la
     * misma hoja sin contarla como copia. Queda registrado cuántas veces,
     * cuándo fue la última y quién.
     */
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table): void {
            $table->unsignedSmallInteger('reintentos')->default(0)->after('impresiones');
            $table->timestamp('reintento_at')->nullable()->after('reintentos');
            $table->foreignId('reintento_por')->nullable()->after('reintento_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reintento_por');
            $table->dropColumn(['reintento_at', 'reintentos']);
        });
    }
};
