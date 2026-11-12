<?php

declare(strict_types=1);

namespace App\Domain\Reportes;

use App\Enums\EstadoPedido;
use App\Enums\TipoMovimiento;
use App\Enums\UnidadVenta;
use App\Models\Bodega;
use App\Models\Existencia;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Reporte de ventas de un periodo, de una bodega o de todas.
 *
 * Es venta lo que bodega ya despachó (pedidos Por imprimir e Impresos), en
 * el día en que se validó el despacho. Pendientes y anulados no cuentan.
 *
 *  - Venta: lo cobrado sin ISV, ya con los descuentos de promociones.
 *  - Total: lo facturado, con ISV.
 *  - Costo: lo que costó lo que salió, con el costo promedio que tenía el
 *    producto ese día (el kárdex lo guarda en cada salida y corrección).
 *    Los pedidos despachados antes de que hubiera inventario no tienen
 *    salida: su costo se estima con el de hoy y se avisa cuántos son.
 *  - Ganancia: venta − costo. Margen: ganancia ÷ venta.
 *
 * Se puede sacar todo junto o cada parte: totales (con el detalle por día y
 * por bodega), por vendedor, por producto o por cliente.
 */
final class VentasDelPeriodo
{
    /** Lo que se puede sacar: todo junto o cada parte por separado. */
    public const VER = [
        'todo'       => 'Todo',
        'totales'    => 'Totales y ganancia',
        'vendedores' => 'Por vendedor',
        'productos'  => 'Por producto',
        'clientes'   => 'Por cliente',
    ];

    private const SUMAS = 'COALESCE(SUM(l.neta), 0) AS venta, COALESCE(SUM(l.descuento), 0) AS descuento, '
        .'COALESCE(SUM(l.isv), 0) AS isv, COALESCE(SUM(l.total), 0) AS total, COALESCE(SUM(l.costo), 0) AS costo';

    private const DIAS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

    private ?FilaVenta $totales = null;

    public function __construct(
        public readonly Periodo $periodo,
        public readonly ?Bodega $bodega,
    ) {}

    public static function ver(mixed $ver): string
    {
        return is_string($ver) && array_key_exists($ver, self::VER) ? $ver : 'todo';
    }

    /**
     * Si la parte va en lo que se escogió ("todo" lleva todas).
     */
    public static function incluye(string $ver, string $parte): bool
    {
        return $ver === 'todo' || $ver === $parte;
    }

    public function titulo(string $ver): string
    {
        return $ver === 'todo' ? 'Reporte de ventas' : 'Ventas · '.self::VER[$ver];
    }

    public function nombreBodega(): string
    {
        return $this->bodega === null ? 'Todas las bodegas' : $this->bodega->nombre;
    }

    /**
     * "ventas-por-vendedor-copan-2026-11-01_2026-11-30".
     */
    public function nombreArchivo(string $ver): string
    {
        return collect([
            'ventas',
            $ver === 'todo' ? null : str_replace('_', '-', $ver),
            $this->bodega === null ? 'todas' : str($this->bodega->codigo)->slug()->toString(),
            $this->periodo->enArchivo(),
        ])->filter()->implode('-');
    }

    /**
     * El total del periodo (también es el pie de cada tabla).
     */
    public function totales(): FilaVenta
    {
        if ($this->totales !== null) {
            return $this->totales;
        }

        $fila = $this->consultar('SELECT COUNT(DISTINCT l.pedido_id) AS pedidos, COUNT(DISTINCT l.cliente_id) AS clientes, COALESCE(SUM(l.unidades), 0) AS unidades, '.self::SUMAS.' FROM lineas l')[0] ?? new stdClass;

        return $this->totales = self::fila($fila, 'Total');
    }

    /**
     * Cuántos pedidos no tienen salida en el kárdex (su costo es estimado).
     */
    public function pedidosEstimados(): int
    {
        $fila = $this->consultar('SELECT COUNT(DISTINCT l.pedido_id) AS cuantos FROM lineas l WHERE l.estimado')[0] ?? null;

        return (int) self::numero($fila?->cuantos ?? 0);
    }

    /**
     * @return list<FilaVenta>
     */
    public function porDia(): array
    {
        $filas = $this->consultar('SELECT CAST(l.validado_at AS date) AS dia, COUNT(DISTINCT l.pedido_id) AS pedidos, COUNT(DISTINCT l.cliente_id) AS clientes, '
            .self::SUMAS.' FROM lineas l GROUP BY 1 ORDER BY 1');

        return array_map(function (stdClass $f): FilaVenta {
            $dia = CarbonImmutable::parse(self::texto($f->dia));

            return self::fila($f, (self::DIAS[$dia->dayOfWeek] ?? '').' '.$dia->format('d/m/Y'), $dia->format('Y-m-d'));
        }, $filas);
    }

