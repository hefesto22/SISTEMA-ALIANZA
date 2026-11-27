<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\EstadoPedido;
use App\Models\Configuracion;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * "Validar los N" de Pedidos: valida de un solo muchos pendientes, como si
 * bodega abriera el Validar de cada uno y le diera Validar sin cambiar nada.
 * Van del más viejo al más nuevo (por fecha y en el orden en que se
 * levantaron): si algo no alcanza para todos, le sale primero al que pidió
 * primero. Cada uno se guarda solo; si uno no se puede, los demás siguen.
 *
 *  - Sale todo lo pedido (si las sueltas no alcanzan, se abren solas las
 *    cajas que sobran) y queda Por imprimir.
 *  - Lo que faltó al alistar con la hoja (HojaDeAlisto, $faltaron): de esa
 *    presentación se reparte lo que sí se encontró (lo que se cobra menos lo
 *    que faltó), del más viejo al más nuevo; a los últimos les sale menos y
 *    van en "incompletos". Si a uno no le queda nada, queda pendiente.
 *  - Con "controlar existencias" y algo que no alcanza en el sistema: con
 *    $conLoQueHay sale lo que hay (lo mismo que trae de entrada el modal de
 *    Validar, LoQueAlcanza) y va en "incompletos"; sin $conLoQueHay, o si no
 *    hay nada de lo que pidió, queda pendiente para revisarlo uno por uno.
 *
 * Antes de validar, queNoAlcanza() dice qué productos no alcanzan para
 * todos con lo que hay en la bodega. Quién puede validar cuáles lo revisa
 * quien lo llama (PedidoPolicy::validar).
 *
 * @phpstan-import-type HojaBodega from HojaDeAlisto
 */
final class ValidarVarios
{
    public function __construct(
        private readonly ValidarDespacho $validar,
    ) {}

    /**
     * @param list<int> $ids los que no estén pendientes se saltan
     * @param array<int, array<int, int>> $faltaron [bodega_id => [presentacion_id => cuántas no se encontraron]]
     */
    public function ejecutar(array $ids, User $usuario, bool $conLoQueHay = true, array $faltaron = []): PedidosValidados
    {
        $controla = Configuracion::actual()->controlar_existencias;
        $validados = [];
        $incompletos = [];
        $pendientes = [];

        $pedidos = Pedido::query()
            ->whereKey($ids)
            ->where('estado', EstadoPedido::Pendiente)
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();

        $quedan = self::seEncontro($pedidos, $faltaron);

        foreach ($pedidos as $pedido) {
            $numero = $pedido->numero ?? (string) $pedido->id;
            $bodega = $pedido->bodega_id;
            $lineas = $pedido->items()->where('bonificacion', false)->orderBy('id')->get();
            $pidio = [];

            foreach ($lineas as $linea) {
                $pidio[$linea->id] = $linea->cantidadFacturable();
            }

            $sale = isset($quedan[$bodega]) ? self::repartir($lineas, $quedan[$bodega]) : $pidio;

            if (array_sum($sale) === 0) {
                $pendientes[$numero] = 'No se encontró nada de lo que pidió.';

                continue;
            }

            try {
                $validados[] = $this->validar->ejecutar($pedido, $sale === $pidio ? [] : $sale, $usuario);

                if ($sale !== $pidio) {
                    $incompletos[] = $numero;
                }

                if (isset($quedan[$bodega])) {
                    $quedan[$bodega] = self::restar($quedan[$bodega], $lineas, $sale);
                }

                continue;
            } catch (ReglaNegocioException $e) {
                if (! $controla || ! $conLoQueHay) {
                    $pendientes[$numero] = $e->getMessage();

                    continue;
                }
            }

            $alcanza = LoQueAlcanza::delPedido($pedido, $sale);

            if (array_sum($alcanza) === 0) {
                $pendientes[$numero] = 'No hay nada de lo que pidió.';

                continue;
            }

            try {
                $validados[] = $this->validar->ejecutar($pedido, $alcanza, $usuario);
                $incompletos[] = $numero;

                if (isset($quedan[$bodega])) {
                    $quedan[$bodega] = self::restar($quedan[$bodega], $lineas, $alcanza);
                }
            } catch (ReglaNegocioException $e) {
                $pendientes[$numero] = $e->getMessage();
            }
        }

        return new PedidosValidados($validados, $incompletos, $pendientes);
    }

