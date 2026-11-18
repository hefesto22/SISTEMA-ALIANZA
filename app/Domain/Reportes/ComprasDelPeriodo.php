<?php

declare(strict_types=1);

namespace App\Domain\Reportes;

use App\Models\Bodega;
use App\Models\Proveedor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Reporte de compras de un periodo: cuánto se le compró a cada proveedor y
 * de qué productos, en una bodega o en todas.
 *
 * Cuenta la fecha de la factura del proveedor (la de la compra), con los
 * montos como vienen en ella: sin ISV, el ISV y el total. Las compras no se
 * editan ni se anulan (lo que entró mal se corrige con un ajuste), así que
 * todas cuentan.
 *
 *  - Sin proveedor escogido: cuánto a cada uno, del que más al que menos.
 *  - Con un proveedor: sus categorías, sus productos y sus facturas; es el
 *    que se le manda al proveedor.
 *
 * Por producto va en la presentación en que entró (10 CJ48, 5 UN) con el
 * costo promedio de cada una, sin ISV.
 */
final class ComprasDelPeriodo
{
    /** Atajos del periodo: semana, quincena y mes (el que va, hasta hoy, y el pasado). */
    public const PERIODOS = [
        'semana'          => 'Esta semana',
        'semana_pasada'   => 'Semana pasada',
        'quincena'        => 'Esta quincena',
        'quincena_pasada' => 'Quincena pasada',
        'mes'             => 'Este mes',
        'mes_pasado'      => 'Mes pasado',
        'otro'            => 'Otras fechas',
    ];

    /** Lo que se puede sacar: todo junto o cada parte por separado. */
    public const VER = [
        'todo'      => 'Todo',
        'productos' => 'Por producto',
        'compras'   => 'Por compra',
    ];

    private const SUMAS = 'COUNT(DISTINCT l.compra_id) AS compras, COUNT(DISTINCT l.producto_id) AS productos, COALESCE(SUM(l.unidades), 0) AS unidades, '
        .'COALESCE(SUM(l.subtotal), 0) AS subtotal, COALESCE(SUM(l.isv), 0) AS isv, COALESCE(SUM(l.total), 0) AS total';

    private const PROVEEDOR = "COALESCE(NULLIF(pv.nombre_corto, ''), pv.nombre)";

    private ?FilaCompra $totales = null;

    private int $proveedores = 0;

    public function __construct(
        public readonly Periodo $periodo,
        public readonly ?Bodega $bodega,
        public readonly ?Proveedor $proveedor = null,
    ) {}

    public static function periodo(mixed $clave): string
    {
        return is_string($clave) && array_key_exists($clave, self::PERIODOS) ? $clave : 'mes';
    }

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

    /**
     * "Reporte de compras", "Compras a MERHONSA" o "Compras a MERHONSA · Por producto".
     */
    public function titulo(string $ver): string
    {
        $titulo = $this->proveedor === null ? 'Reporte de compras' : 'Compras a '.$this->proveedor->etiqueta();

        return $ver === 'todo' ? $titulo : $titulo.' · '.self::VER[$ver];
    }

    public function nombreBodega(): string
    {
        return $this->bodega === null ? 'Todas las bodegas' : $this->bodega->nombre;
    }

    /**
     * "Todas las bodegas" o "BODEGA COPÁN · Proveedor: MERHONSA".
     */
    public function alcance(): string
    {
        return $this->nombreBodega().($this->proveedor === null ? '' : ' · Proveedor: '.$this->proveedor->etiqueta());
    }

    /**
     * Para la hoja que se le manda: "MERHONSA · RTN 08019001234567 · Tel. 2222-3333".
     */
    public function fichaProveedor(): ?string
    {
        if ($this->proveedor === null) {
            return null;
        }

        return collect([
            filled($this->proveedor->razon_social) ? $this->proveedor->razon_social : $this->proveedor->nombre,
            filled($this->proveedor->rtn) ? 'RTN '.$this->proveedor->rtn : null,
            filled($this->proveedor->telefono) ? 'Tel. '.$this->proveedor->telefono : null,
        ])->filter()->implode(' · ');
    }

    /**
     * "compras-merhonsa-por-producto-todas-2026-11-01_2026-11-30".
     */
    public function nombreArchivo(string $ver): string
    {
        return collect([
            'compras',
            $this->proveedor === null ? null : str($this->proveedor->etiqueta())->slug()->toString(),
            $ver === 'todo' ? null : str(self::VER[$ver])->slug()->toString(),
            $this->bodega === null ? 'todas' : str($this->bodega->codigo)->slug()->toString(),
            $this->periodo->enArchivo(),
        ])->filter()->implode('-');
    }