    /**
     * Solo tiene sentido con todas las bodegas.
     *
     * @return list<FilaVenta>
     */
    public function porBodega(): array
    {
        $filas = $this->consultar('SELECT b.codigo, b.nombre, COUNT(DISTINCT l.pedido_id) AS pedidos, COUNT(DISTINCT l.cliente_id) AS clientes, '
            .self::SUMAS.' FROM lineas l JOIN bodegas b ON b.id = l.bodega_id GROUP BY b.id, b.codigo, b.nombre ORDER BY venta DESC, b.nombre');

        return array_map(fn (stdClass $f): FilaVenta => self::fila($f, self::texto($f->nombre), self::texto($f->codigo)), $filas);
    }

    /**
     * @return list<FilaVenta>
     */
    public function porVendedor(): array
    {
        $filas = $this->consultar('SELECT u.codigo, u.name AS nombre, COUNT(DISTINCT l.pedido_id) AS pedidos, COUNT(DISTINCT l.cliente_id) AS clientes, '
            .self::SUMAS.' FROM lineas l JOIN users u ON u.id = l.vendedor_id GROUP BY u.id, u.codigo, u.name ORDER BY venta DESC, u.name');

        return array_map(fn (stdClass $f): FilaVenta => self::fila($f, self::texto($f->nombre), self::textoONulo($f->codigo)), $filas);
    }

    /**
     * Unidades que salieron, también en cajas: "12 CJ + 5 UN".
     *
     * @return list<FilaVenta>
     */
    public function porProducto(): array
    {
        $filas = $this->consultar('SELECT p.codigo, p.descripcion AS nombre, p.unidades_por_caja AS uxc, COALESCE(NULLIF(pv.nombre_corto, \'\'), pv.nombre) AS proveedor, '
            .'COUNT(DISTINCT l.pedido_id) AS pedidos, COUNT(DISTINCT l.cliente_id) AS clientes, COALESCE(SUM(l.unidades), 0) AS unidades, '.self::SUMAS
            .' FROM lineas l JOIN productos p ON p.id = l.producto_id JOIN proveedores pv ON pv.id = p.proveedor_id'
            .' GROUP BY p.id, p.codigo, p.descripcion, p.unidades_por_caja, pv.id, pv.nombre, pv.nombre_corto ORDER BY venta DESC, p.descripcion');

        return array_map(function (stdClass $f): FilaVenta {
            $unidades = (int) self::numero($f->unidades);

            return self::fila(
                $f,
                self::texto($f->nombre),
                self::texto($f->codigo),
                self::texto($f->proveedor),
                Existencia::enCajas($unidades, (int) self::numero($f->uxc)),
            );
        }, $filas);
    }

    /**
     * Con el día del último despacho en el periodo.
     *
     * @return list<FilaVenta>
     */
    public function porCliente(): array
    {
        $filas = $this->consultar('SELECT c.codigo, c.nombre, MAX(l.validado_at) AS ultima, COUNT(DISTINCT l.pedido_id) AS pedidos, 1 AS clientes, '
            .self::SUMAS.' FROM lineas l JOIN clientes c ON c.id = l.cliente_id GROUP BY c.id, c.codigo, c.nombre ORDER BY venta DESC, c.nombre');

        return array_map(fn (stdClass $f): FilaVenta => self::fila(
            $f,
            self::texto($f->nombre),
            self::textoONulo($f->codigo),
            'Último: '.CarbonImmutable::parse(self::texto($f->ultima))->format('d/m/Y'),
        ), $filas);
    }

    /**
     * Lo que va en pantalla y en el PDF según lo que se escogió en "Ver".
     *
     * @return array<string, mixed>
     */
    public function datos(string $ver, ?int $limite = null): array
    {
        $ver = self::ver($ver);

        $vendedores = self::incluye($ver, 'vendedores') ? $this->porVendedor() : null;
        $productos = self::incluye($ver, 'productos') ? $this->porProducto() : null;
        $clientes = self::incluye($ver, 'clientes') ? $this->porCliente() : null;

        return [
            'ver'        => $ver,
            'titulo'     => $this->titulo($ver),
            'periodo'    => $this->periodo->texto(),
            'bodega'     => $this->nombreBodega(),
            'todas'      => $this->bodega === null,
            'totales'    => $this->totales(),
            'estimados'  => $this->pedidosEstimados(),
            'verTotales' => self::incluye($ver, 'totales'),
            'dias'       => self::incluye($ver, 'totales') ? $this->porDia() : [],
            'bodegas'    => self::incluye($ver, 'totales') && $this->bodega === null ? $this->porBodega() : [],
            'vendedores' => $vendedores === null ? null : self::recortar($vendedores, $limite),
            'productos'  => $productos === null ? null : self::recortar($productos, $limite),
            'clientes'   => $clientes === null ? null : self::recortar($clientes, $limite),
            'cuantos'    => [
                'vendedores' => $vendedores === null ? 0 : count($vendedores),
                'productos'  => $productos === null ? 0 : count($productos),
                'clientes'   => $clientes === null ? 0 : count($clientes),
            ],
            'limite' => $limite,
        ];
    }

