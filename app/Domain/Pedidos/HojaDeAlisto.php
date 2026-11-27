<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Domain\Inventario\Disponible;
use App\Enums\EstadoPedido;
use App\Models\Bodega;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Presentacion;
use App\Models\Producto;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Hoja de alisto: lo que hay que sacar de la bodega para unos pedidos
 * pendientes (los de un día, o los marcados), para ir apartándolo antes de
 * validar. Por bodega, un renglón por producto con lo que se cobra de cada
 * presentación y a qué pedido va cada parte; los regalos de las promociones
 * van aparte ("regalos"), con a quién va cada uno y de qué promoción. En
 * papel lleva una casilla "Apartado" y, en lo que se cobra, un espacio
 * "Faltó": lo que faltó se anota al validar (ValidarVarios, $faltaron) y a
 * los últimos pedidos les sale menos; los regalos salen solos con lo que se
 * cobra. "faltan" (lo que según el sistema no alcanza, contando los
 * regalos) es para el aviso del modal de validar; en papel no va: allá se
 * revisa en físico.
 *
 * Los pedidos van del más viejo al más nuevo, como al validar.
 *
 * @phpstan-type HojaVenta array{presentacionId: int, sigla: string, cantidad: int}
 * @phpstan-type HojaRenglon array{productoId: int, codigo: string, descripcion: string, sale: list<HojaVenta>, para: list<string>}
 * @phpstan-type HojaBodega array{bodegaId: int, bodega: string, pedidos: list<array{numero: string, cliente: string, fecha: string, total: float}>, total: float, desde: string, hasta: string, productos: list<HojaRenglon>, regalos: list<HojaRenglon>, faltan: list<string>}
 * @phpstan-type HojaParte array<int, list<array{0: int, 1: int, 2: string}>>
 * @phpstan-type HojaProducto array{producto: Producto, cobra: array<int, int>, regalo: array<int, int>, paraCobra: HojaParte, paraRegalo: HojaParte}
 */
final class HojaDeAlisto
{
    /** Cuántas letras del cliente van en "Para" (el número ya dice cuál es). */
    private const CLIENTE = 22;

