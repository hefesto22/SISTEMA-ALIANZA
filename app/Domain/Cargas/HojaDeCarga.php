<?php

declare(strict_types=1);

namespace App\Domain\Cargas;

use App\Domain\Facturacion\ResumenDelDia;
use App\Domain\Pedidos\AnularNoEntregado;
use App\Enums\EntregaFactura;
use App\Enums\TipoDevolucion;
use App\Models\Carga;
use App\Models\CargaFactura;
use App\Models\CargaPago;
use App\Models\CargaReposicion;
use App\Models\Devolucion;
use App\Models\PedidoItem;
use App\Models\Presentacion;
use App\Models\Producto;

/**
 * Lo que muestran la carga en pantalla y sus hojas para imprimir
 * (App\Http\Controllers\ImprimirCargaController):
 *
 *  - Hoja de carga: lo que se sube al camión, un renglón por producto con
 *    cuántas cajas y cuántas unidades (con los regalos de las
 *    promociones y lo de los cambios), sumado de las facturas y los cambios
 *    que lleva, para marcarlo; y los cambios que lleva, cliente por
 *    cliente, para que el que sale sepa a quién se le da cada uno.
 *  - Hoja de cobro: las facturas, para anotar lo que se cobró en cada una.
 *  - Liquidación: el cuadre que se guardó al liquidar, los cheques, las
 *    transferencias y las devoluciones.
 */
final class HojaDeCarga
{
    /**
     * @return array{
     *     carga: Carga,
     *     numero: string,
     *     fecha: string,
     *     bodega: string,
     *     sale: string,
     *     liquidada: bool,
     *     facturas: list<array{id: int, numero: string, corto: string, cliente: string, municipio: string|null, vendio: string, ruta: string|null, pedido: string, pedido_id: int, total: float, vigente: bool, salio: bool, estado: EntregaFactura, nota: string|null, devoluciones: list<string>}>,
     *     cuantas: int,
     *     total: float,
     *     productos: list<array{codigo: string, descripcion: string, cajas: list<array{cantidad: int, sigla: string}>, unidades: int, suelta: string, regalo: string|null, cambio: string|null}>,
     *     cambios: list<array{id: int, cliente: string, municipio: string|null, ruta: string|null, lleva: list<string>, devolucion: string, factura: string|null, nota: string|null, estado: EntregaFactura}>,
     *     cheques: list<array{factura: string|null, banco: string|null, referencia: string|null, monto: float}>,
     *     transferencias: list<array{factura: string|null, banco: string|null, referencia: string|null, monto: float}>,
     *     devoluciones: list<array{numero: string, factura: string|null, cliente: string, tipo: string, credito: bool, total: float}>,
     *     cuenta: array{salio: float, entregado: float, no_entregado: float, devuelto: float, debe_traer: float, efectivo: float, cheques: float, transferencias: float, trajo: float, diferencia: float, texto: string, color: string}|null,
     *     nota: string|null,
     *     liquido: string|null,
     *     liquidada_el: string|null
     * }
     */
    public static function datos(Carga $carga): array
    {
        $carga->loadMissing([
            'bodega',
            'repartidor',
            'liquidador',
            'renglones.factura.pedido.cliente.municipio',
            'renglones.factura.pedido.vendedor',
            'renglones.factura.pedido.ruta.bodega',
            'renglones.factura.reemplazo',
            'pagos.factura',
            'devoluciones.factura',
            'devoluciones.cliente',
            'cambios.reposicion.cliente.municipio',
            'cambios.reposicion.pedido.ruta',
            'cambios.reposicion.pedido.vendedor',
            'cambios.reposicion.devolucion.factura',
            'cambios.reposicion.devolucion.items.presentacion.unidadMedida',
            'cambios.reposicion.devolucion.items.presentacion.producto',
        ]);

        $facturas = [];
        $cuantas = 0;

        // Las devoluciones que se registraron en la ruta, por factura: "DEV-000012 · Cambio".
        $devueltas = [];

        foreach ($carga->devoluciones->sortBy('id') as $devolucion) {
            if ($devolucion->factura_id !== null) {
                $devueltas[$devolucion->factura_id][] = $devolucion->numero.' · '.$devolucion->tipo->getLabel();
            }
        }

        /** @var CargaFactura $renglon */
        foreach ($carga->renglones as $renglon) {
            $factura = $renglon->factura;
            $pedido = $factura->pedido;
            $vigente = ! $factura->estaAnulada();
            $salio = self::salio($renglon);
            $cuantas += $salio ? 1 : 0;

            $facturas[] = [
                'id'           => $factura->id,
                'numero'       => $factura->numero,
                'corto'        => self::corto($factura->numero),
                'cliente'      => $factura->cliente_nombre,
                'municipio'    => $pedido->cliente->municipio?->nombre,
                'vendio'       => $pedido->vendedor->name,
                'ruta'         => $pedido->ruta?->codigo(),
                'pedido'       => (string) $pedido->numero,
                'pedido_id'    => $pedido->id,
                'total'        => round($factura->total, 2),
                'vigente'      => $vigente,
                'salio'        => $salio,
                'estado'       => $renglon->estado,
                'nota'         => self::nota($renglon),
                'devoluciones' => $devueltas[$factura->id] ?? [],
            ];
        }

        return [
            'carga'          => $carga,
            'numero'         => $carga->numero(),
            'fecha'          => mb_ucfirst(ResumenDelDia::fechaLarga($carga->fecha->toDateString())),
            'bodega'         => $carga->bodega->nombre,
            'sale'           => $carga->repartidor->name,
            'liquidada'      => ! $carga->enRuta(),
            'facturas'       => $facturas,
            'cuantas'        => $cuantas,
            'total'          => round($carga->total_carga, 2),
            'productos'      => self::productos($carga),
            'cambios'        => self::cambios($carga),
            'cheques'        => self::pagos($carga, CargaPago::CHEQUE),
            'transferencias' => self::pagos($carga, CargaPago::TRANSFERENCIA),
            'devoluciones'   => self::devoluciones($carga),
            'cuenta'         => self::cuenta($carga),
            'nota'           => $carga->nota,
            'liquido'        => $carga->liquidador?->name,
            'liquidada_el'   => $carga->liquidada_at?->format('d/m/Y h:i a'),
        ];
    }

