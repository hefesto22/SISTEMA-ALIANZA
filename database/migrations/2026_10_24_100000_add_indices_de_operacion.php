<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PostgreSQL no crea índice solo por tener llave foránea. Sin estos, las
     * líneas de cada pedido, los conteos de la lista y la impresión recorren
     * la tabla completa (con un año de pedidos se vuelve lento).
     *
     * @var array<string, list<array{0: list<string>, 1: string}>>
     */
    private const INDICES = [
        'pedido_items' => [
            [['pedido_id'], 'pedido_items_pedido_idx'],
            [['producto_id'], 'pedido_items_producto_idx'],
            [['promocion_id'], 'pedido_items_promocion_idx'],
        ],
        'pedidos' => [
            [['cliente_id'], 'pedidos_cliente_idx'],
            [['estado', 'created_at'], 'pedidos_estado_creado_idx'],
            [['validado_at'], 'pedidos_validado_idx'],
        ],
        'facturas' => [
            [['fecha_emision'], 'facturas_fecha_emision_idx'],
            [['reemplaza_a_id'], 'facturas_reemplaza_a_idx'],
        ],
        'cai_bodega' => [
            [['bodega_id'], 'cai_bodega_bodega_idx'],
        ],
        'users' => [
            [['bodega_id'], 'users_bodega_idx'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDICES as $tabla => $indices) {
            Schema::table($tabla, function (Blueprint $table) use ($indices): void {
                foreach ($indices as [$columnas, $nombre]) {
                    $table->index($columnas, $nombre);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDICES as $tabla => $indices) {
            Schema::table($tabla, function (Blueprint $table) use ($indices): void {
                foreach ($indices as [, $nombre]) {
                    $table->dropIndex($nombre);
                }
            });
        }
    }
};
