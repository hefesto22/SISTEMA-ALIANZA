<?php

declare(strict_types=1);

namespace App\Domain\Facturacion;

use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Producto;
use LogicException;

/**
 * Un renglón del detalle de la factura impresa: un producto con lo que va
 * en caja (la presentación que trae varias: CJ12, FD6…) en la columna CJ
 * y lo que va suelto (la base) en la columna UN. Si van las dos salen en
 * el mismo renglón; si va solo una, la otra columna queda vacía.
 *
 * Aparte, en su propio renglón: el regalo de una promoción (bonificación,
 * sin cobro), debajo de lo que lo ganó, y lo que bodega no despachó
 * (tachado en la hoja).
 */
final class RenglonFactura
{
    private ?PedidoItem $caja = null;

    private ?PedidoItem $suelta = null;

    private function __construct(private readonly bool $validado, PedidoItem $item)
    {
        $this->poner($item);
    }

    /**
     * Los renglones del pedido en el orden en que se pidió; cada regalo,
     * debajo del último producto de su promoción.
     *
     * @return list<self>
     */
    public static function delPedido(Pedido $pedido, bool $validado): array
    {
        $renglones = [];
        $regalos = [];

        foreach ($pedido->items->sortBy('id') as $item) {
            if ($item->bonificacion) {
                $regalos[] = new self($validado, $item);

                continue;
            }

            foreach ($renglones as $renglon) {
                if ($renglon->junta($item)) {
                    continue 2;
                }
            }

            $renglones[] = new self($validado, $item);
        }

        /** @var array<int, list<self>> $debajo */
        $debajo = [];

        foreach ($regalos as $regalo) {
            $debajo[self::quienLoGano($renglones, $regalo)][] = $regalo;
        }

        $todos = [];

        foreach ($renglones as $i => $renglon) {
            $todos[] = $renglon;

            foreach ($debajo[$i] ?? [] as $regalo) {
                $todos[] = $regalo;
            }
        }

        foreach ($debajo[-1] ?? [] as $regalo) {
            $todos[] = $regalo;
        }

        return $todos;
    }

    /**
     * La línea que manda en el renglón (la de caja si hay): de ella salen
     * el producto, si es gravado y la promoción.
     */
    public function item(): PedidoItem
    {
        return $this->caja ?? $this->suelta ?? throw new LogicException('Renglón sin líneas.');
    }

    public function producto(): Producto
    {
        return $this->item()->producto;
    }

    /**
     * Cuántas en caja (columna CJ); null si no va en caja.
     */
    public function cajas(): ?int
    {
        return $this->caja === null ? null : $this->cantidad($this->caja);
    }

    /**
     * Cuántas sueltas (columna UN); null si no van sueltas.
     */
    public function unidades(): ?int
    {
        return $this->suelta === null ? null : $this->cantidad($this->suelta);
    }

    /**
     * La medida de la caja si no es CJ ("FD", "SC"), para ponerla junto a la cantidad.
     */
    public function medidaCaja(): ?string
    {
        return self::medidaSiNoEs($this->caja, 'CJ');
    }

    /**
     * La medida de lo suelto si no es UN ("BT"), para ponerla junto a la cantidad.
     */
    public function medidaSuelta(): ?string
    {
        return self::medidaSiNoEs($this->suelta, 'UN');
    }

    /**
     * Lo que sale cada unidad, sin ISV (columna Precio Unit.): lo del
     * renglón entre las unidades que lleva, contando lo de las cajas (1
     * CJ48 + 2 UN son 50 unidades). Un solo precio aunque vayan cajas y
     * sueltas. Si no sale nada (tachado), el de la línea entre lo que trae.
     */
    public function precioUnidad(): float
    {
        $unidades = 0;
        $monto = 0.0;

        foreach ($this->items() as $item) {
            $cantidad = $this->cantidad($item);
            $unidades += $cantidad * max(1, $item->contenido);
            $monto += $cantidad * $item->precio;
        }

        if ($unidades === 0) {
            $item = $this->item();

            return round($item->precio / max(1, $item->contenido), 2);
        }

        return round($monto / $unidades, 2);
    }