    /**
     * "000-001-01-00004106" → "4106".
     */
    public static function corto(string $numero): string
    {
        $correlativo = ltrim(substr($numero, 11), '0');

        return $correlativo === '' ? $numero : $correlativo;
    }

    /**
     * Lo que va en el camión, un renglón por producto como en la factura:
     * cuántas cajas (la presentación que trae varias: CJ12, FD6…; si van
     * de más de un tamaño, cada una con su sigla) y cuántas unidades
     * sueltas (la base), de los pedidos de las facturas que salieron en el
     * camión. Los regalos de las promociones y lo de los cambios que lleva
     * van sumados y se dice cuánto es de cada cosa. Por descripción, para ir
     * marcando en la bodega.
     *
     * @return list<array{codigo: string, descripcion: string, cajas: list<array{cantidad: int, sigla: string}>, unidades: int, suelta: string, regalo: string|null, cambio: string|null}>
     */
    public static function productos(Carga $carga): array
    {
        $pedidos = $carga->renglones
            ->filter(fn (CargaFactura $renglon): bool => self::salio($renglon))
            ->map(fn (CargaFactura $renglon): int => $renglon->factura->pedido_id)
            ->unique()
            ->values()
            ->all();

        $items = PedidoItem::query()
            ->whereIn('pedido_id', $pedidos)
            ->with(['producto', 'presentacion.unidadMedida'])
            ->orderBy('id')
            ->get();

        /** @var list<array{producto: Producto, presentacion: Presentacion, contenido: int, cantidad: int, regalo: bool, cambio: bool}> $renglones */
        $renglones = [];

        /** @var PedidoItem $item */
        foreach ($items as $item) {
            $renglones[] = ['producto' => $item->producto, 'presentacion' => $item->presentacion, 'contenido' => $item->contenido, 'cantidad' => $item->cantidadFacturable(), 'regalo' => $item->bonificacion, 'cambio' => false];
        }

        // Lo de los cambios que se subieron (todos salieron de la bodega en este camión).
        foreach ($carga->cambios as $cambio) {
            foreach ($cambio->reposicion->productos() as $fila) {
                $presentacion = $fila['presentacion'];
                $renglones[] = ['producto' => $presentacion->producto, 'presentacion' => $presentacion, 'contenido' => $presentacion->contenido, 'cantidad' => $fila['cantidad'], 'regalo' => false, 'cambio' => true];
            }
        }

        /** @var array<int, array{codigo: string, descripcion: string, cajas: array<int, array{cantidad: int, sigla: string, contenido: int}>, unidades: int, suelta: string, regalos: array<string, int>, cambios: array<string, int>}> $porProducto */
        $porProducto = [];

        foreach ($renglones as $renglon) {
            $cantidad = $renglon['cantidad'];

            if ($cantidad <= 0) {
                continue;
            }

            $producto = $renglon['producto'];
            $presentacion = $renglon['presentacion'];
            $sigla = $presentacion->sigla();
            $fila = $porProducto[$producto->id] ?? [
                'codigo'      => $producto->codigo,
                'descripcion' => $producto->descripcion,
                'cajas'       => [],
                'unidades'    => 0,
                'suelta'      => 'UN',
                'regalos'     => [],
                'cambios'     => [],
            ];

            // Como en la factura: lo que trae varias va en caja; la base, suelta.
            if ($renglon['contenido'] > 1) {
                $caja = $fila['cajas'][$presentacion->id] ?? ['cantidad' => 0, 'sigla' => $sigla, 'contenido' => $renglon['contenido']];
                $caja['cantidad'] += $cantidad;
                $fila['cajas'][$presentacion->id] = $caja;
            } else {
                $fila['unidades'] += $cantidad;
                $fila['suelta'] = $presentacion->unidadMedida->codigo;
            }

            if ($renglon['regalo']) {
                $fila['regalos'][$sigla] = ($fila['regalos'][$sigla] ?? 0) + $cantidad;
            }

            if ($renglon['cambio']) {
                $fila['cambios'][$sigla] = ($fila['cambios'][$sigla] ?? 0) + $cantidad;
            }

            $porProducto[$producto->id] = $fila;
        }

        usort($porProducto, fn (array $a, array $b): int => [$a['descripcion'], $a['codigo']] <=> [$b['descripcion'], $b['codigo']]);

        return array_map(function (array $fila): array {
            $cajas = $fila['cajas'];
            usort($cajas, fn (array $a, array $b): int => $b['contenido'] <=> $a['contenido']);

            return [
                'codigo'      => $fila['codigo'],
                'descripcion' => $fila['descripcion'],
                'cajas'       => array_map(fn (array $caja): array => ['cantidad' => $caja['cantidad'], 'sigla' => $caja['sigla']], $cajas),
                'unidades'    => $fila['unidades'],
                'suelta'      => $fila['suelta'],
                'regalo'      => self::enTexto($fila['regalos']),
                'cambio'      => self::enTexto($fila['cambios']),
            ];
        }, $porProducto);
    }

