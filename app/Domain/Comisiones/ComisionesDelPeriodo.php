<?php

declare(strict_types=1);

namespace App\Domain\Comisiones;

use App\Enums\EstadoFactura;
use App\Enums\Rol;
use App\Models\ComisionFactura;
use App\Models\ComisionGrupo;
use App\Models\ComisionLiquidacion;
use App\Models\ComisionMeta;
use App\Models\ComisionPeriodo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Las comisiones de un periodo: por vendedor y por grupo, la venta neta
 * facturada (sin ISV, ya con descuentos), el escalón que alcanzó y lo que
 * gana. De todos los vendedores, los de una bodega o uno solo.
 *
 * Es venta lo FACTURADO: las facturas vigentes con fecha del periodo. Un
 * pedido corregido después de impreso anula su factura y saca otra: cuenta
 * la nueva, en su fecha.
 *
 * Abierto, se calcula al momento ("así va"). Cerrado (ComisionPeriodo), se
 * lee lo que se pagó y ya no cambia. Al cerrar el siguiente entran también:
 *  - las facturas con fecha de un periodo ya cerrado que se emitieron
 *    después de cerrarlo (rezagadas), y
 *  - descontadas, las facturas que ya se pagaron y después se anularon.
 * Así nada se paga dos veces ni se queda sin pagar.
 */
final class ComisionesDelPeriodo
{
    private bool $buscoCierre = false;

    private ?ComisionPeriodo $cierre = null;

    /** @var array{ventas: list<array{factura_id: int, vendedor_id: int, grupo_id: int|null, neta: float, rezagada: bool}>, anuladas: list<array{factura_id: int, vendedor_id: int, grupo_id: int|null, activo: bool, neta: float}>}|null */
    private ?array $detalle = null;

    /** @var list<ResumenVendedor>|null */
    private ?array $vendedores = null;

    public function __construct(
        public readonly PeriodoComision $periodo,
        public readonly ?int $bodegaId = null,
        public readonly ?int $vendedorId = null,
    ) {}

    /**
     * Si ya se cerró (pagó), el cierre.
     */
    public function cierre(): ?ComisionPeriodo
    {
        if (! $this->buscoCierre) {
            $this->cierre = ComisionPeriodo::de($this->periodo);
            $this->buscoCierre = true;
        }

        return $this->cierre;
    }

    /**
     * Cada vendedor con una fila por grupo activo (aunque no haya vendido:
     * así ve sus metas). Cerrado: lo que se le pagó.
     *
     * @return list<ResumenVendedor>
     */
    public function vendedores(): array
    {
        if ($this->vendedores !== null) {
            return $this->vendedores;
        }

        $cierre = $this->cierre();

        return $this->vendedores = $cierre === null ? $this->calcular() : $this->leer($cierre);
    }

    /**
     * @return array{venta: float, base: float, comision: float, facturas: int, vendedores: int}
     */
    public function totales(): array
    {
        $vendedores = $this->vendedores();

        return [
            'venta'      => round(array_sum(array_map(fn (ResumenVendedor $v): float => $v->ventaTotal, $vendedores)), 2),
            'base'       => round(array_sum(array_map(fn (ResumenVendedor $v): float => $v->ventaEnGrupos(), $vendedores)), 2),
            'comision'   => round(array_sum(array_map(fn (ResumenVendedor $v): float => $v->comision(), $vendedores)), 2),
            'facturas'   => array_sum(array_map(fn (ResumenVendedor $v): int => $v->facturas, $vendedores)),
            'vendedores' => count(array_filter($vendedores, fn (ResumenVendedor $v): bool => $v->ventaTotal != 0.0 || $v->comision() > 0)),
        ];
    }

    /**
     * Facturas de periodos ya cerrados que entran en este (se emitieron después del cierre).
     */
    public function rezagadas(): int
    {
        $cierre = $this->cierre();

        if ($cierre !== null) {
            return (int) $this->conAlcance(
                DB::table('comision_facturas as cf')
                    ->join('facturas as fa', 'fa.id', '=', 'cf.factura_id')
                    ->where('cf.comision_periodo_id', $cierre->id)
                    ->where('cf.tipo', ComisionFactura::VENTA)
                    ->where('fa.fecha_emision', '<', $this->periodo->inicio()),
                'cf.vendedor_id',
            )->distinct()->count('cf.factura_id');
        }

        return count(array_unique(array_column(array_filter($this->detalle()['ventas'], fn (array $f): bool => $f['rezagada']), 'factura_id')));
    }

