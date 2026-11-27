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
use Illuminate\Support\Str;

/**
 * Hoja de alisto: lo que hay que sacar de la bodega para unos pedidos
 * pendientes (los de un día, o los marcados), para ir apartándolo antes de
 * validar. Por bodega, un renglón por producto con el total a sacar de cada
 * presentación (con los regalos de las promociones), lo que dice el sistema
 * que hay, si no alcanza y a qué pedido va cada parte. En papel lleva una
 * casilla "Apartado" y un espacio "Faltó": lo que faltó se anota al validar
 * (ValidarVarios, $faltaron) y a los últimos pedidos les sale menos.
 *
 * Los pedidos van del más viejo al más nuevo, como al validar.
 *
 * @phpstan-type HojaVenta array{presentacionId: int, sigla: string, cantidad: int, cobra: int}
 * @phpstan-type HojaRenglon array{productoId: int, codigo: string, descripcion: string, sale: list<HojaVenta>, regalo: string|null, hay: string, falta: string|null, para: list<string>}
 * @phpstan-type HojaBodega array{bodegaId: int, bodega: string, pedidos: list<array{numero: string, cliente: string, fecha: string, total: float}>, total: float, desde: string, hasta: string, productos: list<HojaRenglon>}
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
            ->with(['producto.presentaciones.unidadMedida', 'presentacion.unidadMedida'])
            ->whereIn('pedido_id', $pedidos->modelKeys())
            ->orderBy('id')
            ->get()
            ->groupBy('pedido_id');

        /** @var array<int, string> $bodegas */
        $bodegas = Bodega::query()->whereKey($pedidos->pluck('bodega_id')->unique()->values()->all())->pluck('nombre', 'id')->all();
        $hoja = [];

        foreach ($pedidos->groupBy('bodega_id') as $bodegaId => $deLaBodega) {
            /** @var array<int, array{producto: Producto, pide: array<int, int>, cobra: array<int, int>, regalo: array<int, int>, para: array<int, list<array{0: int, 1: int, 2: bool}>>}> $productos */
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
                    $productos[$id] ??= ['producto' => $item->producto, 'pide' => [], 'cobra' => [], 'regalo' => [], 'para' => []];
                    $productos[$id]['pide'][$item->presentacion_id] = ($productos[$id]['pide'][$item->presentacion_id] ?? 0) + $cantidad;

                    if ($item->bonificacion) {
                        $productos[$id]['regalo'][$item->presentacion_id] = ($productos[$id]['regalo'][$item->presentacion_id] ?? 0) + $cantidad;
                    } else {
                        $productos[$id]['cobra'][$item->presentacion_id] = ($productos[$id]['cobra'][$item->presentacion_id] ?? 0) + $cantidad;
                    }

                    $productos[$id]['para'][$pedido->id][] = [$item->presentacion_id, $cantidad, $item->bonificacion];
                }
            }

            $hay = LoQueAlcanza::existencias((int) $bodegaId, array_keys($productos));
            $porId = $deLaBodega->keyBy('id')->all();
            $renglones = [];

            foreach ($productos as $producto) {
                $renglones[] = self::renglon($producto, $hay, $porId);
            }

            usort($renglones, fn (array $a, array $b): int => [$a['descripcion'], $a['codigo']] <=> [$b['descripcion'], $b['codigo']]);

            $hoja[] = [
                'bodegaId'  => (int) $bodegaId,
                'bodega'    => $bodegas[(int) $bodegaId] ?? '',
                'pedidos'   => $filas,
                'total'     => round((float) $deLaBodega->sum('total'), 2),
                'desde'     => (string) $deLaBodega->min(fn (Pedido $pedido): string => $pedido->fecha->toDateString()),
                'hasta'     => (string) $deLaBodega->max(fn (Pedido $pedido): string => $pedido->fecha->toDateString()),
                'productos' => $renglones,
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
                    if ($sale['cobra'] > 0) {
                        $opciones[$bodega['bodegaId'].':'.$sale['presentacionId']] = (count($hoja) > 1 ? $bodega['bodega'].' · ' : '')
                            .$producto['descripcion'].' · '.$sale['sigla'].' (salen '.number_format($sale['cobra']).')';
                    }
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
                    if ($sale['cobra'] > 0) {
                        $cobrado[$bodega['bodegaId']][$sale['presentacionId']] = $sale['cobra'];
                    }
                }
            }
        }

        return $cobrado;
    }

    /**
     * @param array{producto: Producto, pide: array<int, int>, cobra: array<int, int>, regalo: array<int, int>, para: array<int, list<array{0: int, 1: int, 2: bool}>>} $producto
     * @param array<int, int> $hay [presentacion_id => lo que hay en la bodega]
     * @param array<array-key, Pedido> $pedidos
     *
     * @return HojaRenglon
     */
    private static function renglon(array $producto, array $hay, array $pedidos): array
    {
        $modelo = $producto['producto'];
        $presentaciones = $modelo->presentaciones->sortByDesc('contenido')->values();
        $sale = [];
        $regalo = [];
        $hayTexto = [];
        $falta = [];

        foreach ($presentaciones as $presentacion) {
            $cantidad = $producto['pide'][$presentacion->id] ?? 0;

            if ($cantidad > 0) {
                $sale[] = [
                    'presentacionId' => $presentacion->id,
                    'sigla'          => $presentacion->sigla(),
                    'cantidad'       => $cantidad,
                    'cobra'          => $producto['cobra'][$presentacion->id] ?? 0,
                ];
            }

            if (($producto['regalo'][$presentacion->id] ?? 0) > 0) {
                $regalo[] = number_format($producto['regalo'][$presentacion->id]).' '.$presentacion->sigla();
            }

            if (($hay[$presentacion->id] ?? 0) !== 0) {
                $hayTexto[] = number_format($hay[$presentacion->id] ?? 0).' '.$presentacion->sigla();
            }
        }

        $contenido = $presentaciones->mapWithKeys(fn (Presentacion $presentacion): array => [$presentacion->id => $presentacion->contenido])->all();

        foreach (Disponible::faltantes($producto['pide'], $hay, $contenido, $modelo->presentacionBase()->id) as $presentacionId => $cuantas) {
            $presentacion = $presentaciones->firstWhere('id', $presentacionId);

            if ($presentacion instanceof Presentacion) {
                $falta[] = ($cuantas === 1 ? 'falta ' : 'faltan ').$presentacion->enPalabras($cuantas);
            }
        }

        return [
            'productoId'  => $modelo->id,
            'codigo'      => $modelo->codigo,
            'descripcion' => $modelo->descripcion,
            'sale'        => $sale,
            'regalo'      => $regalo === [] ? null : implode(' y ', $regalo),
            'hay'         => $hayTexto === [] ? 'nada' : implode(' · ', $hayTexto),
            'falta'       => $falta === [] ? null : implode(', ', $falta),
            'para'        => self::para($producto['para'], $presentaciones->keyBy('id')->all(), $pedidos),
        ];
    }

    /**
     * "COP-004211 SUPERMERCADO BETESDA: 1 CJ12 + 2 UN (1 UN de regalo)",
     * uno por pedido, del más viejo al más nuevo.
     *
     * @param array<int, list<array{0: int, 1: int, 2: bool}>> $para [pedido => [[presentación, cantidad, regalo]]]
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

            $cobra = [];
            $regala = [];

            foreach ($para[$id] as [$presentacionId, $cantidad, $esRegalo]) {
                $sigla = isset($presentaciones[$presentacionId]) ? $presentaciones[$presentacionId]->sigla() : '';

                if ($esRegalo) {
                    $regala[$sigla] = ($regala[$sigla] ?? 0) + $cantidad;
                } else {
                    $cobra[$sigla] = ($cobra[$sigla] ?? 0) + $cantidad;
                }
            }

            $texto = self::cantidades($cobra);
            $deRegalo = self::cantidades($regala);

            $textos[] = (string) $pedido->numero.' '.Str::limit($pedido->cliente->nombre, self::CLIENTE, '…').': '
                .match (true) {
                    $texto === ''    => $deRegalo.' de regalo',
                    $deRegalo === '' => $texto,
                    default          => "{$texto} ({$deRegalo} de regalo)",
                };
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