    /**
     * El total del periodo (también es el pie de cada tabla).
     */
    public function totales(): FilaCompra
    {
        if ($this->totales !== null) {
            return $this->totales;
        }

        $fila = $this->consultar('SELECT COUNT(DISTINCT l.proveedor_id) AS proveedores, '.self::SUMAS.' FROM lineas l')[0] ?? new stdClass;
        $this->proveedores = (int) self::numero($fila->proveedores ?? 0);

        return $this->totales = self::fila($fila, 'Total');
    }

    /**
     * A cuántos proveedores se les compró en el periodo.
     */
    public function cuantosProveedores(): int
    {
        $this->totales();

        return $this->proveedores;
    }

    /**
     * A cada proveedor, del que más se le compró al que menos (sin proveedor escogido).
     *
     * @return list<FilaCompra>
     */
    public function porProveedor(): array
    {
        $filas = $this->consultar('SELECT pv.codigo, '.self::PROVEEDOR.' AS nombre, '.self::SUMAS
            .' FROM lineas l JOIN proveedores pv ON pv.id = l.proveedor_id'
            .' GROUP BY pv.id, pv.codigo, pv.nombre, pv.nombre_corto ORDER BY total DESC, nombre');

        return array_map(fn (stdClass $f): FilaCompra => self::fila($f, self::texto($f->nombre), self::textoONulo($f->codigo)), $filas);
    }

    /**
     * Solo tiene sentido con todas las bodegas.
     *
     * @return list<FilaCompra>
     */
    public function porBodega(): array
    {
        $filas = $this->consultar('SELECT b.codigo, b.nombre, '.self::SUMAS
            .' FROM lineas l JOIN bodegas b ON b.id = l.bodega_id GROUP BY b.id, b.codigo, b.nombre ORDER BY total DESC, b.nombre');

        return array_map(fn (stdClass $f): FilaCompra => self::fila($f, self::texto($f->nombre), self::texto($f->codigo)), $filas);
    }

    /**
     * Las categorías del proveedor (con todos, "PROVEEDOR · CATEGORÍA").
     *
     * @return list<FilaCompra>
     */
    public function porCategoria(): array
    {
        $filas = $this->consultar('SELECT cat.nombre, '.self::PROVEEDOR.' AS proveedor, '.self::SUMAS
            .' FROM lineas l JOIN productos p ON p.id = l.producto_id JOIN categorias cat ON cat.id = p.categoria_id'
            .' JOIN proveedores pv ON pv.id = l.proveedor_id'
            .' GROUP BY cat.id, cat.nombre, pv.id, pv.nombre, pv.nombre_corto ORDER BY total DESC, cat.nombre');

        return array_map(fn (stdClass $f): FilaCompra => self::fila(
            $f,
            $this->proveedor === null ? self::texto($f->proveedor).' · '.self::texto($f->nombre) : self::texto($f->nombre),
        ), $filas);
    }

    /**
     * Cada producto en la presentación en que entró, con lo que salió cada
     * una. Arriba el producto al que más se le compró; si entró en dos
     * presentaciones, van juntas.
     *
     * @return list<FilaCompra>
     */
    public function porProducto(): array
    {
        $filas = $this->consultar('SELECT p.codigo, p.descripcion AS nombre, cat.nombre AS categoria, '.self::PROVEEDOR.' AS proveedor,'
            ." um.codigo || CASE WHEN pz.contenido > 1 THEN pz.contenido::text ELSE '' END AS presentacion,"
            .' COALESCE(SUM(l.cantidad), 0) AS cantidad, '.self::SUMAS.','
            .' SUM(SUM(l.total)) OVER (PARTITION BY p.id) AS del_producto'
            .' FROM lineas l JOIN productos p ON p.id = l.producto_id JOIN categorias cat ON cat.id = p.categoria_id'
            .' JOIN proveedores pv ON pv.id = l.proveedor_id'
            .' JOIN presentaciones pz ON pz.id = l.presentacion_id JOIN unidades_medida um ON um.id = pz.unidad_medida_id'
            .' GROUP BY p.id, p.codigo, p.descripcion, cat.nombre, pv.id, pv.nombre, pv.nombre_corto, pz.id, pz.contenido, um.codigo'
            .' ORDER BY del_producto DESC, p.descripcion, p.id, pz.contenido DESC');

        return array_map(fn (stdClass $f): FilaCompra => self::fila(
            $f,
            self::texto($f->nombre),
            self::texto($f->codigo),
            self::texto($f->categoria),
            proveedor: self::texto($f->proveedor),
            presentacion: self::texto($f->presentacion),
        ), $filas);
    }