    /**
     * Facturas que ya se habían pagado y se anularon: se descuentan en este.
     */
    public function anuladas(): int
    {
        $cierre = $this->cierre();

        if ($cierre !== null) {
            return (int) $this->conAlcance(
                DB::table('comision_facturas as cf')
                    ->where('cf.comision_periodo_id', $cierre->id)
                    ->where('cf.tipo', ComisionFactura::ANULADA),
                'cf.vendedor_id',
            )->distinct()->count('cf.factura_id');
        }

        return count(array_unique(array_column($this->detalle()['anuladas'], 'factura_id')));
    }

    /**
     * Lo que entraría al cerrar, factura por factura y por grupo (sin
     * grupo = no paga comisión). Solo para un periodo abierto.
     *
     * @return array{ventas: list<array{factura_id: int, vendedor_id: int, grupo_id: int|null, neta: float, rezagada: bool}>, anuladas: list<array{factura_id: int, vendedor_id: int, grupo_id: int|null, activo: bool, neta: float}>}
     */
    public function detalle(): array
    {
        if ($this->detalle !== null) {
            return $this->detalle;
        }

        [$alcance, $valores] = $this->alcanceSql('p.vendedor_id');

        /** @var list<stdClass> $ventas */
        $ventas = DB::select(<<<SQL
            SELECT fa.id AS factura_id, p.vendedor_id, CASE WHEN g.activo THEN g.id END AS grupo_id,
                fa.fecha_emision < ? AS rezagada, ROUND(SUM(i.subtotal - i.descuento), 2) AS neta
            FROM facturas fa
            JOIN pedidos p ON p.id = fa.pedido_id
            JOIN pedido_items i ON i.pedido_id = fa.pedido_id
            JOIN productos pr ON pr.id = i.producto_id
            LEFT JOIN categorias c ON c.id = pr.categoria_id
            LEFT JOIN comision_grupos g ON g.id = c.comision_grupo_id
            WHERE fa.estado = ?
                AND fa.fecha_emision < ?
                AND (
                    fa.fecha_emision >= ?
                    OR EXISTS (SELECT 1 FROM comision_periodos cp WHERE cp.desde < ? AND fa.fecha_emision >= cp.desde AND fa.fecha_emision < cp.hasta + 1)
                )
                AND NOT EXISTS (SELECT 1 FROM comision_facturas cf WHERE cf.factura_id = fa.id AND cf.tipo = ?){$alcance}
            GROUP BY 1, 2, 3, 4
            ORDER BY 1
            SQL, [
            $this->periodo->inicio(),
            EstadoFactura::Vigente->value,
            $this->periodo->finExclusivo(),
            $this->periodo->inicio(),
            $this->periodo->desde->toDateString(),
            ComisionFactura::VENTA,
            ...$valores,
        ]);

        [$alcance, $valores] = $this->alcanceSql('cf.vendedor_id');

        /** @var list<stdClass> $anuladas */
        $anuladas = DB::select(<<<SQL
            SELECT cf.factura_id, cf.vendedor_id, cf.comision_grupo_id AS grupo_id, COALESCE(g.activo, FALSE) AS activo, cf.neta
            FROM comision_facturas cf
            JOIN comision_periodos cp ON cp.id = cf.comision_periodo_id
            JOIN facturas fa ON fa.id = cf.factura_id
            LEFT JOIN comision_grupos g ON g.id = cf.comision_grupo_id
            WHERE cf.tipo = ? AND fa.estado = ? AND fa.anulada_at < ? AND cp.desde < ?
                AND NOT EXISTS (SELECT 1 FROM comision_facturas x WHERE x.factura_id = cf.factura_id AND x.tipo = ?){$alcance}
            ORDER BY cf.factura_id, cf.id
            SQL, [
            ComisionFactura::VENTA,
            EstadoFactura::Anulada->value,
            $this->periodo->finExclusivo(),
            $this->periodo->desde->toDateString(),
            ComisionFactura::ANULADA,
            ...$valores,
        ]);

        return $this->detalle = [
            'ventas' => array_map(fn (stdClass $f): array => [
                'factura_id'  => self::entero($f->factura_id),
                'vendedor_id' => self::entero($f->vendedor_id),
                'grupo_id'    => $f->grupo_id === null ? null : self::entero($f->grupo_id),
                'neta'        => self::numero($f->neta),
                'rezagada'    => (bool) $f->rezagada,
            ], $ventas),
            'anuladas' => array_map(fn (stdClass $f): array => [
                'factura_id'  => self::entero($f->factura_id),
                'vendedor_id' => self::entero($f->vendedor_id),
                'grupo_id'    => $f->grupo_id === null ? null : self::entero($f->grupo_id),
                'activo'      => (bool) $f->activo,
                'neta'        => self::numero($f->neta),
            ], $anuladas),
        ];
    }

