<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Inventario\Disponible;
use App\Enums\EstadoPedido;
use App\Models\Configuracion;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\User;

/**
 * "Validar las N" de Pedidos: valida de un solo muchos pendientes, como si
 * bodega abriera el Validar de cada uno y le diera Validar sin cambiar nada.
 * Van del más viejo al más nuevo (por fecha y en el orden en que se
 * levantaron): si algo no alcanza para todos, le sale primero al que pidió
 * primero. Cada uno se guarda solo; si uno no se puede, los demás siguen.
 *
 *  - Sale todo lo pedido (si las sueltas no alcanzan, se abren solas las
 *    cajas que sobran) y queda Por imprimir.
 *  - Con "controlar existencias" y algo que no alcanza: con $conLoQueHay
 *    sale lo que hay (lo mismo que trae de entrada el modal de Validar,
 *    LoQueAlcanza) y va en "incompletos"; sin $conLoQueHay, o si no hay
 *    nada de lo que pidió, queda pendiente para revisarlo uno por uno.
 *
 * Antes de validar, queNoAlcanza() dice qué productos no alcanzan para
 * todos con lo que hay en la bodega. Quién puede validar cuáles lo revisa
 * quien lo llama (PedidoPolicy::validar).
 */
final class ValidarVarios
{
    public function __construct(
        private readonly ValidarDespacho $validar,
    ) {}

    /**
     * @param list<int> $ids los que no estén pendientes se saltan
     */
    public function ejecutar(array $ids, User $usuario, bool $conLoQueHay = true): PedidosValidados
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

        foreach ($pedidos as $pedido) {
            $numero = $pedido->numero ?? (string) $pedido->id;

            try {
                $validados[] = $this->validar->ejecutar($pedido, [], $usuario, abrirSolas: true);

                continue;
            } catch (ReglaNegocioException $e) {
                if (! $controla || ! $conLoQueHay) {
                    $pendientes[$numero] = $e->getMessage();

                    continue;
                }
            }

            $sale = LoQueAlcanza::delPedido($pedido);

            if (array_sum($sale) === 0) {
                $pendientes[$numero] = 'No hay nada de lo que pidió.';

                continue;
            }

            try {
                $validados[] = $this->validar->ejecutar($pedido, $sale, $usuario, abrirSolas: true);
                $incompletos[] = $numero;
            } catch (ReglaNegocioException $e) {
                $pendientes[$numero] = $e->getMessage();
            }
        }

        return new PedidosValidados($validados, $incompletos, $pendientes);
    }

    /**
     * Para avisar antes de validar: lo que no alcanza para todos esos
     * pendientes con lo que hay hoy en su bodega, "JABON X (faltan 3
     * unidades)". Cada caja sale de sus cajas y las sueltas, de las sueltas
     * y de abrir las que sobran (Disponible::faltantes). Los regalos cuentan
     * lo que dice el pedido (al validar se vuelven a calcular).
     *
     * @param list<int> $ids
     *
     * @return list<string>
     */
    public static function queNoAlcanza(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $filas = PedidoItem::query()
            ->join('pedidos', 'pedidos.id', '=', 'pedido_items.pedido_id')
            ->whereIn('pedidos.id', $ids)
            ->where('pedidos.estado', EstadoPedido::Pendiente)
            ->groupBy('pedidos.bodega_id', 'pedido_items.producto_id', 'pedido_items.presentacion_id')
            ->selectRaw('pedidos.bodega_id, pedido_items.producto_id, pedido_items.presentacion_id, SUM(pedido_items.cantidad) AS cantidad')
            ->toBase()
            ->get();

        /** @var array<int, array<int, array<int, int>>> $pide [bodega => [producto => [presentación => cantidad]]] */
        $pide = [];

        foreach ($filas as $fila) {
            $pide[(int) $fila->bodega_id][(int) $fila->producto_id][(int) $fila->presentacion_id] = (int) $fila->cantidad;
        }

        $productos = Producto::query()
            ->with('presentaciones.unidadMedida')
            ->whereKey($filas->pluck('producto_id')->unique()->values()->all())
            ->orderBy('descripcion')
            ->get();

        $faltan = [];

        foreach ($pide as $bodegaId => $deLaBodega) {
            $hay = LoQueAlcanza::existencias($bodegaId, array_keys($deLaBodega));

            foreach ($productos as $producto) {
                if (! isset($deLaBodega[$producto->id])) {
                    continue;
                }

                $contenido = $producto->presentaciones->mapWithKeys(fn (Presentacion $presentacion): array => [$presentacion->id => $presentacion->contenido])->all();
                $partes = [];

                foreach (Disponible::faltantes($deLaBodega[$producto->id], $hay, $contenido, $producto->presentacionBase()->id) as $presentacionId => $falta) {
                    $presentacion = $producto->presentaciones->firstWhere('id', $presentacionId);

                    if ($presentacion instanceof Presentacion) {
                        $partes[] = ($falta === 1 ? 'falta ' : 'faltan ').$presentacion->enPalabras($falta);
                    }
                }

                if ($partes !== []) {
                    $faltan[] = $producto->descripcion.' ('.implode(', ', $partes).')';
                }
            }
        }

        return $faltan;
    }
}