    /**
     * En pantalla las tablas largas se cortan (las primeras, que son las que más venden).
     *
     * @param list<FilaVenta> $filas
     *
     * @return list<FilaVenta>
     */
    private static function recortar(array $filas, ?int $limite): array
    {
        return $limite === null ? $filas : array_slice($filas, 0, $limite);
    }

    /**
     * Corre la consulta sobre "lineas": una fila por pedido y producto con lo
     * cobrado, lo que salió y lo que costó.
     *
     * @return list<stdClass>
     */
    private function consultar(string $select): array
    {
        [$cte, $valores] = $this->lineas();

        /** @var list<stdClass> $filas */
        $filas = DB::select($cte.' '.$select, $valores);

        return $filas;
    }

    /**
     * ped    = pedidos despachados en el periodo (y la bodega)
     * it     = lo cobrado por pedido y producto, y las unidades que salieron
     * k      = lo que costó según el kárdex (despacho + correcciones)
     * lineas = todo junto; sin kárdex, el costo se estima con el de hoy
     *
     * @return array{0: string, 1: list<int|string>}
     */
    private function lineas(): array
    {
        $valores = [
            EstadoPedido::PorImprimir->value,
            EstadoPedido::Impreso->value,
            $this->periodo->desde->format('Y-m-d H:i:s'),
            $this->periodo->hasta->addDay()->format('Y-m-d H:i:s'),
        ];

        $deBodega = '';

        if ($this->bodega !== null) {
            $deBodega = ' AND p.bodega_id = ?';
            $valores[] = $this->bodega->id;
        }

        $valores[] = UnidadVenta::Caja->value;
        $valores[] = TipoMovimiento::Despacho->value;
        $valores[] = TipoMovimiento::Correccion->value;

        $cte = <<<SQL
            WITH ped AS (
                SELECT p.id, p.bodega_id, p.cliente_id, p.vendedor_id, p.validado_at
                FROM pedidos p
                WHERE p.estado IN (?, ?) AND p.validado_at >= ? AND p.validado_at < ?{$deBodega}
            ),
            it AS (
                SELECT i.pedido_id, i.producto_id,
                    SUM(i.subtotal) AS bruto, SUM(i.descuento) AS descuento, SUM(i.isv) AS isv, SUM(i.total) AS total,
                    SUM(COALESCE(i.cantidad_despachada, i.cantidad) * CASE WHEN i.unidad = ? THEN GREATEST(pr.unidades_por_caja, 1) ELSE 1 END) AS unidades
                FROM pedido_items i
                JOIN ped ON ped.id = i.pedido_id
                JOIN productos pr ON pr.id = i.producto_id
                GROUP BY i.pedido_id, i.producto_id
            ),
            k AS (
                SELECT m.pedido_id, m.producto_id, -SUM(m.cantidad * m.costo_unidad) AS costo
                FROM movimientos_inventario m
                JOIN ped ON ped.id = m.pedido_id
                WHERE m.tipo IN (?, ?)
                GROUP BY m.pedido_id, m.producto_id
            ),
            lineas AS (
                SELECT ped.id AS pedido_id, ped.bodega_id, ped.cliente_id, ped.vendedor_id, ped.validado_at, it.producto_id,
                    it.descuento, it.bruto - it.descuento AS neta, it.isv, it.total, it.unidades,
                    COALESCE(k.costo, it.unidades * pr.costo_unidad) AS costo,
                    (k.pedido_id IS NULL AND it.unidades <> 0) AS estimado
                FROM it
                JOIN ped ON ped.id = it.pedido_id
                JOIN productos pr ON pr.id = it.producto_id
                LEFT JOIN k ON k.pedido_id = it.pedido_id AND k.producto_id = it.producto_id
            )
            SQL;

        return [$cte, $valores];
    }

    private static function fila(stdClass $f, string $nombre, ?string $codigo = null, ?string $detalle = null, ?string $cantidad = null): FilaVenta
    {
        return new FilaVenta(
            nombre: $nombre,
            codigo: $codigo,
            detalle: $detalle,
            pedidos: (int) self::numero($f->pedidos ?? 0),
            clientes: (int) self::numero($f->clientes ?? 0),
            unidades: (int) self::numero($f->unidades ?? 0),
            cantidad: $cantidad,
            venta: round(self::numero($f->venta ?? 0), 2),
            descuento: round(self::numero($f->descuento ?? 0), 2),
            isv: round(self::numero($f->isv ?? 0), 2),
            total: round(self::numero($f->total ?? 0), 2),
            costo: round(self::numero($f->costo ?? 0), 2),
        );
    }

    private static function numero(mixed $valor): float
    {
        return is_numeric($valor) ? (float) $valor : 0.0;
    }

    private static function texto(mixed $valor): string
    {
        return is_scalar($valor) ? (string) $valor : '';
    }

    private static function textoONulo(mixed $valor): ?string
    {
        $texto = self::texto($valor);

        return $texto === '' ? null : $texto;
    }
}