    /**
     * "4 UN y 1 CJ12" (null si no hay nada).
     *
     * @param array<string, int> $cantidades [sigla => cantidad]
     */
    private static function enTexto(array $cantidades): ?string
    {
        $partes = [];

        foreach ($cantidades as $sigla => $cantidad) {
            $partes[] = number_format($cantidad).' '.$sigla;
        }

        return $partes === [] ? null : implode(' y ', $partes);
    }

    /**
     * Los cambios que lleva, en el orden en que se subieron: a quién se le
     * dan (su ruta y municipio), qué se le lleva, de qué devolución y
     * factura, y cómo terminó.
     *
     * @return list<array{id: int, cliente: string, municipio: string|null, ruta: string|null, lleva: list<string>, devolucion: string, factura: string|null, nota: string|null, estado: EntregaFactura}>
     */
    private static function cambios(Carga $carga): array
    {
        return array_values($carga->cambios
            ->map(function (CargaReposicion $renglon): array {
                $cambio = $renglon->reposicion;
                $factura = $cambio->devolucion->factura;

                return [
                    'id'        => $cambio->id,
                    'cliente'   => $cambio->cliente->nombre,
                    'municipio' => $cambio->cliente->municipio?->nombre,
                    'ruta'      => $cambio->deRuta(),
                    'lleva'     => array_map(
                        fn (array $fila): string => number_format($fila['cantidad']).' '.$fila['presentacion']->sigla().' '.$fila['presentacion']->producto->descripcion,
                        $cambio->productos(),
                    ),
                    'devolucion' => $cambio->numero.' · '.$cambio->devolucion->numero,
                    'factura'    => $factura !== null ? self::corto($factura->numero) : null,
                    'nota'       => $cambio->nota,
                    'estado'     => $renglon->estado,
                ];
            })
            ->all());
    }