    /**
     * Periodo abierto: con las facturas y las escalas de hoy.
     *
     * @return list<ResumenVendedor>
     */
    private function calcular(): array
    {
        $detalle = $this->detalle();

        /** @var array<int, array<int, array{venta?: float, anulado?: float, facturas?: array<int, true>}>> $porGrupo [vendedor][grupo] */
        $porGrupo = [];
        /** @var array<int, float> $total */
        $total = [];
        /** @var array<int, array<int, true>> $facturas */
        $facturas = [];

        foreach ($detalle['ventas'] as $f) {
            $total[$f['vendedor_id']] = ($total[$f['vendedor_id']] ?? 0.0) + $f['neta'];
            $facturas[$f['vendedor_id']][$f['factura_id']] = true;

            if ($f['grupo_id'] !== null) {
                $porGrupo[$f['vendedor_id']][$f['grupo_id']]['venta'] = ($porGrupo[$f['vendedor_id']][$f['grupo_id']]['venta'] ?? 0.0) + $f['neta'];
                $porGrupo[$f['vendedor_id']][$f['grupo_id']]['facturas'][$f['factura_id']] = true;
            }
        }

        foreach ($detalle['anuladas'] as $f) {
            $total[$f['vendedor_id']] = ($total[$f['vendedor_id']] ?? 0.0) - $f['neta'];

            if ($f['grupo_id'] !== null && $f['activo']) {
                $porGrupo[$f['vendedor_id']][$f['grupo_id']]['anulado'] = ($porGrupo[$f['vendedor_id']][$f['grupo_id']]['anulado'] ?? 0.0) + $f['neta'];
            }
        }

        $grupos = ComisionGrupo::query()->activos()->orderBy('nombre')->get();
        $usuarios = $this->usuarios(array_keys($total));
        /** @var array<int, array<int, ComisionMeta>> $metas [vendedor][grupo] */
        $metas = [];

        foreach (ComisionMeta::query()->whereIn('vendedor_id', array_keys($usuarios))->get() as $meta) {
            $metas[$meta->vendedor_id][$meta->comision_grupo_id] = $meta;
        }

        $resumenes = [];

        foreach ($usuarios as $id => $usuario) {
            $filas = [];

            foreach ($grupos as $grupo) {
                $meta = $metas[$id][$grupo->id] ?? null;
                $propia = $meta !== null && ! $meta->escala()->vacia();
                $datos = $porGrupo[$id][$grupo->id] ?? [];

                $filas[] = FilaComision::calcular(
                    grupoId: $grupo->id,
                    grupo: $grupo->nombre,
                    venta: $datos['venta'] ?? 0.0,
                    anulado: $datos['anulado'] ?? 0.0,
                    facturas: count($datos['facturas'] ?? []),
                    escala: $meta !== null && $propia ? $meta->escala() : $grupo->escala(),
                    propia: $propia,
                );
            }

            $resumenes[] = new ResumenVendedor(
                id: $id,
                nombre: $usuario->name,
                codigo: $usuario->codigo,
                bodega: $usuario->bodega?->nombre,
                filas: $filas,
                ventaTotal: round($total[$id] ?? 0.0, 2),
                facturas: count($facturas[$id] ?? []),
            );
        }

        return $resumenes;
    }

