<?php

declare(strict_types=1);

namespace App\Domain\Reportes;

use App\Domain\Precios\CalculadoraPrecio;
use App\Models\Bodega;
use App\Models\Proveedor;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Inventario valorizado: qué hay en la bodega (o en todas), de qué
 * productos y cuánto dinero hay en ellos.
 *
 *  - Invertido: lo que hay de cada presentación × su costo promedio, sin
 *    ISV (y aparte con ISV, que es lo que se le pagó al proveedor).
 *  - A precio de venta: lo que hay de cada presentación × su precio de
 *    venta, sin ISV.
 *  - Ganancia esperada: lo que dejaría si se vende todo a ese precio.
 *
 * Todo sale de cada bodega y presentación, redondeado ahí, y de ahí se suman el
 * detalle por producto, el resumen por bodega, por proveedor y el total: así
 * las cuentas cuadran entre sí. Lo que está en negativo (se despachó sin
 * tener existencia cargada) no se suma y se avisa.
 */
final class InventarioValorizado
{
    /** @var list<stdClass>|null */
    private ?array $filas = null;

    /** @var list<FilaInventario>|null */
    private ?array $productos = null;

    public function __construct(
        public readonly ?Bodega $bodega,
        public readonly ?Proveedor $proveedor = null,
        public readonly string $buscar = '',
        public readonly bool $soloConExistencia = true,
    ) {}

    public function nombreBodega(): string
    {
        return $this->bodega === null ? 'Todas las bodegas' : $this->bodega->nombre;
    }

    /**
     * "Proveedor: MERHONSA · Busca: aceite · Incluye productos sin existencia".
     */
    public function filtrosTexto(): ?string
    {
        $partes = array_filter([
            $this->proveedor === null ? null : 'Proveedor: '.$this->proveedor->etiqueta(),
            trim($this->buscar) === '' ? null : 'Busca: '.trim($this->buscar),
            $this->soloConExistencia ? null : 'Incluye productos sin existencia',
        ]);

        return $partes === [] ? null : implode(' · ', $partes);
    }

    /**
     * "inventario-copan-2026-11-11".
     */
    public function nombreArchivo(): string
    {
        return 'inventario-'.($this->bodega === null ? 'todas' : str($this->bodega->codigo)->slug()->toString()).'-'.now()->format('Y-m-d');
    }

    /**
     * Un renglón por producto (sumando las bodegas si son todas), por
     * proveedor y nombre.
     *
     * @return list<FilaInventario>
     */
    public function productos(): array
    {
        if ($this->productos !== null) {
            return $this->productos;
        }

        $porProducto = [];

        foreach ($this->filas() as $f) {
            $porProducto[(int) $f->id][] = $f;
        }

        $productos = [];

        foreach ($porProducto as $filas) {
            $primera = $filas[0];
            $suma = self::sumar($filas);
            $unidades = $suma['unidades'];

            if ($unidades === 0 && ($this->soloConExistencia || ! (bool) $primera->activo)) {
                continue;
            }

            $productos[] = new FilaInventario(
                nombre: self::texto($primera->descripcion),
                codigo: self::texto($primera->codigo),
                detalle: self::texto($primera->proveedor),
                productos: 1,
                unidades: $unidades,
                existencia: self::existenciaTexto($filas),
                // Por unidad base: lo invertido entre lo que hay (sin nada, el de la base).
                costoUnidad: $unidades > 0 ? round($suma['invertido'] / $unidades, 4) : (float) $primera->costo_base,
                precioUnidad: $unidades > 0 ? round($suma['valorVenta'] / $unidades, 4) : (float) $primera->precio_unidad,
                invertido: $suma['invertido'],
                invertidoConIsv: $suma['invertidoConIsv'],
                valorVenta: $suma['valorVenta'],
                precioFijo: (bool) $primera->precio_fijo,
            );
        }

        return $this->productos = $productos;
    }

    public function totales(): FilaInventario
    {
        return self::resumen('Total', $this->productos());
    }

    /**
     * De lo que más dinero tiene a lo que menos.
     *
     * @return list<FilaInventario>
     */
    public function porProveedor(): array
    {
        $grupos = [];

        foreach ($this->productos() as $producto) {
            $grupos[(string) $producto->detalle][] = $producto;
        }

        $filas = [];

        foreach ($grupos as $proveedor => $productos) {
            $filas[] = self::resumen((string) $proveedor, $productos);
        }

        usort($filas, fn (FilaInventario $a, FilaInventario $b): int => $b->invertido <=> $a->invertido ?: strcmp($a->nombre, $b->nombre));

        return $filas;
    }

