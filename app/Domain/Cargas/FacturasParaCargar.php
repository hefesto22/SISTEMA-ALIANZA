<?php

declare(strict_types=1);

namespace App\Domain\Cargas;

use App\Enums\EntregaFactura;
use App\Enums\EstadoFactura;
use App\Enums\EstadoPedido;
use App\Models\Factura;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * Las facturas que se pueden subir a un camión: vigentes, de la bodega, de
 * pedidos impresos, que no van en otra carga ni se entregaron ya, emitidas
 * desde un día hasta la fecha de la carga. Si no se dice desde cuándo, los
 * últimos DIAS días (las de antes casi siempre ya se entregaron); en la
 * pantalla se puede ir más atrás con "Impresas desde".
 *
 * Tampoco salen las de un día que ya salió (ver sinCargar()): si la ruta y
 * vendedor de la factura ya llevó en otra carga facturas de un día después,
 * o de ese mismo día en un camión que salió un día antes que esta carga.
 * Dos camiones que salen el mismo día sí se reparten una ruta.
 *
 * Las que ya lleva la carga que se está cambiando y las que la liquidación
 * mandó a otra carga siempre salen.
 */
final class FacturasParaCargar
{
    public const DIAS = 7;

    /** Hasta cuántos días antes de "desde" se buscan las que quedaron sin cargar. */
    public const ATRAS = 31;

    /**
     * @return Collection<int, Factura>
     */
    public static function de(int $bodegaId, ?string $fecha = null, ?int $carga = null, ?string $desde = null): Collection
    {
        $hasta = Carbon::parse($fecha ?? 'today')->endOfDay();
        $inicio = $desde !== null ? Carbon::parse($desde)->startOfDay() : self::desdeDe($hasta->toDateString());

        return self::sinCargar($bodegaId, $carga, $hasta->toDateString())
            ->where('facturas.fecha_emision', '>=', $inicio)
            ->where('facturas.fecha_emision', '<=', $hasta)
            ->with(['pedido.cliente.municipio', 'pedido.vendedor', 'pedido.ruta.bodega'])
            ->orderBy('facturas.fecha_emision')
            ->orderBy('facturas.id')
            ->get();
    }

    /**
     * Las que quedaron sin cargar antes de "desde" (hasta ATRAS días antes):
     * cuántas, el día de la más vieja y el de la más reciente, para ofrecer verlas.
     *
     * @return array{cuantas: int, desde: string|null, ultimo: string|null}
     */
    public static function anteriores(int $bodegaId, string $desde, ?int $carga = null, ?string $fecha = null): array
    {
        $inicio = Carbon::parse($desde)->startOfDay();
        $salida = Carbon::parse($fecha ?? 'today')->toDateString();
        $consulta = fn (): Builder => self::sinCargar($bodegaId, $carga, $salida)
            ->where('facturas.fecha_emision', '<', $inicio)
            ->where('facturas.fecha_emision', '>=', $inicio->copy()->subDays(self::ATRAS));

        $cuantas = $consulta()->count();
        $primera = $cuantas > 0 ? $consulta()->min('facturas.fecha_emision') : null;
        $ultima = $cuantas > 0 ? $consulta()->max('facturas.fecha_emision') : null;

        return [
            'cuantas' => $cuantas,
            'desde'   => is_string($primera) ? Carbon::parse($primera)->toDateString() : null,
            'ultimo'  => is_string($ultima) ? Carbon::parse($ultima)->toDateString() : null,
        ];
    }

    /**
     * Si hay alguna sin cargar desde ese día hasta hoy.
     */
    public static function hayDesde(int $bodegaId, string $desde): bool
    {
        return self::sinCargar($bodegaId, null, today()->toDateString())
            ->where('facturas.fecha_emision', '>=', Carbon::parse($desde)->startOfDay())
            ->exists();
    }

    /**
     * Vigentes, de la bodega, de pedidos impresos, que no van en otra carga
     * ni se entregaron ya (las de la carga que se está cambiando sí salen) y
     * que no son de un día que ya salió para su ruta y vendedor: otra carga
     * (no la que se cambia) llevó facturas de esa ruta y vendedor de un día
     * después, o del mismo día y salió antes de $salida (la fecha de esta
     * carga). Las que la liquidación mandó a otra carga sí salen.
     *
     * @return Builder<Factura>
     */
    private static function sinCargar(int $bodegaId, ?int $carga, string $salida): Builder
    {
        return Factura::query()
            ->where('facturas.bodega_id', $bodegaId)
            ->where('facturas.estado', EstadoFactura::Vigente)
            ->whereHas('pedido', fn (Builder $q): Builder => $q->where('estado', EstadoPedido::Impreso))
            ->whereNotExists(function (QueryBuilder $q) use ($carga): void {
                $q->selectRaw('1')
                    ->from('carga_factura')
                    ->whereColumn('carga_factura.factura_id', 'facturas.id')
                    ->whereIn('carga_factura.estado', [EntregaFactura::EnRuta->value, EntregaFactura::Entregada->value]);

                if ($carga !== null) {
                    $q->where('carga_factura.carga_id', '!=', $carga);
                }
            })
            ->where(function (Builder $q) use ($carga, $salida): void {
                $q->whereExists(function (QueryBuilder $q) use ($carga): void {
                    // La lleva la carga que se cambia, o la liquidación la mandó a otra carga.
                    $q->selectRaw('1')
                        ->from('carga_factura')
                        ->whereColumn('carga_factura.factura_id', 'facturas.id')
                        ->where(function (QueryBuilder $q) use ($carga): void {
                            $q->where('carga_factura.estado', EntregaFactura::OtraCarga->value)
                                ->orWhere('carga_factura.carga_id', $carga ?? 0);
                        });
                })->orWhereNotExists(function (QueryBuilder $q) use ($carga, $salida): void {
                    // Su día ya salió: otra carga llevó de su ruta y vendedor
                    // facturas de un día después, o del mismo día en un camión
                    // que salió antes que esta carga.
                    $q->selectRaw('1')
                        ->from('carga_factura as salio')
                        ->join('cargas as camion', 'camion.id', '=', 'salio.carga_id')
                        ->join('facturas as otra', 'otra.id', '=', 'salio.factura_id')
                        ->join('pedidos as de_otra', 'de_otra.id', '=', 'otra.pedido_id')
                        ->join('pedidos as de_esta', 'de_esta.id', '=', 'facturas.pedido_id')
                        ->whereColumn('camion.bodega_id', 'facturas.bodega_id')
                        ->where('camion.id', '!=', $carga ?? 0)
                        ->whereColumn('de_otra.vendedor_id', 'de_esta.vendedor_id')
                        ->whereRaw('de_otra.ruta_id IS NOT DISTINCT FROM de_esta.ruta_id')
                        ->whereRaw('otra.fecha_emision >= CAST(facturas.fecha_emision AS DATE) + CASE WHEN camion.fecha < CAST(? AS DATE) THEN 0 ELSE 1 END', [$salida]);
                });
            });
    }

    /**
     * Desde cuándo se buscan si no se dice: DIAS días antes de la fecha de la carga.
     */
    public static function desdeDe(?string $fecha = null): Carbon
    {
        return Carbon::parse($fecha ?? 'today')->startOfDay()->subDays(self::DIAS);
    }
}