    /**
     * @param list<int> $ids los que no estén pendientes no van
     *
     * @return list<HojaBodega>
     */
    public static function datos(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $pedidos = Pedido::query()
            ->with('cliente')
            ->whereKey($ids)
            ->where('estado', EstadoPedido::Pendiente)
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();

        if ($pedidos->isEmpty()) {
            return [];
        }

        $items = PedidoItem::query()
            ->with(['producto.presentaciones.unidadMedida', 'presentacion.unidadMedida', 'promocion'])
            ->whereIn('pedido_id', $pedidos->modelKeys())
            ->orderBy('id')
            ->get()
            ->groupBy('pedido_id');

        /** @var array<int, string> $bodegas */
        $bodegas = Bodega::query()->whereKey($pedidos->pluck('bodega_id')->unique()->values()->all())->pluck('nombre', 'id')->all();
        $hoja = [];

        foreach ($pedidos->groupBy('bodega_id') as $bodegaId => $deLaBodega) {
            /** @var array<int, HojaProducto> $productos */
            $productos = [];
            $filas = [];

            foreach ($deLaBodega as $pedido) {
                $filas[] = [
                    'numero'  => (string) $pedido->numero,
                    'cliente' => $pedido->cliente->nombre,
                    'fecha'   => $pedido->fecha->format('d/m/Y'),
                    'total'   => $pedido->total,
                ];

                foreach ($items->get($pedido->id, collect()) as $item) {
                    $cantidad = $item->cantidadFacturable();

                    if ($cantidad <= 0) {
                        continue;
                    }

                    $id = $item->producto_id;
                    $presentacion = $item->presentacion_id;
                    $productos[$id] ??= ['producto' => $item->producto, 'cobra' => [], 'regalo' => [], 'paraCobra' => [], 'paraRegalo' => []];

                    if ($item->bonificacion) {
                        $productos[$id]['regalo'][$presentacion] = ($productos[$id]['regalo'][$presentacion] ?? 0) + $cantidad;
                        $productos[$id]['paraRegalo'][$pedido->id][] = [$presentacion, $cantidad, $item->promocion?->mecanica() ?? ''];
                    } else {
                        $productos[$id]['cobra'][$presentacion] = ($productos[$id]['cobra'][$presentacion] ?? 0) + $cantidad;
                        $productos[$id]['paraCobra'][$pedido->id][] = [$presentacion, $cantidad, ''];
                    }
                }
            }

            uasort($productos, fn (array $a, array $b): int => [$a['producto']->descripcion, $a['producto']->codigo] <=> [$b['producto']->descripcion, $b['producto']->codigo]);

            $hay = LoQueAlcanza::existencias((int) $bodegaId, array_keys($productos));
            $porId = $deLaBodega->keyBy('id')->all();
            $cobra = [];
            $regalos = [];
            $faltan = [];

            foreach ($productos as $producto) {
                $presentaciones = $producto['producto']->presentaciones->sortByDesc('contenido')->values();

                if ($producto['cobra'] !== []) {
                    $cobra[] = self::renglon($producto['producto'], $presentaciones, $producto['cobra'], $producto['paraCobra'], $porId);
                }

                if ($producto['regalo'] !== []) {
                    $regalos[] = self::renglon($producto['producto'], $presentaciones, $producto['regalo'], $producto['paraRegalo'], $porId);
                }

                $falta = self::falta($producto, $presentaciones, $hay);

                if ($falta !== null) {
                    $faltan[] = $producto['producto']->descripcion.' ('.$falta.')';
                }
            }

            $hoja[] = [
                'bodegaId'  => (int) $bodegaId,
                'bodega'    => $bodegas[(int) $bodegaId] ?? '',
                'pedidos'   => $filas,
                'total'     => round((float) $deLaBodega->sum('total'), 2),
                'desde'     => (string) $deLaBodega->min(fn (Pedido $pedido): string => $pedido->fecha->toDateString()),
                'hasta'     => (string) $deLaBodega->max(fn (Pedido $pedido): string => $pedido->fecha->toDateString()),
                'productos' => $cobra,
                'regalos'   => $regalos,
                'faltan'    => $faltan,
            ];
        }

        return $hoja;
    }

    /**
     * Para anotar lo que faltó al validar: ["bodega:presentación" => "JABON
     * X · CJ12 (salen 3)"], de lo que se cobra (los regalos se acomodan
     * solos). Con varias bodegas, el nombre de la bodega adelante.
     *
     * @param list<HojaBodega> $hoja
     *
     * @return array<string, string>
     */
    public static function paraAnotar(array $hoja): array
    {
        $opciones = [];

        foreach ($hoja as $bodega) {
            foreach ($bodega['productos'] as $producto) {
                foreach ($producto['sale'] as $sale) {
                    $opciones[$bodega['bodegaId'].':'.$sale['presentacionId']] = (count($hoja) > 1 ? $bodega['bodega'].' · ' : '')
                        .$producto['descripcion'].' · '.$sale['sigla'].' (salen '.number_format($sale['cantidad']).')';
                }
            }
        }

        return $opciones;
    }

    /**
     * Cuánto se cobra de cada presentación, por bodega: [bodega =>
     * [presentación => cantidad]], el tope de lo que puede faltar.
     *
     * @param list<HojaBodega> $hoja
     *
     * @return array<int, array<int, int>>
     */
    public static function cobrado(array $hoja): array
    {
        $cobrado = [];

        foreach ($hoja as $bodega) {
            foreach ($bodega['productos'] as $producto) {
                foreach ($producto['sale'] as $sale) {
                    $cobrado[$bodega['bodegaId']][$sale['presentacionId']] = $sale['cantidad'];
                }
            }
        }

        return $cobrado;
    }

