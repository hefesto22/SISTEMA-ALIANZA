<?php

declare(strict_types=1);

namespace App\Domain\Reportes;

use App\Enums\EstadoFactura;
use App\Models\Bodega;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Indicadores de venta de un periodo, sobre lo FACTURADO (facturas vigentes
 * en su fecha de emisión), sin ISV y ya con descuentos: lo mismo que cuenta
 * para las comisiones.
 *
 *  - Resumen: venta, facturas, clientes, ticket promedio y cómo va contra el
 *    periodo anterior del mismo largo.
 *  - Vendedores: venta, facturas, clientes atendidos, ticket, variación y
 *    cobertura (clientes atendidos ÷ clientes activos de sus rutas).
 *  - Grupos de comisión, productos más vendidos, zonas (municipio y
 *    departamento del cliente) y venta por día.
 *
 * De todas las bodegas, de una (la del encargado) o de un vendedor.
 */
final class KpisVentas
{
    /** @var array{venta: float, facturas: int, clientes: int, vendedores: int, ticket: float|null, anterior: float, variacion: float|null}|null */
    private ?array $resumen = null;

    public function __construct(
        public readonly Periodo $periodo,
        public readonly ?Bodega $bodega = null,
        public readonly ?int $vendedorId = null,
    ) {}

    /**
     * El periodo anterior del mismo largo (para comparar).
     */
    public function anterior(): self
    {
        $dias = (int) $this->periodo->desde->diffInDays($this->periodo->hasta) + 1;

        return new self(
            new Periodo($this->periodo->desde->subDays($dias), $this->periodo->desde->subDay()),
            $this->bodega,
            $this->vendedorId,
        );
    }

    /**
     * @return array{venta: float, facturas: int, clientes: int, vendedores: int, ticket: float|null, anterior: float, variacion: float|null}
     */
    public function resumen(): array
    {
        if ($this->resumen !== null) {
            return $this->resumen;
        }

        $f = $this->consultar('SELECT COALESCE(SUM(l.neta), 0) AS venta, COUNT(DISTINCT l.factura_id) AS facturas, COUNT(DISTINCT l.cliente_id) AS clientes, COUNT(DISTINCT l.vendedor_id) AS vendedores FROM l')[0] ?? new stdClass;
        $venta = round(self::numero($f->venta ?? 0), 2);
        $facturas = self::entero($f->facturas ?? 0);
        $anterior = $this->anterior()->ventaTotal();

        return $this->resumen = [
            'venta'      => $venta,
            'facturas'   => $facturas,
            'clientes'   => self::entero($f->clientes ?? 0),
            'vendedores' => self::entero($f->vendedores ?? 0),
            'ticket'     => $facturas > 0 ? round($venta / $facturas, 2) : null,
            'anterior'   => $anterior,
            'variacion'  => self::variacion($venta, $anterior),
        ];
    }

    public function ventaTotal(): float
    {
        $f = $this->consultar('SELECT COALESCE(SUM(l.neta), 0) AS venta FROM l')[0] ?? null;

        return round(self::numero($f->venta ?? 0), 2);
    }

    /**
     * @return list<array{id: int, nombre: string, codigo: string|null, venta: float, facturas: int, clientes: int, ticket: float|null, anterior: float, variacion: float|null, cartera: int, cobertura: float|null, participacion: float|null}>
     */
    public function vendedores(): array
    {
        $filas = $this->consultar('SELECT u.id, u.name AS nombre, u.codigo, SUM(l.neta) AS venta, COUNT(DISTINCT l.factura_id) AS facturas, COUNT(DISTINCT l.cliente_id) AS clientes'
            .' FROM l JOIN users u ON u.id = l.vendedor_id GROUP BY u.id, u.name, u.codigo ORDER BY venta DESC, u.name');

        $antes = [];

        foreach ($this->anterior()->consultar('SELECT l.vendedor_id, SUM(l.neta) AS venta FROM l GROUP BY l.vendedor_id') as $f) {
            $antes[self::entero($f->vendedor_id)] = round(self::numero($f->venta), 2);
        }

        $cartera = $this->cartera(array_map(fn (stdClass $f): int => self::entero($f->id), $filas));
        $total = $this->resumen()['venta'];

        return array_map(function (stdClass $f) use ($antes, $cartera, $total): array {
            $id = self::entero($f->id);
            $venta = round(self::numero($f->venta), 2);
            $facturas = self::entero($f->facturas);
            $clientes = self::entero($f->clientes);
            $enCartera = $cartera[$id] ?? 0;

            return [
                'id'            => $id,
                'nombre'        => self::texto($f->nombre),
                'codigo'        => $f->codigo === null ? null : self::texto($f->codigo),
                'venta'         => $venta,
                'facturas'      => $facturas,
                'clientes'      => $clientes,
                'ticket'        => $facturas > 0 ? round($venta / $facturas, 2) : null,
                'anterior'      => $antes[$id] ?? 0.0,
                'variacion'     => self::variacion($venta, $antes[$id] ?? 0.0),
                'cartera'       => $enCartera,
                'cobertura'     => $enCartera > 0 ? min(1.0, $clientes / $enCartera) : null,
                'participacion' => self::parte($venta, $total),
            ];
        }, $filas);
    }

