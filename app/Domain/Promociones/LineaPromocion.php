<?php

declare(strict_types=1);

namespace App\Domain\Promociones;

use App\Enums\UnidadVenta;

/**
 * Una línea del pedido tal como la ve el motor de promociones.
 * `precio` es por unidad de venta (unidad o caja) y sin ISV.
 */
final readonly class LineaPromocion
{
    public function __construct(
        public int|string $clave,
        public int $productoId,
        public int $unidadesPorCaja,
        public UnidadVenta $unidad,
        public int $cantidad,
        public float $precio,
    ) {}

    /**
     * Cuántas unidades sueltas trae una unidad de venta (1 o las de la caja).
     */
    public function factor(): int
    {
        return MotorPromociones::factor($this->unidad, $this->unidadesPorCaja);
    }

    public function unidades(): int
    {
        return max(0, $this->cantidad) * $this->factor();
    }
}