    /**
     * Un renglón de lo que se cobra o de los regalos: cuánto sale de cada
     * presentación (de la más grande a la más chica) y a qué pedido va.
     *
     * @param Collection<int, Presentacion> $presentaciones del producto, de la más grande a la más chica
     * @param array<int, int> $cantidades [presentacion_id => cantidad]
     * @param HojaParte $para
     * @param array<array-key, Pedido> $pedidos
     *
     * @return HojaRenglon
     */
    private static function renglon(Producto $producto, Collection $presentaciones, array $cantidades, array $para, array $pedidos): array
    {
        $sale = [];

        foreach ($presentaciones as $presentacion) {
            if (($cantidades[$presentacion->id] ?? 0) > 0) {
                $sale[] = [
                    'presentacionId' => $presentacion->id,
                    'sigla'          => $presentacion->sigla(),
                    'cantidad'       => $cantidades[$presentacion->id],
                ];
            }
        }

        return [
            'productoId'  => $producto->id,
            'codigo'      => $producto->codigo,
            'descripcion' => $producto->descripcion,
            'sale'        => $sale,
            'para'        => self::para($para, $presentaciones->keyBy('id')->all(), $pedidos),
        ];
    }

    /**
     * "faltan 2 cajas de 12, falta 1 unidad": lo que no alcanza de todo lo
     * que sale del producto (lo que se cobra y los regalos); null si alcanza.
     *
     * @param HojaProducto $producto
     * @param Collection<int, Presentacion> $presentaciones
     * @param array<int, int> $hay [presentacion_id => lo que hay en la bodega]
     */
    private static function falta(array $producto, Collection $presentaciones, array $hay): ?string
    {
        $pide = $producto['cobra'];

        foreach ($producto['regalo'] as $presentacionId => $cantidad) {
            $pide[$presentacionId] = ($pide[$presentacionId] ?? 0) + $cantidad;
        }

        $contenido = $presentaciones->mapWithKeys(fn (Presentacion $presentacion): array => [$presentacion->id => $presentacion->contenido])->all();
        $falta = [];

        foreach (Disponible::faltantes($pide, $hay, $contenido, $producto['producto']->presentacionBase()->id) as $presentacionId => $cuantas) {
            $presentacion = $presentaciones->firstWhere('id', $presentacionId);

            if ($presentacion instanceof Presentacion) {
                $falta[] = ($cuantas === 1 ? 'falta ' : 'faltan ').$presentacion->enPalabras($cuantas);
            }
        }

        return $falta === [] ? null : implode(', ', $falta);
    }

    /**
     * "COP-004211 SUPERMERCADO BETESDA: 1 CJ12 + 2 UN", uno por pedido, del
     * más viejo al más nuevo; en los regalos, con la promoción: "…: 1 UN
     * (12+1)".
     *
     * @param HojaParte $para [pedido => [[presentación, cantidad, mecánica]]]
     * @param array<array-key, Presentacion> $presentaciones
     * @param array<array-key, Pedido> $pedidos
     *
     * @return list<string>
     */
    private static function para(array $para, array $presentaciones, array $pedidos): array
    {
        $textos = [];

        foreach ($pedidos as $id => $pedido) {
            if (! isset($para[$id])) {
                continue;
            }

            $cantidades = [];
            $promociones = [];

            foreach ($para[$id] as [$presentacionId, $cantidad, $mecanica]) {
                $sigla = isset($presentaciones[$presentacionId]) ? $presentaciones[$presentacionId]->sigla() : '';
                $cantidades[$sigla] = ($cantidades[$sigla] ?? 0) + $cantidad;

                if ($mecanica !== '' && ! in_array($mecanica, $promociones, true)) {
                    $promociones[] = $mecanica;
                }
            }

            $textos[] = (string) $pedido->numero.' '.Str::limit($pedido->cliente->nombre, self::CLIENTE, '…').': '
                .self::cantidades($cantidades)
                .($promociones === [] ? '' : ' ('.implode(' y ', $promociones).')');
        }

        return $textos;
    }

    /**
     * "1 CJ12 + 2 UN".
     *
     * @param array<int|string, int> $porSigla
     */
    private static function cantidades(array $porSigla): string
    {
        $partes = [];

        foreach ($porSigla as $sigla => $cantidad) {
            $partes[] = number_format($cantidad).' '.$sigla;
        }

        return implode(' + ', $partes);
    }
}
