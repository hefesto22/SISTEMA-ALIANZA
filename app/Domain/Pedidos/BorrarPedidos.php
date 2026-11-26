<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Domain\Cargas\ArmarCarga;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Models\Cai;
use App\Models\Carga;
use App\Models\CargaFactura;
use App\Models\ComisionFactura;
use App\Models\Devolucion;
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
 *  - Sus devoluciones se borran con ellos (lo que movieron en la bodega
 *    también se deshace); si alguna emitió nota de crédito de un CAI del
 *    SAR, no se borran (las del CAI de prueba sí).
 *  - Sus facturas se borran solo si son del CAI de prueba y ninguna está en
 *    un periodo de comisiones cerrado. Salen de las cargas del camión (la
 *    carga que se queda sin facturas se borra). Una factura de un CAI del
 *    SAR no se borra nunca: se anula.
 *  - Los CAI de prueba (facturas y notas de crédito) vuelven a su primer
 *    número libre y, si ya no queda ningún pedido, la numeración de los
 *    pedidos empieza otra vez en 1.
 *
 * Los clientes, las compras, los costos y los precios no se tocan.
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

        /** @var list<string> $notas */
        $notas = Devolucion::query()->whereIn('pedido_id', $ids)->whereNotNull('nota_numero')->where('cai', '!=', Cai::PRUEBA_NOTAS)->orderBy('nota_numero')->limit(5)->pluck('nota_numero')->all();

        if ($notas !== []) {
            return 'Tienen devoluciones con nota de crédito ('.implode(', ', $notas).'): esas no se borran.';
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
            $cais = array_values(array_unique(array_map(intval(...), [
                ...Factura::query()->whereIn('pedido_id', $ids)->distinct()->pluck('cai_id')->all(),
                ...Devolucion::query()->whereIn('pedido_id', $ids)->whereNotNull('cai_id')->distinct()->pluck('cai_id')->all(),
            ])));
            $this->quitarDeCargas($ids);
            $facturas = (int) Factura::query()->whereIn('pedido_id', $ids)->delete();
            $pedidos = (int) Pedido::query()->whereKey($ids)->delete();

            $this->reiniciarNumeros($cais);

            return ['pedidos' => $pedidos, 'facturas' => $facturas, 'movimientos' => $movimientos];
        });
    }

    /**
     * Sus facturas salen de las cargas del camión; la carga que se queda sin
     * facturas se borra y a las demás se les vuelve a sacar el total.
     *
     * @param list<int> $ids
     */
    private function quitarDeCargas(array $ids): void
    {
        $facturas = Factura::query()->whereIn('pedido_id', $ids)->select('id');
        $cargas = CargaFactura::query()->whereIn('factura_id', $facturas)->distinct()->pluck('carga_id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($cargas === []) {
            return;
        }

        CargaFactura::query()->whereIn('factura_id', $facturas)->delete();
        Carga::query()->whereKey($cargas)->whereDoesntHave('renglones')->delete();

        foreach (Carga::query()->whereKey($cargas)->get() as $carga) {
            ArmarCarga::conTotal($carga);
        }
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
     * Los CAI de prueba siguen en su primer número libre y, sin pedidos, el
     * próximo vuelve a ser el 1 (COP-000001).
     *
     * @param list<int> $cais
     */
    private function reiniciarNumeros(array $cais): void
    {
        foreach (Cai::query()->whereKey($cais)->whereIn('cai', Cai::DE_PRUEBA)->lockForUpdate()->get() as $cai) {
            $ultimo = $cai->tipo_documento === Cai::NOTA_CREDITO
                ? Devolucion::query()->where('cai_id', $cai->id)->max('correlativo')
                : $cai->facturas()->max('correlativo');
            $cai->siguiente = $ultimo === null ? $cai->rango_desde : (int) $ultimo + 1;
            $cai->save();
        }

        if (DB::getDriverName() === 'pgsql' && ! Pedido::query()->exists()) {
            DB::statement("SELECT setval(pg_get_serial_sequence('pedidos', 'id'), 1, false)");
        }
    }
}
