<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Models\Cai;
use App\Models\ComisionFactura;
use App\Models\Existencia;
use App\Models\Factura;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use Illuminate\Support\Facades\DB;

/**
 * Borra pedidos como si nunca se hubieran levantado. Es para limpiar los de
 * prueba (php artisan pedidos:borrar); un pedido de verdad se anula
 * (pendiente) o se corrige.
 *
 *  - Lo que salió de la bodega regresa: se quitan sus renglones del kárdex
 *    (despacho, correcciones y cajas abiertas) y los renglones que vinieron
 *    después quedan con la existencia que habrían tenido sin ellos.
 *  - Sus facturas se borran solo si son del CAI de prueba y ninguna está en
 *    un periodo de comisiones cerrado. Una factura de un CAI del SAR no se
 *    borra nunca: se anula.
 *  - El CAI de prueba vuelve a su primer número libre y, si ya no queda
 *    ningún pedido, la numeración de los pedidos empieza otra vez en 1.
 *
 * Los clientes, las compras, los costos y los precios no se tocan (el costo
 * que dejó una caja abierta se queda como está).
 */
final class BorrarPedidos
{
    /**
     * Qué se borraría: pedidos por estado, facturas y renglones del kárdex.
     *
     * @param list<int> $ids
     *
     * @return array{pedidos: array<string, int>, facturas: list<string>, movimientos: int}
     */
    public function resumen(array $ids): array
    {
        $porEstado = [];

        foreach (Pedido::query()->whereKey($ids)->get(['id', 'estado']) as $pedido) {
            $estado = $pedido->estado->getLabel();
            $porEstado[$estado] = ($porEstado[$estado] ?? 0) + 1;
        }

        /** @var list<string> $facturas */
        $facturas = Factura::query()->whereIn('pedido_id', $ids)->orderBy('numero')->pluck('numero')->all();

        return [
            'pedidos'     => $porEstado,
            'facturas'    => $facturas,
            'movimientos' => MovimientoInventario::query()->whereIn('pedido_id', $ids)->count(),
        ];
    }

    /**
     * Por qué no se pueden borrar (null = sí se puede).
     *
     * @param list<int> $ids
     */
    public function impedimento(array $ids): ?string
    {
        $facturas = Factura::query()->whereIn('pedido_id', $ids);

        /** @var list<string> $delSar */
        $delSar = (clone $facturas)->where('cai', '!=', Cai::PRUEBA)->orderBy('numero')->limit(5)->pluck('numero')->all();

        if ($delSar !== []) {
            return 'Tienen facturas de un CAI del SAR ('.implode(', ', $delSar).'): esas no se borran, se anulan corrigiendo el pedido.';
        }

        $enComisiones = ComisionFactura::query()->whereIn('factura_id', (clone $facturas)->select('id'))->exists();

        if ($enComisiones) {
            return 'Sus facturas ya están en un periodo de comisiones cerrado: reabra ese periodo en Comisiones y vuelva a intentarlo.';
        }

        return null;
    }

    /**
     * @param list<int> $ids
     *
     * @return array{pedidos: int, facturas: int, movimientos: int}
     */
    public function ejecutar(array $ids): array
    {
        return DB::transaction(function () use ($ids): array {
            /** @var list<int> $ids */
            $ids = Pedido::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

            if ($ids === []) {
                return ['pedidos' => 0, 'facturas' => 0, 'movimientos' => 0];
            }

            $impedimento = $this->impedimento($ids);

            if ($impedimento !== null) {
                throw new ReglaNegocioException($impedimento);
            }

            $movimientos = $this->devolverExistencias($ids);

            /** @var list<int> $cais */
            $cais = Factura::query()->whereIn('pedido_id', $ids)->distinct()->pluck('cai_id')->map(fn (mixed $id): int => (int) $id)->all();
            $facturas = (int) Factura::query()->whereIn('pedido_id', $ids)->delete();
            $pedidos = (int) Pedido::query()->whereKey($ids)->delete();

            $this->reiniciarNumeros($cais);

            return ['pedidos' => $pedidos, 'facturas' => $facturas, 'movimientos' => $movimientos];
        });
    }

    /**
     * Regresa a cada existencia lo que movieron los pedidos, corrige la
     * existencia de los renglones que vinieron después y borra los suyos.
     *
     * @param list<int> $ids
     */
    private function devolverExistencias(array $ids): int
    {
        $movidos = MovimientoInventario::query()
            ->whereIn('pedido_id', $ids)
            ->groupBy('bodega_id', 'presentacion_id')
            ->orderBy('presentacion_id')
            ->orderBy('bodega_id')
            ->selectRaw('bodega_id, presentacion_id, SUM(cantidad) as cantidad')
            ->get();

        if ($movidos->isEmpty()) {
            return 0;
        }

        foreach ($movidos as $movido) {
            $existencia = Existencia::query()
                ->where('bodega_id', $movido->bodega_id)
                ->where('presentacion_id', $movido->presentacion_id)
                ->lockForUpdate()
                ->first();

            if ($existencia !== null) {
                $existencia->cantidad -= (int) $movido->getAttribute('cantidad');
                $existencia->save();
            }
        }

        // Cada renglón que no es de estos pedidos deja de contar lo que ellos movieron antes que él.
        $marcas = implode(', ', array_fill(0, count($ids), '?'));
        $suyos = "FROM movimientos_inventario AS d WHERE d.pedido_id IN ({$marcas}) AND d.bodega_id = m.bodega_id AND d.presentacion_id = m.presentacion_id AND d.id < m.id";

        DB::update(
            "UPDATE movimientos_inventario AS m SET existencia = m.existencia - (SELECT COALESCE(SUM(d.cantidad), 0) {$suyos}) WHERE (m.pedido_id IS NULL OR m.pedido_id NOT IN ({$marcas})) AND EXISTS (SELECT 1 {$suyos})",
            [...$ids, ...$ids, ...$ids],
        );

        return (int) MovimientoInventario::query()->whereIn('pedido_id', $ids)->delete();
    }

    /**
     * El CAI de prueba sigue en su primer número libre y, sin pedidos, el
     * próximo vuelve a ser el 1 (COP-000001).
     *
     * @param list<int> $cais
     */
    private function reiniciarNumeros(array $cais): void
    {
        foreach (Cai::query()->whereKey($cais)->where('cai', Cai::PRUEBA)->lockForUpdate()->get() as $cai) {
            $ultimo = $cai->facturas()->max('correlativo');
            $cai->siguiente = $ultimo === null ? $cai->rango_desde : (int) $ultimo + 1;
            $cai->save();
        }

        if (DB::getDriverName() === 'pgsql' && ! Pedido::query()->exists()) {
            DB::statement("SELECT setval(pg_get_serial_sequence('pedidos', 'id'), 1, false)");
        }
    }
}