    public function descuento(): float
    {
        return round(array_sum(array_map(fn (PedidoItem $item): float => $item->descuento, $this->items())), 2);
    }

    public function isv(): float
    {
        return round(array_sum(array_map(fn (PedidoItem $item): float => $item->isv, $this->items())), 2);
    }

    /**
     * Con ISV, como en la columna Total.
     */
    public function total(): float
    {
        return round(array_sum(array_map(fn (PedidoItem $item): float => $item->total, $this->items())), 2);
    }

    public function gravado(): bool
    {
        return $this->item()->gravado;
    }

    /**
     * El regalo de una promoción gratis (no se cobra).
     */
    public function esRegalo(): bool
    {
        return $this->item()->bonificacion;
    }

    /**
     * Bodega validó y no sale nada de esa línea.
     */
    public function noSale(): bool
    {
        return $this->validado && $this->cantidad($this->item()) === 0;
    }

    /**
     * Lo que va debajo de la descripción: la promoción y, ya validado, lo
     * que no va completo o va de más.
     *
     * @return list<string>
     */
    public function notas(): array
    {
        $notas = [];

        foreach ($this->items() as $item) {
            $diferencia = $this->validado ? ($item->faltaEnPalabras() ?? $item->deMasEnPalabras()) : null;

            if ($diferencia !== null) {
                $notas[] = $diferencia;
            }
        }

        $promocion = $this->item()->promocion;

        if ($promocion !== null) {
            $notas[] = $this->esRegalo()
                ? "Gratis por la promoción {$promocion->codigo}"
                : "Promoción {$promocion->codigo}";
        }

        return $notas;
    }

    /**
     * @return list<PedidoItem>
     */
    private function items(): array
    {
        return array_values(array_filter([$this->caja, $this->suelta]));
    }

    /**
     * Si la línea es del mismo producto y su columna está libre, entra a
     * este renglón. Los regalos y lo que no sale no se juntan con nada.
     */
    private function junta(PedidoItem $item): bool
    {
        $mio = $this->item();

        if ($mio->producto_id !== $item->producto_id || $mio->bonificacion || $this->cantidad($mio) === 0 || $this->cantidad($item) === 0) {
            return false;
        }

        if (self::esCaja($item) ? $this->caja !== null : $this->suelta !== null) {
            return false;
        }

        $this->poner($item);

        return true;
    }

    private function poner(PedidoItem $item): void
    {
        if (self::esCaja($item)) {
            $this->caja = $item;
        } else {
            $this->suelta = $item;
        }
    }

    /**
     * Lo que sale en la hoja: lo despachado si bodega ya validó; si no, lo pedido.
     */
    private function cantidad(PedidoItem $item): int
    {
        return $this->validado ? $item->cantidadFacturable() : $item->cantidad;
    }

    private static function esCaja(PedidoItem $item): bool
    {
        return $item->contenido > 1;
    }

    private static function medidaSiNoEs(?PedidoItem $item, string $codigo): ?string
    {
        $medida = $item?->presentacion->unidadMedida->codigo;

        return $medida === null || $medida === $codigo ? null : $medida;
    }

    /**
     * El último renglón con un producto de la promoción del regalo (-1: al final).
     *
     * @param list<self> $renglones
     */
    private static function quienLoGano(array $renglones, self $regalo): int
    {
        $productos = $regalo->item()->promocion?->condiciones->pluck('producto_id')->all() ?? [];
        $ultimo = -1;

        foreach ($renglones as $i => $renglon) {
            if (in_array($renglon->item()->producto_id, $productos, true)) {
                $ultimo = $i;
            }
        }

        return $ultimo;
    }
}
