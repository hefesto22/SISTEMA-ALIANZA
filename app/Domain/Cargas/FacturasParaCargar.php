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
 * en los últimos DIAS días antes de la fecha de la carga (las de días atrás
 * ya se entregaron sin carga, cuando todavía no había cargas).
 * Las que ya lleva la carga que se está cambiando también salen.
 */
final class FacturasParaCargar
{
    public const DIAS = 7;

    /**
     * @return Collection<int, Factura>
     */
    public static function de(int $bodegaId, ?string $fecha = null, ?int $carga = null): Collection
    {
        $hasta = Carbon::parse($fecha ?? 'today')->endOfDay();

        return Factura::query()
            ->where('facturas.bodega_id', $bodegaId)
            ->where('facturas.estado', EstadoFactura::Vigente)
            ->where('facturas.fecha_emision', '>=', $hasta->copy()->subDays(self::DIAS)->startOfDay())
            ->where('facturas.fecha_emision', '<=', $hasta)
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
            ->with(['pedido.cliente.municipio', 'pedido.vendedor'])
            ->orderBy('facturas.fecha_emision')
            ->orderBy('facturas.id')
            ->get();
    }

    /**
     * "4106 · PULPERÍA LA ROCA · L 1,234.50".
     */
    public static function etiqueta(Factura $factura): string
    {
        return ltrim(substr($factura->numero, 11), '0').' · '.$factura->cliente_nombre.' · '.lempiras($factura->total);
    }

    /**
     * "Vendió CARLOS · OCOTEPEQUE · 25/11".
     */
    public static function descripcion(Factura $factura): string
    {
        $partes = ['Vendió '.$factura->pedido->vendedor->name];
        $municipio = $factura->pedido->cliente->municipio?->nombre;

        if (filled($municipio)) {
            $partes[] = (string) $municipio;
        }

        $partes[] = $factura->fecha_emision->format('d/m');

        return implode(' · ', $partes);
    }
}
