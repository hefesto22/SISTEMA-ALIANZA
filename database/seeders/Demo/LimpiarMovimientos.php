<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Cai;
use App\Models\ComisionPeriodo;
use App\Models\Compra;
use App\Models\Devolucion;
use App\Models\Factura;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use Illuminate\Support\Facades\DB;

/**
 * Deja la base local sin operación para generar el historial de
 * demostración: borra pedidos, facturas, devoluciones, compras, kárdex,
 * existencias, cierres de comisiones y cargas del camión, y la numeración
 * vuelve a empezar en 1.
 * El catálogo, los precios, los clientes, las rutas, los usuarios y la
 * configuración no se tocan.
 *
 * No se usa si hay documentos de un CAI del SAR (facturas o notas de
 * crédito) o un CAI del SAR activo para facturar o para notas de crédito.
 * Lo del CAI de prueba (facturas y notas) sí se borra.
 */
final class LimpiarMovimientos
{
    /** Todas juntas: TRUNCATE pide en la misma orden las tablas que se apuntan entre sí. */
    public const TABLAS = [
        'carga_pagos',
        'carga_factura',
        'cargas',
        'devolucion_items',
        'devoluciones',
        'comision_facturas',
        'comision_liquidaciones',
        'comision_periodos',
        'facturas',
        'movimientos_inventario',
        'existencias',
        'pedido_items',
        'pedidos',
        'compra_items',
        'compras',
    ];

    /** Los CAI de prueba alcanzan para la demostración. */
    private const RANGO_PRUEBA = 50_000;

    /**
     * Lo que hay ahora (lo que se borraría).
     *
     * @return array<string, int>
     */
    public function resumen(): array
    {
        return [
            'Pedidos'               => Pedido::query()->count(),
            'Facturas'              => Factura::query()->count(),
            'Devoluciones'          => Devolucion::query()->count(),
            'Compras'               => Compra::query()->count(),
            'Renglones del kárdex'  => MovimientoInventario::query()->count(),
            'Cierres de comisiones' => ComisionPeriodo::query()->count(),
        ];
    }

    /**
     * Por qué no se puede (null = sí se puede).
     */
    public function impedimento(): ?string
    {
        /** @var list<string> $delSar */
        $delSar = Factura::query()->where('cai', '!=', Cai::PRUEBA)->orderBy('numero')->limit(5)->pluck('numero')->all();

        if ($delSar !== []) {
            return 'Hay facturas de un CAI del SAR ('.implode(', ', $delSar).'): la demostración solo se genera en una base de prueba.';
        }

        /** @var list<string> $notas */
        $notas = Devolucion::query()->whereNotNull('nota_numero')->where('cai', '!=', Cai::PRUEBA_NOTAS)->orderBy('nota_numero')->limit(5)->pluck('nota_numero')->all();

        if ($notas !== []) {
            return 'Hay notas de crédito emitidas ('.implode(', ', $notas).'): la demostración solo se genera en una base de prueba.';
        }

        /** @var list<string> $activos */
        $activos = Cai::query()->vigentes()->whereIn('tipo_documento', [Cai::FACTURA, Cai::NOTA_CREDITO])->whereNotIn('cai', Cai::DE_PRUEBA)->orderBy('id')->pluck('cai')->all();

        if ($activos !== []) {
            return 'Hay CAI del SAR activos para facturar o para notas de crédito ('.implode(', ', $activos).'): desactívelos en Administración > CAI para que la demostración use los CAI de prueba.';
        }

        return null;
    }

    public function ejecutar(): void
    {
        DB::transaction(function (): void {
            DB::statement('TRUNCATE TABLE '.implode(', ', self::TABLAS).' RESTART IDENTITY');

            // Los CAI de prueba vuelven a su primer número, activos, con números y fecha de sobra.
            Cai::query()->whereIn('cai', Cai::DE_PRUEBA)->update([
                'siguiente'    => DB::raw('rango_desde'),
                'rango_hasta'  => DB::raw('GREATEST(rango_hasta, '.self::RANGO_PRUEBA.')'),
                'fecha_limite' => DB::raw('GREATEST(fecha_limite, CURRENT_DATE + 365)'),
                'activo'       => true,
            ]);
        });
    }
}