    /**
     * Periodo cerrado: lo que se pagó.
     *
     * @return list<ResumenVendedor>
     */
    private function leer(ComisionPeriodo $cierre): array
    {
        $liquidaciones = $this->conAlcanceEloquent(ComisionLiquidacion::query()->where('comision_periodo_id', $cierre->id))
            ->orderBy('grupo')
            ->get()
            ->groupBy('vendedor_id');

        /** @var list<stdClass> $totales */
        $totales = $this->conAlcance(
            DB::table('comision_facturas as cf')->where('cf.comision_periodo_id', $cierre->id),
            'cf.vendedor_id',
        )
            ->groupBy('cf.vendedor_id')
            ->selectRaw('cf.vendedor_id, SUM(cf.neta) AS venta, COUNT(DISTINCT CASE WHEN cf.tipo = ? THEN cf.factura_id END) AS facturas', [ComisionFactura::VENTA])
            ->get()
            ->all();

        $venta = [];
        $facturas = [];

        foreach ($totales as $t) {
            $venta[self::entero($t->vendedor_id)] = self::numero($t->venta);
            $facturas[self::entero($t->vendedor_id)] = self::entero($t->facturas);
        }

        $ids = array_values(array_unique([...array_map('intval', $liquidaciones->keys()->all()), ...array_keys($venta)]));
        $usuarios = User::query()->with('bodega')->whereIn('id', $ids)->get()->keyBy('id');
        $resumenes = [];

        foreach (self::ordenar($usuarios->all()) as $id => $usuario) {
            $filas = ($liquidaciones->get($id) ?? collect())->map(fn (ComisionLiquidacion $l): FilaComision => new FilaComision(
                grupoId: $l->comision_grupo_id,
                grupo: $l->grupo,
                venta: $l->venta,
                anulado: $l->anulado,
                facturas: $l->facturas,
                escala: $l->escala(),
                propia: $l->escala_propia,
                base: $l->base,
                porcentaje: $l->porcentaje,
                comision: $l->comision,
                meta: $l->meta,
            ))->values()->all();

            $resumenes[] = new ResumenVendedor(
                id: $id,
                nombre: $usuario->name,
                codigo: $usuario->codigo,
                bodega: $usuario->bodega?->nombre,
                filas: $filas,
                ventaTotal: round($venta[$id] ?? 0.0, 2),
                facturas: $facturas[$id] ?? 0,
            );
        }

        return $resumenes;
    }

    /**
     * Los vendedores activos del alcance (aunque no hayan vendido) y los que
     * vendieron, en orden de código y nombre.
     *
     * @param list<int> $conVenta
     *
     * @return array<int, User>
     */
    private function usuarios(array $conVenta): array
    {
        $query = User::query()->with('bodega');

        if ($this->vendedorId !== null) {
            $query->whereKey($this->vendedorId);
        } else {
            $query->where(function (Builder $q) use ($conVenta): void {
                $q->whereIn('users.id', $conVenta)
                    ->orWhere(fn (Builder $activos): Builder => $activos
                        ->where('users.is_active', true)
                        ->whereIn('users.id', DB::table('model_has_roles as mr')
                            ->join('roles as r', 'r.id', '=', 'mr.role_id')
                            ->where('r.name', Rol::Vendedor->value)
                            ->where('mr.model_type', (new User)->getMorphClass())
                            ->select('mr.model_id')));
            });

            if ($this->bodegaId !== null) {
                $query->where('users.bodega_id', $this->bodegaId);
            }
        }

        return self::ordenar($query->get()->keyBy('id')->all());
    }

    /**
     * @param array<int|string, User> $usuarios
     *
     * @return array<int, User>
     */
    private static function ordenar(array $usuarios): array
    {
        uasort($usuarios, fn (User $a, User $b): int => [blank($a->codigo), (string) $a->codigo, $a->name] <=> [blank($b->codigo), (string) $b->codigo, $b->name]);

        $ordenados = [];

        foreach ($usuarios as $usuario) {
            $ordenados[$usuario->id] = $usuario;
        }

        return $ordenados;
    }

    /**
     * " AND p.vendedor_id IN (…)" según la bodega o el vendedor escogido.
     *
     * @return array{0: string, 1: list<int>}
     */
    private function alcanceSql(string $columna): array
    {
        if ($this->vendedorId !== null) {
            return [" AND {$columna} = ?", [$this->vendedorId]];
        }

        if ($this->bodegaId !== null) {
            return [" AND {$columna} IN (SELECT u.id FROM users u WHERE u.bodega_id = ?)", [$this->bodegaId]];
        }

        return ['', []];
    }

    private function conAlcance(QueryBuilder $query, string $columna): QueryBuilder
    {
        if ($this->vendedorId !== null) {
            $query->where($columna, $this->vendedorId);
        } elseif ($this->bodegaId !== null) {
            $query->whereIn($columna, DB::table('users')->where('bodega_id', $this->bodegaId)->select('id'));
        }

        return $query;
    }

    /**
     * @param Builder<ComisionLiquidacion> $query
     *
     * @return Builder<ComisionLiquidacion>
     */
    private function conAlcanceEloquent(Builder $query): Builder
    {
        $this->conAlcance($query->getQuery(), 'vendedor_id');

        return $query;
    }

    private static function numero(mixed $valor): float
    {
        return is_numeric($valor) ? (float) $valor : 0.0;
    }

    private static function entero(mixed $valor): int
    {
        return is_numeric($valor) ? (int) $valor : 0;
    }
}
