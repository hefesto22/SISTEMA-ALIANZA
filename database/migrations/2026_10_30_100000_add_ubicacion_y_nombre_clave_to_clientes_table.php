<?php

declare(strict_types=1);

use App\Support\Texto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - ubicacion: el punto del negocio en el mapa, {"lat": …, "lng": …}.
     * - nombre_clave: el nombre sin tildes, signos ni espacios de más, para
     *   no registrar dos veces el mismo cliente ("Pulpería  Ana." = "PULPERIA ANA").
     *
     * Los clientes que ya existen quedan sin ubicación y se les calcula la clave.
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->string('nombre_clave')->nullable()->after('nombre');
            $table->json('ubicacion')->nullable()->after('direccion');

            $table->index('nombre_clave');
        });

        DB::table('clientes')
            ->select(['id', 'nombre'])
            ->chunkById(500, function (Collection $filas): void {
                foreach ($filas as $fila) {
                    $fila = (array) $fila;
                    $nombre = is_string($fila['nombre'] ?? null) ? $fila['nombre'] : '';

                    DB::table('clientes')->where('id', $fila['id'])->update(['nombre_clave' => Texto::clave($nombre)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->dropIndex(['nombre_clave']);
            $table->dropColumn(['nombre_clave', 'ubicacion']);
        });
    }
};
