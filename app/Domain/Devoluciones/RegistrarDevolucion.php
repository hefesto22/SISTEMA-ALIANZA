<?php

declare(strict_types=1);

namespace App\Domain\Devoluciones;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Inventario\MoverExistencia;
use App\Domain\Precios\CalculadoraPrecio;
use App\Enums\EstadoPedido;
use App\Enums\TipoDevolucion;
use App\Enums\TipoMovimiento;
use App\Models\Cai;
use App\Models\Carga;
use App\Models\Configuracion;
use App\Models\Devolucion;
use App\Models\DevolucionItem;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Presentacion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * El cliente devuelve producto de un pedido ya entregado (impreso). Por cada
 * línea se dice cuántas regresan buenas y cuántas dañadas: en la presentación
 * de la línea (1 CJ12) y, de una línea en caja, también unidades sueltas en
 * la presentación base (3 UN; se cobran y cuestan por unidad). Las unidades
 * no "recuerdan" de qué caja salieron.
 *
 *  - Las buenas regresan a la bodega del pedido (kárdex "Devolución").
 *  - Las dañadas no regresan: quedan marcadas en la devolución, con su
 *    costo, para el reporte de producto dañado (y reclamarlas al proveedor).
 *  - Cambio: se le debe lo mismo (buenas y dañadas); lo que pagó no cambia.
 *    Queda Por entregar (Reposiciones) y sale de la bodega cuando se sube a
 *    una carga. Si al cliente ya se le dio en ruta con producto del pedido de
 *    otro cliente ($seLeDebeA), lo que queda por entregar es para ese otro.
 *  - Crédito: se le abona lo que pagó por eso (precio neto de la línea, con
 *    su descuento, más ISV si es gravado; lo de un regalo vale 0). Con
 *    "Notas de crédito" encendido en Configuración se emite la nota con el
 *    CAI tipo 07 de la bodega y los datos de la factura; apagado, queda como
 *    crédito interno.
 *
 * No se devuelve más de lo que salió menos lo ya devuelto (en unidades). Un
 * pedido con devoluciones ya no se corrige (CorregirPedido).
 */
final class RegistrarDevolucion
{
    /** Hasta cuántos días atrás se busca el pedido del otro cliente al que se le queda debiendo. */
    public const DIAS_PARA_DEBERLE = 15;

    public function __construct(
        private readonly MoverExistencia $mover,
        private readonly Reposiciones $reposiciones,
    ) {}

    /**
     * @param array<int, array{buenas?: int|string|null, danadas?: int|string|null, buenas_un?: int|string|null, danadas_un?: int|string|null}> $lineas [id de la línea del pedido => cuántas regresan buenas y dañadas en su presentación y, de una caja, sueltas]
     * @param Pedido|null $seLeDebeA en un cambio: el pedido de otro cliente con cuyo producto ya se le dio el cambio (a ese otro se le queda debiendo)
     */
    public function ejecutar(Pedido $pedido, TipoDevolucion $tipo, array $lineas, User $usuario, ?string $motivo = null, ?Pedido $seLeDebeA = null): Devolucion
    {
        return DB::transaction(function () use ($pedido, $tipo, $lineas, $usuario, $motivo, $seLeDebeA): Devolucion {
            $pedido = Pedido::query()->with('cliente')->lockForUpdate()->findOrFail($pedido->id);

            if ($pedido->estado !== EstadoPedido::Impreso) {
                throw new ReglaNegocioException("El pedido {$pedido->numero} está {$pedido->estado->getLabel()}: solo se recibe devolución de un pedido ya entregado (impreso). Antes de imprimir, se corrige.");
            }

            $seLeDebeA = $this->seLeDebeA($pedido, $tipo, $seLeDebeA);

            $filas = $this->filas($pedido, $lineas);
            $factura = $pedido->factura()->first();
            $conNota = $tipo === TipoDevolucion::Credito && Configuracion::actual()->notas_credito;

            if ($conNota && $factura === null) {
                throw new ReglaNegocioException("El pedido {$pedido->numero} no tiene factura vigente: la nota de crédito necesita los datos de la factura.");
            }

            $motivo = trim((string) $motivo);

            $devolucion = Devolucion::query()->create([
                'pedido_id'  => $pedido->id,
                'factura_id' => $factura?->id,
                // Si la factura va en un camión, se descuenta en la liquidación de esa carga.
                'carga_id'   => $factura !== null ? Carga::enRutaDe($factura->id)?->id : null,
                'bodega_id'  => $pedido->bodega_id,
                'cliente_id' => $pedido->cliente_id,
                'user_id'    => $usuario->id,
                'fecha'      => today(),
                'tipo'       => $tipo,
                'motivo'     => $motivo === '' ? null : mb_strtoupper($motivo),
            ]);

            $this->renglones($devolucion, $filas);
            $this->bodega($devolucion, $pedido, $filas, $usuario);

            if ($tipo === TipoDevolucion::Cambio) {
                $this->reposiciones->crear($devolucion, $pedido, $seLeDebeA);
            }

            if ($conNota) {
                $this->nota($devolucion, $pedido);
            }

            return $devolucion->load('items');
        });
    }

