<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La condición de una promoción pasa a ser una lista de productos
     * ("2 cajas de X + 1 caja de Y"). Las promociones que ya existían
     * se pasan tal cual a la tabla nueva.
     */
    public function up(): void
    {
        Schema::create('promocion_condiciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promocion_id')->constrained('promociones')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->unsignedInteger('cantidad')->default(1);
            $table->string('unidad', 10)->default('unidad');

            $table->index('promocion_id');
        });

        foreach (DB::table('promociones')->orderBy('id')->get() as $promocion) {
            $fila = (array) $promocion;

            DB::table('promocion_condiciones')->insert([
                'promocion_id' => $fila['id'],
                'producto_id'  => $fila['producto_condicion_id'],
                'cantidad'     => $fila['cantidad_condicion'],
                'unidad'       => $fila['condicion_unidad'],
            ]);
        }

        Schema::table('promociones', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('producto_condicion_id');
            $table->dropColumn(['cantidad_condicion', 'condicion_unidad']);
        });
    }

    public function down(): void
    {
        Schema::table('promociones', function (Blueprint $table): void {
            $table->foreignId('producto_condicion_id')->nullable()->constrained('productos')->restrictOnDelete();
            $table->unsignedInteger('cantidad_condicion')->default(1);
            $table->string('condicion_unidad', 10)->default('unidad');
        });

        // Vuelve con el primer producto de cada condición.
        foreach (DB::table('promocion_condiciones')->orderBy('id')->get() as $condicion) {
            $fila = (array) $condicion;

            DB::table('promociones')
                ->where('id', $fila['promocion_id'])
                ->whereNull('producto_condicion_id')
                ->update([
                    'producto_condicion_id' => $fila['producto_id'],
                    'cantidad_condicion'    => $fila['cantidad'],
                    'condicion_unidad'      => $fila['unidad'],
                ]);
        }

        Schema::dropIfExists('promocion_condiciones');
    }
};
