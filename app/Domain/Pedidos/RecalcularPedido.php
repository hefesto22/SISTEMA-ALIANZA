<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Domain\Precios\CalculadoraPrecio;
use App\Domain\Promociones\LineaPromocion;
use App\Domain\Promociones\MotorPromociones;
use App\Domain\Promociones\RegaloPromocion;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Promocion;
use Illuminate\Database\Eloquent\Collection;

/**
 * Recalcula líneas y totales de un pedido.
 *
 * Con $preciosVigentes = true toma el precio que tiene HOY la presentación
 * de cada línea (al levantar la preventa y al validar en bodega). Las
 * cantidades que se cobran son las despachadas si bodega ya validó; si no,
 * las pedidas.
 *
 * Con $sinPerdida (al despachar cobrando el precio del pedido) la línea
 * cuyo precio ya quedó por debajo del costo de hoy pasa al precio de hoy:
 * no se vende a pérdida.
 *
 * Promociones: se aplican las vigentes hoy en la bodega del pedido. Con $respetarPromocionesDelPedido
 * (al despachar) también cuentan las que ya tenía el pedido aunque se hayan
 * desactivado después: al cliente se le respeta lo que le ofrecieron.
 *
 * Bonificaciones (promociones gratis): el regalo va en su propia línea
 * (`bonificacion`), con 100 % de descuento, y se acomoda solo con lo que
 * se cobra: en la preventa es lo que pide; al validar o corregir es lo que
 * sale (si ya no se cumple, sale 0 o se quita). Las condiciones solo
 * cuentan las líneas que se cobran.
 *
 * Por línea: subtotal (bruto) − descuento = neto; el ISV va sobre el neto.
 */
final class RecalcularPedido
{
    public function __construct(private readonly MotorPromociones $motor) {}

    public function ejecutar(Pedido $pedido, bool $preciosVigentes = true, bool $respetarPromocionesDelPedido = false, bool $sinPerdida = false): Pedido
    {
        $tasaIsv = CalculadoraPrecio::tasaIsv();
        $pedido->load(['items.producto', 'items.presentacion']);

        /** @var Collection<int, PedidoItem> $items */
        $items = $pedido->items;

        foreach ($items as $item) {
            $costo = round($item->presentacion->costo, 4);
            $quedaBajoCosto = $sinPerdida && round($item->precio, 4) < $costo;

            if ($preciosVigentes || $quedaBajoCosto) {
                $item->precio = $item->presentacion->precio;
                $item->gravado = $item->producto->gravado;
            }
        }

        $lineas = [];

        foreach ($items as $item) {
            if ($item->bonificacion) {
                continue;
            }

            $lineas[] = new LineaPromocion(
                clave: $item->id,
                productoId: $item->producto_id,
                presentacionId: $item->presentacion_id,
                contenido: $item->contenido,
                cantidad: $item->cantidadFacturable(),
                precio: $item->precio,
            );
        }

        $resultado = $this->motor->calcular($lineas, $this->promociones($pedido, $items, $respetarPromocionesDelPedido));
        $items = $this->bonificaciones($pedido, $items, $resultado->regalos);

        $subtotal = 0.0;
        $descuento = 0.0;
        $isv = 0.0;

        foreach ($items as $item) {
            $item->subtotal = round($item->precio * $item->cantidadFacturable(), 2);

            if ($item->bonificacion) {
                $item->descuento = $item->subtotal;
            } else {
                $aplicado = $resultado->descuentoDe($item->id);
                $item->descuento = min($item->subtotal, $aplicado->monto ?? 0.0);
                $item->promocion_id = $aplicado?->promocion->id;
            }

            $neto = round($item->subtotal - $item->descuento, 2);
            $item->isv = $item->gravado ? round($neto * $tasaIsv, 2) : 0.0;
            $item->total = round($neto + $item->isv, 2);
            $item->save();

            $subtotal += $item->subtotal;
            $descuento += $item->descuento;
            $isv += $item->isv;
        }

        $pedido->forceFill([
            'subtotal'  => round($subtotal, 2),
            'descuento' => round($descuento, 2),
            'isv'       => round($isv, 2),
            'total'     => round($subtotal - $descuento + $isv, 2),
        ])->save();

        $pedido->setRelation('items', $items->sortBy('id')->values());

        return $pedido;
    }

    /**
     * Deja una línea de bonificación por cada regalo (promoción y
     * presentación). Sin validar, su cantidad es el regalo; validado (o
     * corrigiendo), lo que sale es el regalo y lo que "pidió" se queda. La
     * que ya no toca se quita; si ya se había ofrecido al validar, queda en 0.
     *
     * @param Collection<int, PedidoItem> $items
     * @param list<RegaloPromocion> $regalos
     *
     * @return Collection<int, PedidoItem> las líneas que quedan (se guardan al sacar los totales)
     */
    private function bonificaciones(Pedido $pedido, Collection $items, array $regalos): Collection
    {
        $validado = $items->contains(fn (PedidoItem $item): bool => ! $item->bonificacion && $item->cantidad_despachada !== null);
        $actuales = $items->filter(fn (PedidoItem $item): bool => $item->bonificacion)->keyBy(fn (PedidoItem $item): string => "{$item->promocion_id}-{$item->presentacion_id}");
        $quedan = $items->reject(fn (PedidoItem $item): bool => $item->bonificacion)->values();
        $tocan = [];

        foreach ($regalos as $regalo) {
            $clave = "{$regalo->promocion->id}-{$regalo->presentacion->id}";
            $item = $actuales->get($clave);

            if (! $item instanceof PedidoItem) {
                $item = new PedidoItem(['producto_id' => $regalo->presentacion->producto_id, 'presentacion_id' => $regalo->presentacion->id, 'cantidad' => 0]);
                $item->pedido_id = $pedido->id;
                $item->promocion_id = $regalo->promocion->id;
                $item->bonificacion = true;
                $item->precio = $regalo->presentacion->precio;
                $item->gravado = $regalo->promocion->productoBeneficio->gravado;
                $item->setRelation('presentacion', $regalo->presentacion);
            }

            if ($validado) {
                $item->cantidad_despachada = $regalo->cantidad;
            } else {
                $item->cantidad = $regalo->cantidad;
            }

            $tocan[$clave] = true;
            $quedan->push($item);
        }

        foreach ($actuales as $clave => $item) {
            if (isset($tocan[$clave])) {
                continue;
            }

            if ($validado && $item->cantidad > 0) {
                $item->cantidad_despachada = 0;
                $quedan->push($item);
            } else {
                $item->delete();
            }
        }

        return $quedan;
    }

    /**
     * Vigentes hoy en la bodega del pedido y, si se pide, también las que ya
     * traía el pedido.
     *
     * @param Collection<int, PedidoItem> $items
     *
     * @return Collection<int, Promocion>
     */
    private function promociones(Pedido $pedido, Collection $items, bool $respetarPromocionesDelPedido): Collection
    {
        $promociones = Promocion::query()->vigentes()->paraBodega($pedido->bodega_id)->with(Promocion::PARA_CALCULAR)->get();

        $delPedido = $items->pluck('promocion_id')->filter()->unique()->values()->all();

        if ($respetarPromocionesDelPedido && $delPedido !== []) {
            $promociones = $promociones->merge(Promocion::query()->with(Promocion::PARA_CALCULAR)->whereKey($delPedido)->get());
        }

        return $promociones->sortBy('id')->values();
    }
}