    /**
     * Pedidos a cuyo cliente se le puede quedar debiendo el cambio de
     * $pedido: los entregados (impresos) de la misma bodega de los últimos
     * días, sin el mismo pedido, del más nuevo al más viejo.
     *
     * @return Builder<Pedido>
     */
    public static function paraDeberle(Pedido $pedido): Builder
    {
        return Pedido::query()
            ->where('bodega_id', $pedido->bodega_id)
            ->where('estado', EstadoPedido::Impreso)
            ->whereKeyNot($pedido->id)
            ->whereDate('fecha', '>=', today()->subDays(self::DIAS_PARA_DEBERLE))
            ->orderByDesc('fecha')
            ->orderByDesc('id');
    }

    /**
     * Cuánto se ha devuelto ya de cada línea del pedido, en unidades (la
     * presentación base): 1 CJ12 y 3 UN de una línea en caja son 15.
     *
     * @return array<int, int>
     */
    public static function devueltas(Pedido $pedido): array
    {
        /** @var array<int, int> $devueltas */
        $devueltas = DevolucionItem::query()
            ->join('presentaciones', 'presentaciones.id', '=', 'devolucion_items.presentacion_id')
            ->whereIn('devolucion_items.devolucion_id', Devolucion::query()->where('pedido_id', $pedido->id)->select('id'))
            ->whereNotNull('devolucion_items.pedido_item_id')
            ->groupBy('devolucion_items.pedido_item_id')
            ->selectRaw('devolucion_items.pedido_item_id, SUM(devolucion_items.cantidad * GREATEST(presentaciones.contenido, 1)) AS cantidad')
            ->pluck('cantidad', 'pedido_item_id')
            ->map(fn (mixed $cantidad): int => (int) $cantidad)
            ->all();

        return $devueltas;
    }

    /**
     * Cuántas unidades (presentación base) quedan por devolver de la línea:
     * lo que salió menos lo ya devuelto.
     *
     * @param array<int, int> $devueltas RegistrarDevolucion::devueltas()
     */
    public static function puede(PedidoItem $item, array $devueltas): int
    {
        return $item->cantidadFacturable() * max(1, $item->presentacion->contenido) - ($devueltas[$item->id] ?? 0);
    }

    /**
     * En qué se cuentan las sueltas de la línea: la presentación base del
     * producto. Null si la línea ya es en unidades o el producto no tiene base.
     */
    public static function sueltas(PedidoItem $item): ?Presentacion
    {
        if ($item->presentacion->contenido <= 1) {
            return null;
        }

        $base = $item->producto->presentaciones->first(fn (Presentacion $presentacion): bool => $presentacion->es_base);

        return $base instanceof Presentacion && $base->id !== $item->presentacion_id ? $base : null;
    }

