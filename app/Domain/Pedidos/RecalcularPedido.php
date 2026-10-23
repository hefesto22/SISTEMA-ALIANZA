<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Domain\Precios\CalculadoraPrecio;
use App\Domain\Promociones\LineaPromocion;
use App\Domain\Promociones\MotorPromociones;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Promocion;
use Illuminate\Database\Eloquent\Collection;

/**
 * Recalcula líneas y totales de un pedido.
 *
 * Con $preciosVigentes = true toma el precio que tiene HOY cada producto
 * (al levantar la preventa y al validar en bodega). Las cantidades que
 * se cobran son las despachadas si bodega ya validó; si no, las pedidas.
 *
 * Promociones: se aplican las vigentes hoy. Con $respetarPromocionesDelPedido
 * (al despachar) también cuentan las que ya tenía el pedido aunque se hayan
 * desactivado después: al cliente se le respeta lo que le ofrecieron.
 *
 * Por línea: subtotal (bruto) − descuento = neto; el ISV va sobre el neto.
 */
final class RecalcularPedido
{
    public function __construct(private readonly MotorPromociones $motor) {}

    public function ejecutar(Pedido $pedido, bool $preciosVigentes = true, bool $respetarPromocionesDelPedido = false): Pedido
    {
        $tasaIsv = CalculadoraPrecio::tasaIsv();
        $pedido->load('items.producto');

        /** @var Collection<int, PedidoItem> $items */
        $items = $pedido->items;

        foreach ($items as $item) {
            if ($preciosVigentes) {
                $item->precio = $item->producto->precioPara($item->unidad);
                $item->gravado = $item->producto->gravado;
            }
        }

        $lineas = [];

        foreach ($items as $item) {
            $lineas[] = new LineaPromocion(
                clave: $item->id,
                productoId: $item->producto_id,
                unidadesPorCaja: $item->producto->unidades_por_caja,
                unidad: $item->unidad,
                cantidad: $item->cantidadFacturable(),
                precio: $item->precio,
            );
        }

        $resultado = $this->motor->calcular($lineas, $this->promociones($items, $respetarPromocionesDelPedido));

        $subtotal = 0.0;
        $descuento = 0.0;
        $isv = 0.0;

        foreach ($items as $item) {
            $aplicado = $resultado->descuentoDe($item->id);

            $item->subtotal = round($item->precio * $item->cantidadFacturable(), 2);
            $item->descuento = min($item->subtotal, $aplicado->monto ?? 0.0);
            $item->promocion_id = $aplicado?->promocion->id;

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

        return $pedido;
    }

    /**
     * Vigentes hoy y, si se pide, también las que ya traía el pedido.
     *
     * @param Collection<int, PedidoItem> $items
     *
     * @return Collection<int, Promocion>
     */
    private function promociones(Collection $items, bool $respetarPromocionesDelPedido): Collection
    {
        $promociones = Promocion::query()->vigentes()->with('condiciones')->get();

        $delPedido = $items->pluck('promocion_id')->filter()->unique()->values()->all();

        if ($respetarPromocionesDelPedido && $delPedido !== []) {
            $promociones = $promociones->merge(Promocion::query()->with('condiciones')->whereKey($delPedido)->get());
        }

        return $promociones->sortBy('id')->values();
    }
}