    /**
     * Solo con todas las bodegas: cuánto hay en cada una.
     *
     * @return list<FilaInventario>
     */
    public function porBodega(): array
    {
        $grupos = [];

        foreach ($this->filas() as $f) {
            if ($f->bodega !== null && (int) $f->cantidad > 0) {
                $grupos[self::texto($f->bodega)][] = $f;
            }
        }

        $filas = [];

        foreach ($grupos as $bodega => $existencias) {
            $suma = self::sumar($existencias);

            $filas[] = new FilaInventario(
                nombre: (string) $bodega,
                codigo: null,
                detalle: null,
                productos: count(array_unique(array_map(fn (stdClass $f): int => (int) $f->id, $existencias))),
                unidades: $suma['unidades'],
                existencia: null,
                costoUnidad: null,
                precioUnidad: null,
                invertido: $suma['invertido'],
                invertidoConIsv: $suma['invertidoConIsv'],
                valorVenta: $suma['valorVenta'],
            );
        }

        usort($filas, fn (FilaInventario $a, FilaInventario $b): int => $b->invertido <=> $a->invertido ?: strcmp($a->nombre, $b->nombre));

        return $filas;
    }

    /**
     * Cuántos productos están en negativo en alguna bodega (no se suman).
     */
    public function enNegativo(): int
    {
        $ids = [];

        foreach ($this->filas() as $f) {
            if ($f->cantidad !== null && (int) $f->cantidad < 0) {
                $ids[(int) $f->id] = true;
            }
        }

        return count($ids);
    }

    /**
     * Lo que va en pantalla y en el PDF.
     *
     * @return array<string, mixed>
     */
    public function datos(?int $limite = null): array
    {
        $productos = $this->productos();

        return [
            'bodega'      => $this->nombreBodega(),
            'todas'       => $this->bodega === null,
            'filtros'     => $this->filtrosTexto(),
            'fecha'       => now()->format('d/m/Y H:i'),
            'totales'     => $this->totales(),
            'negativos'   => $this->enNegativo(),
            'bodegas'     => $this->bodega === null ? $this->porBodega() : [],
            'proveedores' => $this->porProveedor(),
            'productos'   => $limite === null ? $productos : array_slice($productos, 0, $limite),
            'cuantos'     => count($productos),
            'limite'      => $limite,
        ];
    }

    /**
     * Una fila por bodega y presentación con su existencia (o sin bodega si
     * el producto no ha estado en ninguna).
     *
     * @return list<stdClass>
     */
    private function filas(): array
    {
        if ($this->filas !== null) {
            return $this->filas;
        }

        $valores = [];
        $deBodega = '';
        $condiciones = '';

        if ($this->bodega !== null) {
            $deBodega = ' AND e.bodega_id = ?';
            $valores[] = $this->bodega->id;
        }

        if ($this->proveedor !== null) {
            $condiciones .= ' AND p.proveedor_id = ?';
            $valores[] = $this->proveedor->id;
        }

        $buscar = trim($this->buscar);

        if ($buscar !== '') {
            $condiciones .= ' AND (p.codigo ILIKE ? OR p.descripcion ILIKE ?)';
            $valores[] = "%{$buscar}%";
            $valores[] = "%{$buscar}%";
        }

        /** @var list<stdClass> $filas */
        // precio_unidad: para valorar lo que está en una presentación sin
        // precio (las sueltas de un producto que no se vende suelto).
        $filas = DB::select(<<<SQL
            SELECT p.id, p.codigo, p.descripcion, p.gravado, p.activo, pb.costo AS costo_base,
                COALESCE(NULLIF(pb.precio, 0), ROUND(mx.precio / mx.contenido, 4), 0) AS precio_unidad, pb.precio_fijo,
                COALESCE(NULLIF(pv.nombre_corto, ''), pv.nombre) AS proveedor, b.nombre AS bodega, e.cantidad,
                ps.contenido, ps.costo, ps.precio, CASE WHEN ps.contenido > 1 THEN um.codigo || ps.contenido ELSE um.codigo END AS sigla
            FROM productos p
            JOIN proveedores pv ON pv.id = p.proveedor_id
            JOIN presentaciones pb ON pb.producto_id = p.id AND pb.es_base
            LEFT JOIN LATERAL (SELECT pz.contenido, pz.precio FROM presentaciones pz
                WHERE pz.producto_id = p.id AND pz.activo AND pz.contenido > 1 ORDER BY pz.contenido DESC, pz.id LIMIT 1) mx ON TRUE
            LEFT JOIN existencias e ON e.producto_id = p.id{$deBodega}
            LEFT JOIN presentaciones ps ON ps.id = e.presentacion_id
            LEFT JOIN unidades_medida um ON um.id = ps.unidad_medida_id
            LEFT JOIN bodegas b ON b.id = e.bodega_id
            WHERE TRUE{$condiciones}
            ORDER BY proveedor, p.descripcion, p.id, b.nombre, ps.contenido DESC, ps.id
            SQL, $valores);

        return $this->filas = $filas;
    }