    /**
     * Por grupo de comisión (lo que no está en un grupo: "Sin grupo").
     *
     * @return list<array{nombre: string, venta: float, clientes: int, participacion: float|null}>
     */
    public function grupos(): array
    {
        $filas = $this->consultar('SELECT COALESCE(g.nombre, \'SIN GRUPO\') AS nombre, g.id IS NULL AS sin, SUM(l.neta) AS venta, COUNT(DISTINCT l.cliente_id) AS clientes'
            .' FROM l JOIN productos p ON p.id = l.producto_id LEFT JOIN categorias c ON c.id = p.categoria_id LEFT JOIN comision_grupos g ON g.id = c.comision_grupo_id'
            .' GROUP BY g.id, g.nombre ORDER BY sin, venta DESC, nombre');

        $total = $this->resumen()['venta'];

        return array_map(fn (stdClass $f): array => [
            'nombre'        => self::texto($f->nombre),
            'venta'         => round(self::numero($f->venta), 2),
            'clientes'      => self::entero($f->clientes),
            'participacion' => self::parte(self::numero($f->venta), $total),
        ], $filas);
    }

    /**
     * Los productos que más se venden (por venta).
     *
     * @return list<array{codigo: string, nombre: string, proveedor: string, venta: float, unidades: int, clientes: int, participacion: float|null}>
     */
    public function productos(int $limite = 15): array
    {
        $filas = $this->consultar('SELECT p.codigo, p.descripcion AS nombre, COALESCE(NULLIF(pv.nombre_corto, \'\'), pv.nombre) AS proveedor,'
            .' SUM(l.neta) AS venta, SUM(l.unidades) AS unidades, COUNT(DISTINCT l.cliente_id) AS clientes'
            .' FROM l JOIN productos p ON p.id = l.producto_id JOIN proveedores pv ON pv.id = p.proveedor_id'
            .' GROUP BY p.id, p.codigo, p.descripcion, pv.nombre_corto, pv.nombre ORDER BY venta DESC, p.descripcion LIMIT '.max(1, $limite));

        $total = $this->resumen()['venta'];

        return array_map(fn (stdClass $f): array => [
            'codigo'        => self::texto($f->codigo),
            'nombre'        => self::texto($f->nombre),
            'proveedor'     => self::texto($f->proveedor),
            'venta'         => round(self::numero($f->venta), 2),
            'unidades'      => self::entero($f->unidades),
            'clientes'      => self::entero($f->clientes),
            'participacion' => self::parte(self::numero($f->venta), $total),
        ], $filas);
    }

    /**
     * Por municipio del cliente (con su departamento).
     *
     * @return list<array{municipio: string, departamento: string, venta: float, facturas: int, clientes: int, participacion: float|null}>
     */
    public function zonas(int $limite = 20): array
    {
        $filas = $this->consultar('SELECT COALESCE(m.nombre, \'SIN MUNICIPIO\') AS municipio, COALESCE(d.nombre, \'\') AS departamento,'
            .' SUM(l.neta) AS venta, COUNT(DISTINCT l.factura_id) AS facturas, COUNT(DISTINCT l.cliente_id) AS clientes'
            .' FROM l JOIN clientes c ON c.id = l.cliente_id LEFT JOIN municipios m ON m.id = c.municipio_id LEFT JOIN departamentos d ON d.id = m.departamento_id'
            .' GROUP BY m.id, m.nombre, d.nombre ORDER BY venta DESC, municipio LIMIT '.max(1, $limite));

        $total = $this->resumen()['venta'];

        return array_map(fn (stdClass $f): array => [
            'municipio'     => self::texto($f->municipio),
            'departamento'  => self::texto($f->departamento),
            'venta'         => round(self::numero($f->venta), 2),
            'facturas'      => self::entero($f->facturas),
            'clientes'      => self::entero($f->clientes),
            'participacion' => self::parte(self::numero($f->venta), $total),
        ], $filas);
    }