    /**
     * Si la factura salió en el camión para cobrarse: las vigentes y las que
     * se anularon al liquidar porque no se entregaron. La que se anuló al
     * corregir el pedido no cuenta (va la que la sustituye).
     */
    public static function salio(CargaFactura $renglon): bool
    {
        return ! $renglon->factura->estaAnulada() || self::noSeEntrego($renglon);
    }

    private static function noSeEntrego(CargaFactura $renglon): bool
    {
        return $renglon->estado === EntregaFactura::Anulada
            && str_starts_with((string) $renglon->factura->motivo_anulacion, AnularNoEntregado::MOTIVO);
    }

    /**
     * Qué pasó con la factura, en palabras (null si va en ruta o se entregó).
     */
    private static function nota(CargaFactura $renglon): ?string
    {
        $factura = $renglon->factura;

        return match (true) {
            $renglon->estado === EntregaFactura::OtraCarga          => 'No se entregó: sale en otra carga',
            self::noSeEntrego($renglon)                             => 'No se entregó: se anuló',
            $factura->estaAnulada() && $factura->reemplazo !== null => 'Anulada al corregir el pedido: la sustituye '.self::corto($factura->reemplazo->numero),
            $factura->estaAnulada()                                 => 'Anulada al corregir el pedido',
            default                                                 => null,
        };
    }

    /**
     * @return list<array{factura: string|null, banco: string|null, referencia: string|null, monto: float}>
     */
    private static function pagos(Carga $carga, string $tipo): array
    {
        return array_values($carga->pagos
            ->filter(fn (CargaPago $pago): bool => $pago->tipo === $tipo)
            ->map(fn (CargaPago $pago): array => [
                'factura'    => $pago->factura !== null ? self::corto($pago->factura->numero) : null,
                'banco'      => $pago->banco,
                'referencia' => $pago->referencia,
                'monto'      => round($pago->monto, 2),
            ])
            ->all());
    }

    /**
     * @return list<array{numero: string, factura: string|null, cliente: string, tipo: string, credito: bool, total: float}>
     */
    private static function devoluciones(Carga $carga): array
    {
        return array_values($carga->devoluciones
            ->sortBy('id')
            ->map(fn (Devolucion $devolucion): array => [
                'numero'  => (string) $devolucion->numero,
                'factura' => $devolucion->factura !== null ? self::corto($devolucion->factura->numero) : null,
                'cliente' => $devolucion->cliente_nombre ?? $devolucion->cliente->nombre,
                'tipo'    => $devolucion->tipo->getLabel(),
                'credito' => $devolucion->tipo === TipoDevolucion::Credito,
                'total'   => round($devolucion->total, 2),
            ])
            ->all());
    }

    /**
     * Las cifras que se guardaron al liquidar (null si va en ruta).
     *
     * @return array{salio: float, entregado: float, no_entregado: float, devuelto: float, debe_traer: float, efectivo: float, cheques: float, transferencias: float, trajo: float, diferencia: float, texto: string, color: string}|null
     */
    private static function cuenta(Carga $carga): ?array
    {
        if ($carga->enRuta() || $carga->diferencia === null) {
            return null;
        }

        return [
            'salio'          => round($carga->total_carga, 2),
            'entregado'      => (float) $carga->entregado,
            'no_entregado'   => (float) $carga->no_entregado,
            'devuelto'       => (float) $carga->devuelto,
            'debe_traer'     => (float) $carga->debe_traer,
            'efectivo'       => (float) $carga->efectivo,
            'cheques'        => (float) $carga->cheques,
            'transferencias' => (float) $carga->transferencias,
            'trajo'          => (float) $carga->trajo,
            'diferencia'     => $carga->diferencia,
            'texto'          => $carga->diferenciaTexto(),
            'color'          => $carga->colorDiferencia(),
        ];
    }
}