    /**
     * Suma las existencias positivas, redondeando cada una: cada
     * presentación con su costo y su precio.
     *
     * @param list<stdClass> $filas
     *
     * @return array{unidades: int, invertido: float, invertidoConIsv: float, valorVenta: float}
     */
    private static function sumar(array $filas): array
    {
        $suma = ['unidades' => 0, 'invertido' => 0.0, 'invertidoConIsv' => 0.0, 'valorVenta' => 0.0];

        foreach ($filas as $f) {
            $cantidad = max(0, (int) $f->cantidad);

            if ($cantidad === 0) {
                continue;
            }

            $unidades = $cantidad * max(1, (int) $f->contenido);
            $invertido = round($cantidad * (float) $f->costo, 2);

            $suma['unidades'] += $unidades;
            $suma['invertido'] += $invertido;
            $suma['invertidoConIsv'] += CalculadoraPrecio::conIsv($invertido, (bool) $f->gravado);
            $suma['valorVenta'] += (float) $f->precio > 0 ? round($cantidad * (float) $f->precio, 2) : round($unidades * (float) $f->precio_unidad, 2);
        }

        return [
            'unidades'        => $suma['unidades'],
            'invertido'       => round($suma['invertido'], 2),
            'invertidoConIsv' => round($suma['invertidoConIsv'], 2),
            'valorVenta'      => round($suma['valorVenta'], 2),
        ];
    }

    /**
     * Lo que hay de cada presentación (sumando las bodegas, sin lo negativo),
     * la más grande primero: "2 CJ48 · 5 UN"; "0" si no hay.
     *
     * @param list<stdClass> $filas
     */
    private static function existenciaTexto(array $filas): string
    {
        $por = [];

        foreach ($filas as $f) {
            $cantidad = max(0, (int) $f->cantidad);

            if ($cantidad > 0) {
                $sigla = self::texto($f->sigla);
                $por[$sigla] = [(int) $f->contenido, ($por[$sigla][1] ?? 0) + $cantidad];
            }
        }

        uasort($por, fn (array $a, array $b): int => $b[0] <=> $a[0]);

        $partes = [];

        foreach ($por as $sigla => [, $cantidad]) {
            $partes[] = "{$cantidad} {$sigla}";
        }

        return $partes === [] ? '0' : implode(' · ', $partes);
    }

    /**
     * @param list<FilaInventario> $productos
     */
    private static function resumen(string $nombre, array $productos): FilaInventario
    {
        $sumar = fn (callable $campo): float => round(array_sum(array_map($campo, $productos)), 2);

        return new FilaInventario(
            nombre: $nombre,
            codigo: null,
            detalle: null,
            productos: count(array_filter($productos, fn (FilaInventario $p): bool => $p->unidades > 0)),
            unidades: array_sum(array_map(fn (FilaInventario $p): int => $p->unidades, $productos)),
            existencia: null,
            costoUnidad: null,
            precioUnidad: null,
            invertido: $sumar(fn (FilaInventario $p): float => $p->invertido),
            invertidoConIsv: $sumar(fn (FilaInventario $p): float => $p->invertidoConIsv),
            valorVenta: $sumar(fn (FilaInventario $p): float => $p->valorVenta),
        );
    }

    private static function texto(mixed $valor): string
    {
        return is_scalar($valor) ? (string) $valor : '';
    }
}