    /**
     * Lo que regresa de cada línea, revisado: [línea, presentación, buenas,
     * dañadas]. De una línea en caja, las cajas van en su presentación y las
     * sueltas en la base; de una en unidades, todo en la suya. Todo junto, en
     * unidades, no pasa de lo que salió menos lo ya devuelto. En orden de
     * producto y presentación (así bloquean igual que un despacho).
     *
     * @param array<int, array{buenas?: int|string|null, danadas?: int|string|null, buenas_un?: int|string|null, danadas_un?: int|string|null}> $lineas
     *
     * @return list<array{0: PedidoItem, 1: Presentacion, 2: int, 3: int}>
     */
    private function filas(Pedido $pedido, array $lineas): array
    {
        $items = $pedido->items()->with(['producto.presentaciones.unidadMedida', 'presentacion.unidadMedida'])->get()->keyBy('id');
        $devueltas = self::devueltas($pedido);
        $filas = [];

        foreach ($lineas as $id => $linea) {
            $buenas = max(0, (int) ($linea['buenas'] ?? 0));
            $danadas = max(0, (int) ($linea['danadas'] ?? 0));
            $buenasSueltas = max(0, (int) ($linea['buenas_un'] ?? 0));
            $danadasSueltas = max(0, (int) ($linea['danadas_un'] ?? 0));

            if ($buenas + $danadas + $buenasSueltas + $danadasSueltas === 0) {
                continue;
            }

            $item = $items->get((int) $id);

            if (! $item instanceof PedidoItem) {
                throw new ReglaNegocioException("Uno de los productos de la devolución no es del pedido {$pedido->numero}.");
            }

            $contenido = max(1, $item->presentacion->contenido);
            $sueltas = self::sueltas($item);

            // Una línea en unidades: lo suelto es lo mismo.
            if ($contenido === 1) {
                [$buenas, $danadas, $buenasSueltas, $danadasSueltas] = [$buenas + $buenasSueltas, $danadas + $danadasSueltas, 0, 0];
            } elseif ($buenasSueltas + $danadasSueltas > 0 && $sueltas === null) {
                throw new ReglaNegocioException("{$item->producto->descripcion} no se maneja en unidades sueltas: devuélvalo en {$item->presentacion->palabra(2)}.");
            }

            $puede = self::puede($item, $devueltas);
            $unidad = $sueltas ?? $item->presentacion;

            if (($buenas + $danadas) * $contenido + $buenasSueltas + $danadasSueltas > $puede) {
                $devuelto = $devueltas[$item->id] ?? 0;

                throw new ReglaNegocioException(sprintf(
                    'De %s se puede devolver hasta %s (salió %s%s).',
                    $item->producto->descripcion,
                    $contenido > 1 && $sueltas === null ? $item->presentacion->enPalabras(intdiv(max(0, $puede), $contenido)) : $unidad->enPalabras(max(0, $puede)),
                    $item->presentacion->enPalabras($item->cantidadFacturable()),
                    $devuelto > 0 ? ' y ya devolvió '.($contenido > 1 && $sueltas === null ? $item->presentacion->enPalabras(intdiv($devuelto, $contenido)) : $unidad->enPalabras($devuelto)) : '',
                ));
            }

            if ($buenas + $danadas > 0) {
                $filas[] = [$item, $item->presentacion, $buenas, $danadas];
            }

            if ($sueltas !== null && $buenasSueltas + $danadasSueltas > 0) {
                $filas[] = [$item, $sueltas, $buenasSueltas, $danadasSueltas];
            }
        }

        if ($filas === []) {
            throw new ReglaNegocioException('Escriba cuántas regresan (buenas o dañadas) de al menos un producto.');
        }

        usort($filas, fn (array $a, array $b): int => [$a[0]->producto_id, $a[1]->id] <=> [$b[0]->producto_id, $b[1]->id]);

        return $filas;
    }

    /**
     * Un renglón por línea, presentación y estado (buenas / dañadas), con lo
     * que se cobró por una: lo de la línea, y por unidad si regresó suelta.
     *
     * @param list<array{0: PedidoItem, 1: Presentacion, 2: int, 3: int}> $filas
     */
    private function renglones(Devolucion $devolucion, array $filas): void
    {
        $tasa = CalculadoraPrecio::tasaIsv();
        $exento = 0.0;
        $gravado = 0.0;
        $isv = 0.0;

        foreach ($filas as [$item, $presentacion, $buenas, $danadas]) {
            $cobradas = $item->cantidadFacturable();
            $neto = $cobradas > 0 ? round(($item->subtotal - $item->descuento) / $cobradas * $presentacion->contenido / max(1, $item->presentacion->contenido), 4) : 0.0;

            foreach ([[$buenas, false], [$danadas, true]] as [$cantidad, $danado]) {
                if ($cantidad === 0) {
                    continue;
                }

                $subtotal = round($neto * $cantidad, 2);
                $impuesto = $item->gravado ? round($subtotal * $tasa, 2) : 0.0;

                $devolucion->items()->create([
                    'pedido_item_id'  => $item->id,
                    'producto_id'     => $item->producto_id,
                    'presentacion_id' => $presentacion->id,
                    'cantidad'        => $cantidad,
                    'danado'          => $danado,
                    'precio'          => $neto,
                    'gravado'         => $item->gravado,
                    'subtotal'        => $subtotal,
                    'isv'             => $impuesto,
                    'total'           => round($subtotal + $impuesto, 2),
                    'costo'           => round($presentacion->costo, 4),
                ]);

                if ($item->gravado) {
                    $gravado += $subtotal;
                } else {
                    $exento += $subtotal;
                }

                $isv += $impuesto;
            }
        }

        $devolucion->forceFill([
            'subtotal'        => round($exento + $gravado, 2),
            'importe_exento'  => round($exento, 2),
            'importe_gravado' => round($gravado, 2),
            'isv'             => round($isv, 2),
            'total'           => round($exento + $gravado + $isv, 2),
        ])->save();
    }

