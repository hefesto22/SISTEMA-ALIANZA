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
use App\Models\Devolucion;
use App\Models\PedidoItem;

/**
 * Lo que muestran la carga en pantalla y sus hojas para imprimir
 * (App\Http\Controllers\ImprimirCargaController):
 *
 *  - Hoja de carga: lo que se sube al camión, producto por producto (lo
 *    vendido y los regalos de las promociones), sumado de las facturas
 *    vigentes que lleva.
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
     *     camion: string|null,
     *     liquidada: bool,
     *     facturas: list<array{id: int, numero: string, corto: string, cliente: string, municipio: string|null, vendio: string, pedido: string, pedido_id: int, total: float, vigente: bool, salio: bool, estado: EntregaFactura, nota: string|null}>,
     *     cuantas: int,
     *     total: float,
     *     productos: list<array{codigo: string, descripcion: string, sigla: string, vendido: int, regalo: int, total: int}>,
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
            'renglones.factura.reemplazo',
            'pagos.factura',
            'devoluciones.factura',
            'devoluciones.cliente',
        ]);

        $facturas = [];
        $cuantas = 0;

        /** @var CargaFactura $renglon */
        foreach ($carga->renglones as $renglon) {
            $factura = $renglon->factura;
            $pedido = $factura->pedido;
            $vigente = ! $factura->estaAnulada();
            $salio = self::salio($renglon);
            $cuantas += $salio ? 1 : 0;

            $facturas[] = [
                'id'        => $factura->id,
                'numero'    => $factura->numero,
                'corto'     => self::corto($factura->numero),
                'cliente'   => $factura->cliente_nombre,
                'municipio' => $pedido->cliente->municipio?->nombre,
                'vendio'    => $pedido->vendedor->name,
                'pedido'    => (string) $pedido->numero,
                'pedido_id' => $pedido->id,
                'total'     => round($factura->total, 2),
                'vigente'   => $vigente,
                'salio'     => $salio,
                'estado'    => $renglon->estado,
                'nota'      => self::nota($renglon),
            ];
        }

        return [
            'carga'          => $carga,
            'numero'         => $carga->numero(),
            'fecha'          => mb_ucfirst(ResumenDelDia::fechaLarga($carga->fecha->toDateString())),
            'bodega'         => $carga->bodega->nombre,
            'sale'           => $carga->repartidor->name,
            'camion'         => $carga->camion,
            'liquidada'      => ! $carga->enRuta(),
            'facturas'       => $facturas,
            'cuantas'        => $cuantas,
            'total'          => round($carga->total_carga, 2),
            'productos'      => self::productos($carga),
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
     * Lo que va en el camión por presentación: de los pedidos de las
     * facturas que siguen vigentes (las que el camión entrega), lo que se
     * despachó de cada línea; los regalos aparte.
     *
     * @return list<array{codigo: string, descripcion: string, sigla: string, vendido: int, regalo: int, total: int}>
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
            ->get();

        /** @var array<int, array{codigo: string, descripcion: string, sigla: string, contenido: int, vendido: int, regalo: int, total: int}> $porPresentacion */
        $porPresentacion = [];

        /** @var PedidoItem $item */
        foreach ($items as $item) {
            $cantidad = $item->cantidadFacturable();

            if ($cantidad <= 0) {
                continue;
            }

            $fila = $porPresentacion[$item->presentacion_id] ?? [
                'codigo'      => $item->producto->codigo,
                'descripcion' => $item->producto->descripcion,
                'sigla'       => $item->presentacion->sigla(),
                'contenido'   => $item->presentacion->contenido,
                'vendido'     => 0,
                'regalo'      => 0,
                'total'       => 0,
            ];

            $fila[$item->bonificacion ? 'regalo' : 'vendido'] += $cantidad;
            $fila['total'] += $cantidad;
            $porPresentacion[$item->presentacion_id] = $fila;
        }

        usort($porPresentacion, fn (array $a, array $b): int => [$a['descripcion'], -$a['contenido']] <=> [$b['descripcion'], -$b['contenido']]);

        return array_map(fn (array $fila): array => [
            'codigo'      => $fila['codigo'],
            'descripcion' => $fila['descripcion'],
            'sigla'       => $fila['sigla'],
            'vendido'     => $fila['vendido'],
            'regalo'      => $fila['regalo'],
            'total'       => $fila['total'],
        ], $porPresentacion);
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