    /**
     * Para avisar antes de validar: lo que no alcanza para todos esos
     * pendientes con lo que hay hoy en su bodega, "JABON X (faltan 3
     * unidades)" (HojaDeAlisto: cada caja sale de sus cajas y las sueltas,
     * de las sueltas y de abrir las que sobran). Los regalos cuentan lo que
     * dice el pedido (al validar se vuelven a calcular).
     *
     * @param list<int> $ids
     *
     * @return list<string>
     */
    public static function queNoAlcanza(array $ids): array
    {
        return self::noAlcanzanEn(HojaDeAlisto::datos($ids));
    }

    /**
     * Lo mismo, de una hoja ya armada.
     *
     * @param list<HojaBodega> $hoja
     *
     * @return list<string>
     */
    public static function noAlcanzanEn(array $hoja): array
    {
        $faltan = [];

        foreach ($hoja as $bodega) {
            $faltan = [...$faltan, ...$bodega['faltan']];
        }

        return $faltan;
    }

    /**
     * Lo que se encontró de cada presentación que faltó, para repartirlo:
     * [bodega => [presentación => lo que se cobra en esos pedidos − lo que faltó]].
     *
     * @param Collection<int, Pedido> $pedidos
     * @param array<int, array<int, int>> $faltaron
     *
     * @return array<int, array<int, int>>
     */
    private static function seEncontro(Collection $pedidos, array $faltaron): array
    {
        $quedan = [];

        foreach ($faltaron as $bodega => $presentaciones) {
            $deLaBodega = $pedidos->where('bodega_id', $bodega)->modelKeys();

            foreach ($presentaciones as $presentacion => $falta) {
                if ($falta <= 0 || $deLaBodega === []) {
                    continue;
                }

                $cobra = 0;

                foreach (PedidoItem::query()->whereIn('pedido_id', $deLaBodega)->where('bonificacion', false)->where('presentacion_id', $presentacion)->get() as $item) {
                    $cobra += $item->cantidadFacturable();
                }

                $quedan[$bodega][$presentacion] = max(0, $cobra - $falta);
            }
        }

        return $quedan;
    }

    /**
     * Lo que sale de cada línea con lo que queda: lo pedido, menos en las
     * presentaciones que se acabaron.
     *
     * @param Collection<int, PedidoItem> $lineas
     * @param array<int, int> $quedan [presentación => lo que queda por repartir]
     *
     * @return array<int, int> [línea => sale]
     */
    private static function repartir(Collection $lineas, array $quedan): array
    {
        $sale = [];

        foreach ($lineas as $linea) {
            $pide = $linea->cantidadFacturable();

            if (array_key_exists($linea->presentacion_id, $quedan)) {
                $pide = min($pide, $quedan[$linea->presentacion_id]);
                $quedan[$linea->presentacion_id] -= $pide;
            }

            $sale[$linea->id] = $pide;
        }

        return $sale;
    }

    /**
     * Lo que queda después de que un pedido se llevó lo suyo.
     *
     * @param array<int, int> $quedan
     * @param Collection<int, PedidoItem> $lineas
     * @param array<int, int> $sale [línea => lo que salió]
     *
     * @return array<int, int>
     */
    private static function restar(array $quedan, Collection $lineas, array $sale): array
    {
        foreach ($lineas as $linea) {
            if (array_key_exists($linea->presentacion_id, $quedan)) {
                $quedan[$linea->presentacion_id] = max(0, $quedan[$linea->presentacion_id] - ($sale[$linea->id] ?? 0));
            }
        }

        return $quedan;
    }
}
