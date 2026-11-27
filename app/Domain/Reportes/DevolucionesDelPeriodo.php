<?php

declare(strict_types=1);

namespace App\Domain\Reportes;

use App\Domain\Cargas\HojaDeCarga;
use App\Enums\TipoDevolucion;
use App\Models\Bodega;
use App\Models\Proveedor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Reporte de devoluciones y pérdidas de un periodo (por la fecha de la
 * devolución), en una bodega o en todas, y de todos los proveedores o de uno.
 * Lo que se registra cuando el que sale a entregar regresa de la ruta:
 *
 *  - Pérdidas: lo que regresó dañado. No vuelve a la bodega; vale lo que
 *    costaba al devolverlo (devolucion_items.costo). Por proveedor y por
 *    producto, para reclamarlo.
 *  - Cambio por cambio: lo que regresó y se le repuso al cliente con lo
 *    mismo. Lo bueno vuelve a la bodega; lo dañado, además, es pérdida.
 *  - Con crédito: se le devolvió lo que pagó (con nota de crédito).
 *  - Faltantes: lo que no recibió (le faltó de su factura). Lo que se le
 *    dio a otro cliente en un cambio (`faltante`) ni regresa ni es pérdida;
 *    lo que se dañó en el camión es pérdida; lo que no salió regresó.
 *
 * Las cantidades van en la presentación en que regresaron (2 CJ12, 5 UN) y
 * los montos a costo, salvo el crédito, que es lo que se le devolvió (con ISV).
 *
 * Lo dañado se da de baja del inventario con el acta de baja del periodo
 * (acta()): una por bodega, renglón por renglón, con las firmas.
 *
 * @phpstan-type ActaRenglon array{fecha: string, devolucion: string, tipo: string, factura: string|null, cliente: string, motivo: string|null, codigo: string, producto: string, proveedor: string, presentacion: string, cantidad: int, costo: float, perdida: float}
 * @phpstan-type Acta array{bodega: string, direccion: string|null, renglones: list<ActaRenglon>, proveedores: list<array{proveedor: string, productos: int, perdida: float}>, total: float}
 */
final class DevolucionesDelPeriodo
{
    /** Lo que se puede sacar: todo junto o cada parte por separado. */
    public const VER = [
        'todo'         => 'Todo',
        'perdidas'     => 'Pérdidas',
        'cambios'      => 'Cambio por cambio',
        'devoluciones' => 'Cada devolución',
    ];

    private const PROVEEDOR = "COALESCE(NULLIF(pv.nombre_corto, ''), pv.nombre)";

    private const PRESENTACION = "um.codigo || CASE WHEN pz.contenido > 1 THEN pz.contenido::text ELSE '' END";

    /** @var array{devoluciones: int, cambios: int, creditos: int, perdida: float, productos_perdidos: int, regreso: float, repuesto: float, credito: float}|null */
    private ?array $totales = null;

    public function __construct(
        public readonly Periodo $periodo,
        public readonly ?Bodega $bodega,
        public readonly ?Proveedor $proveedor = null,
    ) {}

