<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Número de ruta (la ruta 22 de la bodega) y la ruta de cada pedido.
     *
     *  - rutas.numero: único en la bodega. A las que ya existen se les pone el
     *    número que traiga el nombre ("RUTA 22 · …" → 22) y, si no trae o se
     *    repite, el siguiente libre.
     *  - pedidos.ruta_id: la ruta en que se vendió; queda fija al emitir la
     *    primera factura (sale en la factura, en Facturas y en las cargas). A
     *    los pedidos ya facturados se les pone la de su vendedor que lleva el
     *    municipio del cliente (o la primera suya), como salía en la factura.
     */
    public function up(): void
    {
        Schema::table('rutas', function (Blueprint $table): void {
            $table->unsignedSmallInteger('numero')->nullable()->after('bodega_id');
        });

        $porBodega = DB::table('rutas')->orderBy('bodega_id')->orderBy('nombre')->orderBy('id')->get(['id', 'bodega_id', 'nombre'])->groupBy('bodega_id');

        foreach ($porBodega as $rutas) {
            $usados = [];
            $sinNumero = [];

            foreach ($rutas as $ruta) {
                $numero = preg_match('/\d+/', (string) $ruta->nombre, $encontrado) === 1 ? (int) $encontrado[0] : 0;

                if ($numero >= 1 && $numero <= 9999 && ! isset($usados[$numero])) {
                    $usados[$numero] = true;
                    DB::table('rutas')->where('id', $ruta->id)->update(['numero' => $numero]);
                } else {
                    $sinNumero[] = $ruta->id;
                }
            }

            $siguiente = 1;

            foreach ($sinNumero as $id) {
                while (isset($usados[$siguiente])) {
                    $siguiente++;
                }

                $usados[$siguiente] = true;
                DB::table('rutas')->where('id', $id)->update(['numero' => $siguiente]);
            }
        }

        Schema::table('rutas', function (Blueprint $table): void {
            $table->unsignedSmallInteger('numero')->nullable(false)->change();
            $table->unique(['bodega_id', 'numero']);
        });

        Schema::table('pedidos', function (Blueprint $table): void {
            $table->foreignId('ruta_id')->nullable()->after('vendedor_id')->constrained('rutas')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE pedidos SET ruta_id = (
                SELECT rutas.id
                FROM rutas
                JOIN ruta_vendedor ON ruta_vendedor.ruta_id = rutas.id AND ruta_vendedor.user_id = pedidos.vendedor_id
                LEFT JOIN clientes ON clientes.id = pedidos.cliente_id
                LEFT JOIN ruta_municipio ON ruta_municipio.ruta_id = rutas.id AND ruta_municipio.municipio_id = clientes.municipio_id
                WHERE rutas.activa
                ORDER BY (ruta_municipio.municipio_id IS NULL), rutas.nombre
                LIMIT 1
            )
            WHERE ruta_id IS NULL
              AND EXISTS (SELECT 1 FROM facturas WHERE facturas.pedido_id = pedidos.id)
            SQL);
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ruta_id');
        });

        Schema::table('rutas', function (Blueprint $table): void {
            $table->dropUnique(['bodega_id', 'numero']);
            $table->dropColumn('numero');
        });
    }
};
