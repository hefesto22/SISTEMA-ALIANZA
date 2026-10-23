<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Después de validar, el pedido queda "Por imprimir"; al imprimirlo pasa
     * a "Impreso". Se lleva cuántas veces se imprimió (reimpresiones) y
     * quién lo corrigió después de validado.
     *
     * Los que ya estaban "despachado" quedan "por imprimir".
     */
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table): void {
            $table->unsignedSmallInteger('impresiones')->default(0)->after('validado_at');
            $table->timestamp('impreso_at')->nullable()->after('impresiones');
            $table->foreignId('impreso_por')->nullable()->after('impreso_at')->constrained('users')->nullOnDelete();
            $table->timestamp('corregido_at')->nullable()->after('impreso_por');
            $table->foreignId('corregido_por')->nullable()->after('corregido_at')->constrained('users')->nullOnDelete();
        });

        DB::table('pedidos')->where('estado', 'despachado')->update(['estado' => 'por_imprimir']);
    }

    public function down(): void
    {
        DB::table('pedidos')->whereIn('estado', ['por_imprimir', 'impreso'])->update(['estado' => 'despachado']);

        Schema::table('pedidos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('corregido_por');
            $table->dropColumn('corregido_at');
            $table->dropConstrainedForeignId('impreso_por');
            $table->dropColumn(['impreso_at', 'impresiones']);
        });
    }
};