    /**
     * El pedido a cuyo cliente se le queda debiendo el cambio, revisado:
     * solo en un cambio, de la misma bodega, entregado y que no sea el mismo.
     */
    private function seLeDebeA(Pedido $pedido, TipoDevolucion $tipo, ?Pedido $seLeDebeA): ?Pedido
    {
        if ($seLeDebeA === null || $tipo !== TipoDevolucion::Cambio) {
            return null;
        }

        $otro = Pedido::query()->with('cliente')->find($seLeDebeA->id);

        if ($otro === null || $otro->id === $pedido->id) {
            throw new ReglaNegocioException('Escoja el pedido del otro cliente: al que se le quedó debiendo lo que se le dio en el cambio.');
        }

        if ($otro->bodega_id !== $pedido->bodega_id || $otro->estado !== EstadoPedido::Impreso) {
            throw new ReglaNegocioException("El pedido {$otro->numero} no sirve: debe ser de la misma bodega y ya entregado (impreso).");
        }

        return $otro;
    }

    /**
     * Lo bueno entra a la bodega. Lo de un cambio sale cuando se sube a una
     * carga (Reposiciones).
     *
     * @param list<array{0: PedidoItem, 1: Presentacion, 2: int, 3: int}> $filas
     */
    private function bodega(Devolucion $devolucion, Pedido $pedido, array $filas, User $usuario): void
    {
        $nota = "DEVOLUCIÓN {$devolucion->numero} · PEDIDO {$pedido->numero}";

        foreach ($filas as [, $presentacion, $buenas]) {
            if ($buenas > 0) {
                $this->mover->ejecutar($pedido->bodega_id, $presentacion, $buenas, TipoMovimiento::Devolucion, $usuario, pedidoId: $pedido->id, nota: $nota);
            }
        }
    }

    /**
     * Emite la nota de crédito con el CAI de notas de crédito (tipo 07) de la
     * bodega: el número siguiente y los datos como se imprimen.
     */
    private function nota(Devolucion $devolucion, Pedido $pedido): void
    {
        $cai = Cai::paraBodega($pedido->bodega_id, bloquear: true, tipo: Cai::NOTA_CREDITO);

        if ($cai === null) {
            throw new ReglaNegocioException('No hay CAI vigente de notas de crédito (tipo 07) para esta bodega: agréguelo en Administración > CAI, o apague las notas de crédito en Configuración para dejar el crédito interno.');
        }

        $correlativo = $cai->siguiente;

        if (! $cai->enRango($correlativo)) {
            throw new ReglaNegocioException("El CAI de notas de crédito {$cai->cai} ya no tiene números en su rango autorizado.");
        }

        $cai->siguiente = $correlativo + 1;
        $cai->save();

        $devolucion->forceFill([
            'nota_numero'    => $cai->numero($correlativo),
            'cai_id'         => $cai->id,
            'correlativo'    => $correlativo,
            'cai'            => $cai->cai,
            'rango_desde'    => $cai->numero($cai->rango_desde),
            'rango_hasta'    => $cai->numero($cai->rango_hasta),
            'fecha_limite'   => $cai->fecha_limite,
            'cliente_nombre' => $pedido->cliente->nombre,
            'cliente_rtn'    => $pedido->cliente->rtn,
        ])->save();
    }
}