    /**
     * Venta de cada día del periodo (los días sin venta van en cero).
     *
     * @return list<array{dia: CarbonImmutable, venta: float}>
     */
    public function porDia(): array
    {
        $venta = [];

        foreach ($this->consultar('SELECT CAST(l.fecha_emision AS date) AS dia, SUM(l.neta) AS venta FROM l GROUP BY 1') as $f) {
            $venta[self::texto($f->dia)] = round(self::numero($f->venta), 2);
        }

        $dias = [];

        for ($dia = $this->periodo->desde; $dia->lte($this->periodo->hasta); $dia = $dia->addDay()) {
            $dias[] = ['dia' => $dia, 'venta' => $venta[$dia->format('Y-m-d')] ?? 0.0];
        }

        return $dias;
    }

    /**
     * Clientes activos en los municipios de las rutas activas de cada vendedor.
     *
     * @param list<int> $vendedores
     *
     * @return array<int, int>
     */
    private function cartera(array $vendedores): array
    {
        if ($vendedores === []) {
            return [];
        }

        $filas = DB::table('ruta_vendedor as rv')
            ->join('rutas as r', fn (JoinClause $join): JoinClause => $join->on('r.id', '=', 'rv.ruta_id')->where('r.activa', true))
            ->join('ruta_municipio as rm', 'rm.ruta_id', '=', 'r.id')
            ->join('clientes as c', fn (JoinClause $join): JoinClause => $join->on('c.municipio_id', '=', 'rm.municipio_id')->where('c.activo', true))
            ->whereIn('rv.user_id', $vendedores)
            ->groupBy('rv.user_id')
            ->selectRaw('rv.user_id, COUNT(DISTINCT c.id) AS clientes')
            ->get();

        $cartera = [];

        foreach ($filas as $f) {
            /** @var stdClass $f */
            $cartera[self::entero($f->user_id)] = self::entero($f->clientes);
        }

        return $cartera;
    }

    /**
     * Qué parte del total es (0.25 = 25 %).
     */
    private static function parte(float $venta, float $total): ?float
    {
        return $total > 0 ? $venta / $total : null;
    }

    /**
     * l = una fila por línea facturada: factura, vendedor, cliente, fecha,
     * producto, venta neta (sin ISV, con descuentos) y unidades.
     *
     * @return list<stdClass>
     */
    private function consultar(string $select): array
    {
        $valores = [
            EstadoFactura::Vigente->value,
            $this->periodo->desde->format('Y-m-d 00:00:00'),
            $this->periodo->hasta->addDay()->format('Y-m-d 00:00:00'),
        ];
        $alcance = '';

        if ($this->bodega !== null) {
            $alcance .= ' AND fa.bodega_id = ?';
            $valores[] = $this->bodega->id;
        }

        if ($this->vendedorId !== null) {
            $alcance .= ' AND p.vendedor_id = ?';
            $valores[] = $this->vendedorId;
        }

        $cte = <<<SQL
            WITH l AS (
                SELECT fa.id AS factura_id, p.vendedor_id, p.cliente_id, fa.fecha_emision, i.producto_id,
                    i.subtotal - i.descuento AS neta,
                    COALESCE(i.cantidad_despachada, i.cantidad) * GREATEST(i.contenido, 1) AS unidades
                FROM facturas fa
                JOIN pedidos p ON p.id = fa.pedido_id
                JOIN pedido_items i ON i.pedido_id = fa.pedido_id
                WHERE fa.estado = ? AND fa.fecha_emision >= ? AND fa.fecha_emision < ?{$alcance}
            )
            SQL;

        /** @var list<stdClass> $filas */
        $filas = DB::select($cte.' '.$select, $valores);

        return $filas;
    }

    private static function variacion(float $ahora, float $antes): ?float
    {
        return $antes > 0 ? ($ahora - $antes) / $antes : null;
    }

    private static function numero(mixed $valor): float
    {
        return is_numeric($valor) ? (float) $valor : 0.0;
    }

    private static function entero(mixed $valor): int
    {
        return is_numeric($valor) ? (int) $valor : 0;
    }

    private static function texto(mixed $valor): string
    {
        return is_scalar($valor) ? (string) $valor : '';
    }
}