    public static function periodo(mixed $clave): string
    {
        return is_string($clave) && array_key_exists($clave, Periodo::OPCIONES) ? $clave : 'mes';
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
     * "Devoluciones y pérdidas" o "Devoluciones y pérdidas · Pérdidas".
     */
    public function titulo(string $ver): string
    {
        return $ver === 'todo' ? 'Devoluciones y pérdidas' : 'Devoluciones y pérdidas · '.self::VER[$ver];
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
     * "devoluciones-perdidas-todas-2026-11-01_2026-11-30".
     */
    public function nombreArchivo(string $ver): string
    {
        return collect([
            'devoluciones',
            $this->proveedor === null ? null : str($this->proveedor->etiqueta())->slug()->toString(),
            $ver === 'todo' ? null : str(self::VER[$ver])->slug()->toString(),
            $this->bodega === null ? 'todas' : str($this->bodega->codigo)->slug()->toString(),
            $this->periodo->enArchivo(),
        ])->filter()->implode('-');
    }

    /**
     * Lo del periodo: cuántas devoluciones (de cambio y con crédito), lo que
     * se perdió (dañado, a costo), lo que regresó a la bodega (a costo), lo
     * que se repuso en los cambios (a costo) y lo que se devolvió en crédito.
     *
     * @return array{devoluciones: int, cambios: int, creditos: int, perdida: float, productos_perdidos: int, regreso: float, repuesto: float, credito: float}
     */
    public function totales(): array
    {
        if ($this->totales !== null) {
            return $this->totales;
        }

        $f = $this->consultar(
            'SELECT COUNT(DISTINCT r.devolucion_id) AS devoluciones,'
            .' COUNT(DISTINCT CASE WHEN r.tipo = ? THEN r.devolucion_id END) AS cambios,'
            .' COUNT(DISTINCT CASE WHEN r.tipo = ? THEN r.devolucion_id END) AS creditos,'
            .' COALESCE(SUM(CASE WHEN r.danado THEN r.cantidad * r.costo ELSE 0 END), 0) AS perdida,'
            .' COUNT(DISTINCT CASE WHEN r.danado THEN r.producto_id END) AS productos_perdidos,'
            .' COALESCE(SUM(CASE WHEN r.danado OR r.faltante THEN 0 ELSE r.cantidad * r.costo END), 0) AS regreso,'
            .' COALESCE(SUM(CASE WHEN r.tipo = ? THEN r.cantidad * r.costo ELSE 0 END), 0) AS repuesto,'
            .' COALESCE(SUM(CASE WHEN r.tipo = ? THEN r.total ELSE 0 END), 0) AS credito'
            .' FROM renglones r',
            [TipoDevolucion::Cambio->value, TipoDevolucion::Credito->value, TipoDevolucion::Cambio->value, TipoDevolucion::Credito->value],
        )[0] ?? new stdClass;

        return $this->totales = [
            'devoluciones'       => (int) self::numero($f->devoluciones ?? 0),
            'cambios'            => (int) self::numero($f->cambios ?? 0),
            'creditos'           => (int) self::numero($f->creditos ?? 0),
            'perdida'            => round(self::numero($f->perdida ?? 0), 2),
            'productos_perdidos' => (int) self::numero($f->productos_perdidos ?? 0),
            'regreso'            => round(self::numero($f->regreso ?? 0), 2),
            'repuesto'           => round(self::numero($f->repuesto ?? 0), 2),
            'credito'            => round(self::numero($f->credito ?? 0), 2),
        ];
    }

    /**
     * Lo que se perdió de cada proveedor (lo dañado, a costo), del que más al que menos.
     *
     * @return list<array{proveedor: string, productos: int, perdida: float}>
     */
    public function perdidasPorProveedor(): array
    {
        $filas = $this->consultar(
            'SELECT '.self::PROVEEDOR.' AS proveedor, COUNT(DISTINCT r.producto_id) AS productos, SUM(r.cantidad * r.costo) AS perdida'
            .' FROM renglones r JOIN productos p ON p.id = r.producto_id JOIN proveedores pv ON pv.id = p.proveedor_id'
            .' WHERE r.danado'
            .' GROUP BY pv.id, pv.nombre, pv.nombre_corto ORDER BY perdida DESC, proveedor',
        );

        return array_map(fn (stdClass $f): array => [
            'proveedor' => self::texto($f->proveedor),
            'productos' => (int) self::numero($f->productos),
            'perdida'   => round(self::numero($f->perdida), 2),
        ], $filas);
    }

    /**
     * Lo dañado, producto por producto en la presentación en que regresó,
     * por proveedor (para el reclamo) y de lo que más se perdió a lo que menos.
     *
     * @return list<array{codigo: string, producto: string, proveedor: string, presentacion: string, cantidad: int, costo: float, perdida: float}>
     */
    public function perdidas(): array
    {
        $filas = $this->consultar(
            'SELECT p.codigo, p.descripcion AS producto, '.self::PROVEEDOR.' AS proveedor, '.self::PRESENTACION.' AS presentacion,'
            .' SUM(r.cantidad) AS cantidad, SUM(r.cantidad * r.costo) AS perdida'
            .' FROM renglones r JOIN productos p ON p.id = r.producto_id JOIN proveedores pv ON pv.id = p.proveedor_id'
            .' JOIN presentaciones pz ON pz.id = r.presentacion_id JOIN unidades_medida um ON um.id = pz.unidad_medida_id'
            .' WHERE r.danado'
            .' GROUP BY p.id, p.codigo, p.descripcion, pv.id, pv.nombre, pv.nombre_corto, pz.id, pz.contenido, um.codigo'
            .' ORDER BY proveedor, perdida DESC, p.descripcion, pz.contenido DESC',
        );

        return array_map(function (stdClass $f): array {
            $cantidad = (int) self::numero($f->cantidad);
            $perdida = round(self::numero($f->perdida), 2);

            return [
                'codigo'       => self::texto($f->codigo),
                'producto'     => self::texto($f->producto),
                'proveedor'    => self::texto($f->proveedor),
                'presentacion' => self::texto($f->presentacion),
                'cantidad'     => $cantidad,
                'costo'        => $cantidad > 0 ? round($perdida / $cantidad, 2) : 0.0,
                'perdida'      => $perdida,
            ];
        }, $filas);
    }

    /**
     * Lo de los cambios, producto por producto en la presentación en que
     * regresó: cuántas volvieron buenas a la bodega, cuántas dañadas y
     * cuántas se le repusieron al cliente (todas: buenas y dañadas).
     *
     * @return list<array{codigo: string, producto: string, proveedor: string, presentacion: string, buenas: int, danadas: int, repuestas: int, costo: float}>
     */
    public function cambios(): array
    {
        $filas = $this->consultar(
            'SELECT p.codigo, p.descripcion AS producto, '.self::PROVEEDOR.' AS proveedor, '.self::PRESENTACION.' AS presentacion,'
            .' SUM(CASE WHEN r.danado OR r.faltante THEN 0 ELSE r.cantidad END) AS buenas, SUM(CASE WHEN r.danado THEN r.cantidad ELSE 0 END) AS danadas,'
            .' SUM(r.cantidad) AS repuestas, SUM(r.cantidad * r.costo) AS costo'
            .' FROM renglones r JOIN productos p ON p.id = r.producto_id JOIN proveedores pv ON pv.id = p.proveedor_id'
            .' JOIN presentaciones pz ON pz.id = r.presentacion_id JOIN unidades_medida um ON um.id = pz.unidad_medida_id'
            .' WHERE r.tipo = ?'
            .' GROUP BY p.id, p.codigo, p.descripcion, pv.id, pv.nombre, pv.nombre_corto, pz.id, pz.contenido, um.codigo'
            .' ORDER BY costo DESC, p.descripcion, pz.contenido DESC',
            [TipoDevolucion::Cambio->value],
        );

        return array_map(fn (stdClass $f): array => [
            'codigo'       => self::texto($f->codigo),
            'producto'     => self::texto($f->producto),
            'proveedor'    => self::texto($f->proveedor),
            'presentacion' => self::texto($f->presentacion),
            'buenas'       => (int) self::numero($f->buenas),
            'danadas'      => (int) self::numero($f->danadas),
            'repuestas'    => (int) self::numero($f->repuestas),
            'costo'        => round(self::numero($f->costo), 2),
        ], $filas);
    }

    /**
     * Cada devolución, en el orden en que se registraron: de quién, de qué
     * factura, si fue cambio o crédito, lo que volvió a la bodega y lo que se
     * perdió (a costo) y lo que se le devolvió en crédito.
     *
     * @return list<array{numero: string, fecha: string, cliente: string, factura: string|null, carga: string|null, registro: string|null, motivo: string|null, tipo: TipoDevolucion, falto: bool, regreso: float, perdida: float, credito: float|null}>
     */
    public function devoluciones(): array
    {
        $filas = $this->consultar(
            'SELECT r.devolucion_id, r.numero, r.fecha, r.tipo, r.falto, r.carga_id, r.motivo, COALESCE(r.cliente_nombre, c.nombre) AS cliente, f.numero AS factura, u.name AS registro,'
            .' SUM(CASE WHEN r.danado OR r.faltante THEN 0 ELSE r.cantidad * r.costo END) AS regreso,'
            .' SUM(CASE WHEN r.danado THEN r.cantidad * r.costo ELSE 0 END) AS perdida,'
            .' SUM(r.total) AS total'
            .' FROM renglones r JOIN clientes c ON c.id = r.cliente_id'
            .' LEFT JOIN facturas f ON f.id = r.factura_id LEFT JOIN users u ON u.id = r.user_id'
            .' GROUP BY r.devolucion_id, r.numero, r.fecha, r.tipo, r.falto, r.carga_id, r.motivo, r.cliente_nombre, c.nombre, f.numero, u.name'
            .' ORDER BY r.fecha, r.devolucion_id',
        );

        return array_map(function (stdClass $f): array {
            $tipo = TipoDevolucion::tryFrom(self::texto($f->tipo)) ?? TipoDevolucion::Cambio;
            $factura = self::textoONulo($f->factura);
            $carga = is_numeric($f->carga_id) ? sprintf('CAR-%06d', (int) $f->carga_id) : null;

            return [
                'numero'   => self::textoONulo($f->numero) ?? sprintf('DEV-%06d', (int) self::numero($f->devolucion_id)),
                'fecha'    => CarbonImmutable::parse(self::texto($f->fecha))->format('d/m/Y'),
                'cliente'  => self::texto($f->cliente),
                'factura'  => $factura === null ? null : HojaDeCarga::corto($factura),
                'carga'    => $carga,
                'registro' => self::textoONulo($f->registro),
                'motivo'   => self::textoONulo($f->motivo),
                'tipo'     => $tipo,
                'falto'    => (bool) $f->falto,
                'regreso'  => round(self::numero($f->regreso), 2),
                'perdida'  => round(self::numero($f->perdida), 2),
                'credito'  => $tipo === TipoDevolucion::Credito ? round(self::numero($f->total), 2) : null,
            ];
        }, $filas);
    }

    /**
     * El acta de baja del periodo: lo que regresó dañado, renglón por renglón
     * (cada devolución y cada producto en la presentación en que regresó),
     * a costo. Una por bodega, porque cada una lo da de baja de su
     * inventario; con lo de cada proveedor (para el reclamo) y el total.
     * Sin nada dañado, ninguna.
     *
     * @return list<Acta>
     */
    public function acta(): array
    {
        $filas = $this->consultar(
            'SELECT b.id AS bodega_id, b.nombre AS bodega, b.direccion, r.devolucion_id, r.numero, r.fecha, r.tipo, r.motivo,'
            .' COALESCE(r.cliente_nombre, c.nombre) AS cliente, f.numero AS factura,'
            .' p.id AS producto_id, p.codigo, p.descripcion AS producto, '.self::PROVEEDOR.' AS proveedor, '.self::PRESENTACION.' AS presentacion,'
            .' SUM(r.cantidad) AS cantidad, SUM(r.cantidad * r.costo) AS perdida'
            .' FROM renglones r JOIN bodegas b ON b.id = r.bodega_id JOIN clientes c ON c.id = r.cliente_id'
            .' JOIN productos p ON p.id = r.producto_id JOIN proveedores pv ON pv.id = p.proveedor_id'
            .' JOIN presentaciones pz ON pz.id = r.presentacion_id JOIN unidades_medida um ON um.id = pz.unidad_medida_id'
            .' LEFT JOIN facturas f ON f.id = r.factura_id'
            .' WHERE r.danado'
            .' GROUP BY b.id, b.nombre, b.direccion, r.devolucion_id, r.numero, r.fecha, r.tipo, r.motivo, r.cliente_nombre, c.nombre, f.numero,'
            .' p.id, p.codigo, p.descripcion, pv.id, pv.nombre, pv.nombre_corto, pz.id, pz.contenido, um.codigo'
            .' ORDER BY b.nombre, b.id, r.fecha, r.devolucion_id, p.descripcion, pz.contenido DESC',
        );

        $actas = [];

        foreach ($filas as $f) {
            $id = (int) self::numero($f->bodega_id);
            $cantidad = (int) self::numero($f->cantidad);
            $perdida = round(self::numero($f->perdida), 2);
            $factura = self::textoONulo($f->factura);
            $proveedor = self::texto($f->proveedor);

            $actas[$id] ??= ['bodega' => self::texto($f->bodega), 'direccion' => self::textoONulo($f->direccion), 'renglones' => [], 'proveedores' => [], 'productos' => [], 'total' => 0.0];
            $actas[$id]['renglones'][] = [
                'fecha'        => CarbonImmutable::parse(self::texto($f->fecha))->format('d/m/Y'),
                'devolucion'   => self::textoONulo($f->numero) ?? sprintf('DEV-%06d', (int) self::numero($f->devolucion_id)),
                'tipo'         => (TipoDevolucion::tryFrom(self::texto($f->tipo)) ?? TipoDevolucion::Cambio)->getLabel(),
                'factura'      => $factura === null ? null : HojaDeCarga::corto($factura),
                'cliente'      => self::texto($f->cliente),
                'motivo'       => self::textoONulo($f->motivo),
                'codigo'       => self::texto($f->codigo),
                'producto'     => self::texto($f->producto),
                'proveedor'    => $proveedor,
                'presentacion' => self::texto($f->presentacion),
                'cantidad'     => $cantidad,
                'costo'        => $cantidad > 0 ? round($perdida / $cantidad, 2) : 0.0,
                'perdida'      => $perdida,
            ];
            $actas[$id]['total'] = round($actas[$id]['total'] + $perdida, 2);
            $actas[$id]['proveedores'][$proveedor] = round(($actas[$id]['proveedores'][$proveedor] ?? 0.0) + $perdida, 2);
            $actas[$id]['productos'][$proveedor][(int) self::numero($f->producto_id)] = true;
        }

        return array_values(array_map(function (array $acta): array {
            $proveedores = [];

            foreach ($acta['proveedores'] as $nombre => $perdida) {
                $proveedores[] = ['proveedor' => (string) $nombre, 'productos' => count($acta['productos'][$nombre] ?? []), 'perdida' => $perdida];
            }

            usort($proveedores, fn (array $a, array $b): int => [$b['perdida'], $a['proveedor']] <=> [$a['perdida'], $b['proveedor']]);

            return ['bodega' => $acta['bodega'], 'direccion' => $acta['direccion'], 'renglones' => $acta['renglones'], 'proveedores' => $proveedores, 'total' => $acta['total']];
        }, $actas));
    }

    /**
     * Lo que va en pantalla y en el PDF según lo que se escogió en "Ver".
     *
     * @return array<string, mixed>
     */
    public function datos(string $ver, ?int $limite = null): array
    {
        $ver = self::ver($ver);
        $perdidas = self::incluye($ver, 'perdidas') ? $this->perdidas() : null;
        $cambios = self::incluye($ver, 'cambios') ? $this->cambios() : null;
        $devoluciones = self::incluye($ver, 'devoluciones') ? $this->devoluciones() : null;

        return [
            'ver'          => $ver,
            'titulo'       => $this->titulo($ver),
            'periodo'      => $this->periodo->texto(),
            'bodega'       => $this->nombreBodega(),
            'alcance'      => $this->alcance(),
            'proveedor'    => $this->proveedor?->etiqueta(),
            'todas'        => $this->bodega === null,
            'totales'      => $this->totales(),
            'proveedores'  => $perdidas !== null && $this->proveedor === null ? $this->perdidasPorProveedor() : [],
            'perdidas'     => $perdidas === null ? null : self::recortar($perdidas, $limite),
            'cambios'      => $cambios === null ? null : self::recortar($cambios, $limite),
            'devoluciones' => $devoluciones === null ? null : self::recortar($devoluciones, $limite),
            'cuantos'      => [
                'perdidas'     => $perdidas === null ? 0 : count($perdidas),
                'cambios'      => $cambios === null ? 0 : count($cambios),
                'devoluciones' => $devoluciones === null ? 0 : count($devoluciones),
            ],
            'limite' => $limite,
        ];
    }

    /**
     * @template T
     *
     * @param list<T> $filas
     *
     * @return list<T>
     */
    private static function recortar(array $filas, ?int $limite): array
    {
        return $limite === null ? $filas : array_slice($filas, 0, $limite);
    }

    /**
     * Corre la consulta sobre "renglones": una fila por lo que regresó de
     * cada línea (buenas o dañadas) en las devoluciones del periodo, con su
     * devolución y el proveedor del producto.
     *
     * @param list<string|int> $valores los "?" de $select, después de los del periodo
     *
     * @return list<stdClass>
     */
    private function consultar(string $select, array $valores = []): array
    {
        $filtro = [$this->periodo->desde->format('Y-m-d'), $this->periodo->hasta->format('Y-m-d')];
        $donde = '';

        if ($this->bodega !== null) {
            $donde .= ' AND d.bodega_id = ?';
            $filtro[] = $this->bodega->id;
        }

        if ($this->proveedor !== null) {
            $donde .= ' AND pr.proveedor_id = ?';
            $filtro[] = $this->proveedor->id;
        }

        $cte = <<<SQL
            WITH renglones AS (
                SELECT d.id AS devolucion_id, d.numero, d.fecha, d.tipo, d.falto, d.bodega_id, d.cliente_id, d.cliente_nombre,
                    d.factura_id, d.carga_id, d.user_id, d.motivo,
                    i.producto_id, i.presentacion_id, i.cantidad, i.danado, i.faltante, i.costo, i.total
                FROM devolucion_items i
                JOIN devoluciones d ON d.id = i.devolucion_id
                JOIN productos pr ON pr.id = i.producto_id
                WHERE d.fecha >= ? AND d.fecha <= ?{$donde}
            )
            SQL;

        /** @var list<stdClass> $filas */
        $filas = DB::select($cte.' '.$select, [...$filtro, ...$valores]);

        return $filas;
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