    /**
     * Cada compra (factura del proveedor), en el orden en que llegaron.
     *
     * @return list<FilaCompra>
     */
    public function porCompra(): array
    {
        $filas = $this->consultar('SELECT c.numero, c.fecha, c.factura_proveedor, b.nombre AS bodega, '.self::PROVEEDOR.' AS proveedor, '.self::SUMAS
            .' FROM lineas l JOIN compras c ON c.id = l.compra_id JOIN bodegas b ON b.id = l.bodega_id JOIN proveedores pv ON pv.id = l.proveedor_id'
            .' GROUP BY c.id, c.numero, c.fecha, c.factura_proveedor, b.nombre, pv.id, pv.nombre, pv.nombre_corto ORDER BY c.fecha, c.id');

        return array_map(fn (stdClass $f): FilaCompra => self::fila(
            $f,
            self::texto($f->numero),
            self::textoONulo($f->factura_proveedor),
            fecha: CarbonImmutable::parse(self::texto($f->fecha))->format('d/m/Y'),
            bodega: self::texto($f->bodega),
            proveedor: self::texto($f->proveedor),
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
        $todo = $ver === 'todo';

        $productos = self::incluye($ver, 'productos') ? $this->porProducto() : null;
        $compras = self::incluye($ver, 'compras') ? $this->porCompra() : null;
        $bodegas = $todo && $this->bodega === null ? $this->porBodega() : [];

        return [
            'ver'         => $ver,
            'titulo'      => $this->titulo($ver),
            'periodo'     => $this->periodo->texto(),
            'bodega'      => $this->nombreBodega(),
            'alcance'     => $this->alcance(),
            'proveedor'   => $this->proveedor?->etiqueta(),
            'todas'       => $this->bodega === null,
            'totales'     => $this->totales(),
            'deCuantos'   => $this->cuantosProveedores(),
            'proveedores' => $todo && $this->proveedor === null ? $this->porProveedor() : [],
            'categorias'  => $todo && $this->proveedor !== null ? $this->porCategoria() : [],
            // Con una sola bodega en el periodo, la tabla no dice nada nuevo.
            'bodegas'   => count($bodegas) > 1 ? $bodegas : [],
            'productos' => $productos === null ? null : self::recortar($productos, $limite),
            'compras'   => $compras === null ? null : self::recortar($compras, $limite),
            'cuantos'   => [
                'productos' => $productos === null ? 0 : count($productos),
                'compras'   => $compras === null ? 0 : count($compras),
            ],
            'limite' => $limite,
        ];
    }

    /**
     * @param list<FilaCompra> $filas
     *
     * @return list<FilaCompra>
     */
    private static function recortar(array $filas, ?int $limite): array
    {
        return $limite === null ? $filas : array_slice($filas, 0, $limite);
    }

    /**
     * Corre la consulta sobre "lineas": una fila por línea de compra del
     * periodo, con su compra, bodega y proveedor.
     *
     * @return list<stdClass>
     */
    private function consultar(string $select): array
    {
        $valores = [$this->periodo->desde->format('Y-m-d'), $this->periodo->hasta->format('Y-m-d')];
        $filtros = '';

        if ($this->bodega !== null) {
            $filtros .= ' AND c.bodega_id = ?';
            $valores[] = $this->bodega->id;
        }

        if ($this->proveedor !== null) {
            $filtros .= ' AND c.proveedor_id = ?';
            $valores[] = $this->proveedor->id;
        }

        $cte = <<<SQL
            WITH lineas AS (
                SELECT c.id AS compra_id, c.bodega_id, c.proveedor_id, i.producto_id, i.presentacion_id,
                    i.cantidad, i.unidades, i.subtotal, i.isv, i.total
                FROM compra_items i
                JOIN compras c ON c.id = i.compra_id
                WHERE c.fecha >= ? AND c.fecha <= ?{$filtros}
            )
            SQL;

        /** @var list<stdClass> $filas */
        $filas = DB::select($cte.' '.$select, $valores);

        return $filas;
    }

    private static function fila(
        stdClass $f,
        string $nombre,
        ?string $codigo = null,
        ?string $detalle = null,
        ?string $fecha = null,
        ?string $bodega = null,
        ?string $proveedor = null,
        ?string $presentacion = null,
    ): FilaCompra {
        return new FilaCompra(
            nombre: $nombre,
            codigo: $codigo,
            detalle: $detalle,
            fecha: $fecha,
            bodega: $bodega,
            proveedor: $proveedor,
            presentacion: $presentacion,
            compras: (int) self::numero($f->compras ?? 0),
            productos: (int) self::numero($f->productos ?? 0),
            cantidad: (int) self::numero($f->cantidad ?? 0),
            unidades: (int) self::numero($f->unidades ?? 0),
            subtotal: round(self::numero($f->subtotal ?? 0), 2),
            isv: round(self::numero($f->isv ?? 0), 2),
            total: round(self::numero($f->total ?? 0), 2),
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
