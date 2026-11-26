<?php

declare(strict_types=1);

namespace App\Domain\Facturacion;

use App\Domain\Cargas\FacturasParaCargar;
use App\Enums\EstadoFactura;
use App\Enums\EstadoPedido;
use App\Models\Bodega;
use App\Models\Factura;
use App\Models\Pedido;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Las pestañas Por imprimir e Impresos de Pedidos, por día: un cuadro por
 * día y bodega (cada bodega imprime en su impresora), del más reciente al
 * más viejo, solo con lo que el usuario ve.
 *
 *  - porImprimir(): por la fecha del pedido.
 *  - impresas(): por la fecha de emisión de la factura (la que va impresa);
 *    cuenta también las que se anularon después, porque sus números ya se
 *    usaron ese día. La que sustituye a una anulada cuenta el día en que se
 *    imprimió (lleva esa fecha y el número que seguía), aunque la anulada
 *    sea de un día antes. Dice cuántas quedan sin cargar en un camión y,
 *    para las pestañas Impreso y Cargado, solo trae los días que tienen
 *    facturas sin cargar ($cargadas = false) o ya cargadas (true).
 *  - idsPorImprimir(): los pedidos de un cuadro, para imprimirlos de un solo.
 */
final class DiasDeFacturacion
{
    /**
     * @return array{dias: list<DiaDeFacturacion>, hayMas: bool}
     */
    public static function porImprimir(User $usuario, int $limite): array
    {
        $filas = Pedido::query()
            ->visiblePara($usuario)
            ->where('pedidos.estado', EstadoPedido::PorImprimir)
            ->toBase()
            ->selectRaw('DATE(pedidos.fecha) AS dia, pedidos.bodega_id, COUNT(*) AS cuantas, COALESCE(SUM(pedidos.total), 0) AS total, COUNT(DISTINCT pedidos.vendedor_id) AS vendedores, SUM(CASE WHEN pedidos.impresiones > 0 THEN 1 ELSE 0 END) AS reimpresiones')
            ->groupByRaw('DATE(pedidos.fecha), pedidos.bodega_id')
            ->orderByDesc('dia')
            ->orderBy('pedidos.bodega_id')
            ->limit($limite + 1)
            ->get();

        $bodegas = self::bodegas($filas);
        $dias = [];

        foreach ($filas->take($limite) as $fila) {
            $dias[] = new DiaDeFacturacion(
                dia: CarbonImmutable::parse((string) $fila->dia),
                bodegaId: (int) $fila->bodega_id,
                bodega: $bodegas[(int) $fila->bodega_id] ?? '',
                cuantas: (int) $fila->cuantas,
                total: round((float) $fila->total, 2),
                vendedores: (int) $fila->vendedores,
                reimpresiones: (int) $fila->reimpresiones,
            );
        }

        return ['dias' => $dias, 'hayMas' => $filas->count() > $limite];
    }

    /**
     * @return array{dias: list<DiaDeFacturacion>, hayMas: bool}
     */
    public static function impresas(User $usuario, int $limite, ?bool $cargadas = null): array
    {
        $vigente = EstadoFactura::Vigente->value;
        // Las que quedan para cargar (una sola vez para todas: no depende de cada fila).
        $paraCargar = FacturasParaCargar::paraCargar()->select('facturas.id');

        $dias = Factura::query()
            ->visiblesPara($usuario)
            ->toBase()
            ->selectRaw(
                'DATE(facturas.fecha_emision) AS dia, facturas.bodega_id, '
                .'SUM(CASE WHEN facturas.estado = ? THEN 1 ELSE 0 END) AS cuantas, '
                .'COALESCE(SUM(CASE WHEN facturas.estado = ? THEN facturas.total ELSE 0 END), 0) AS total, '
                .'SUM(CASE WHEN facturas.estado = ? THEN 0 ELSE 1 END) AS anuladas, '
                .'COALESCE(SUM(GREATEST(facturas.impresiones - 1, 0)), 0) AS copias, '
                .'MIN(facturas.numero) AS desde, MAX(facturas.numero) AS hasta, '
                .'SUM(CASE WHEN facturas.estado = ? AND facturas.reemplaza_a_id IS NOT NULL THEN 1 ELSE 0 END) AS sustituciones, '
                .'SUM(CASE WHEN facturas.id IN ('.$paraCargar->toSql().') THEN 1 ELSE 0 END) AS sin_cargar',
                [$vigente, $vigente, $vigente, $vigente, ...$paraCargar->getBindings()],
            )
            ->groupByRaw('DATE(facturas.fecha_emision), facturas.bodega_id');

        $filas = DB::query()
            ->fromSub($dias, 'd')
            ->when($cargadas === false, fn (QueryBuilder $q): QueryBuilder => $q->where('d.sin_cargar', '>', 0))
            ->when($cargadas === true, fn (QueryBuilder $q): QueryBuilder => $q->whereColumn('d.cuantas', '>', 'd.sin_cargar'))
            ->orderByDesc('d.dia')
            ->orderBy('d.bodega_id')
            ->limit($limite + 1)
            ->get();

        $bodegas = self::bodegas($filas);
        $dias = [];

        foreach ($filas->take($limite) as $fila) {
            $dias[] = new DiaDeFacturacion(
                dia: CarbonImmutable::parse((string) $fila->dia),
                bodegaId: (int) $fila->bodega_id,
                bodega: $bodegas[(int) $fila->bodega_id] ?? '',
                cuantas: (int) $fila->cuantas,
                total: round((float) $fila->total, 2),
                anuladas: (int) $fila->anuladas,
                copias: (int) $fila->copias,
                desde: (string) $fila->desde,
                hasta: (string) $fila->hasta,
                sustituciones: (int) $fila->sustituciones,
                sinCargar: (int) $fila->sin_cargar,
            );
        }

        return ['dias' => $dias, 'hayMas' => $filas->count() > $limite];
    }

    /**
     * Los pedidos Por imprimir de un día y una bodega, en el orden en que se
     * emiten (el número va de menor a mayor por id).
     *
     * @return list<int>
     */
    public static function idsPorImprimir(User $usuario, string $dia, int $bodegaId): array
    {
        return array_values(array_map(
            intval(...),
            Pedido::query()
                ->visiblePara($usuario)
                ->where('pedidos.estado', EstadoPedido::PorImprimir)
                ->whereDate('pedidos.fecha', $dia)
                ->where('pedidos.bodega_id', $bodegaId)
                ->orderBy('pedidos.id')
                ->pluck('pedidos.id')
                ->all(),
        ));
    }

    /**
     * @param Collection<int, stdClass> $filas
     *
     * @return array<int, string>
     */
    private static function bodegas(Collection $filas): array
    {
        /** @var array<int, string> $nombres */
        $nombres = Bodega::query()
            ->whereKey($filas->pluck('bodega_id')->unique()->all())
            ->pluck('nombre', 'id')
            ->all();

        return $nombres;
    }
}
