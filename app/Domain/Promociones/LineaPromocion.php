<?php

declare(strict_types=1);

namespace App\Domain\Promociones;

/**
 * Una línea del pedido tal como la ve el motor de promociones.
 * `contenido` son las unidades base que trae su presentación (UN = 1,
 * CJ de 12 = 12) y `precio` es por esa presentación, sin ISV.
 */
final readonly class LineaPromocion
{
    public function __construct(
        public int|string $clave,
        public int $productoId,
        public int $contenido,
        public int $cantidad,
        public float $precio,
    ) {}

    /**
     * Cuántas unidades base trae una de la línea.
     */
    public function factor(): int
    {
        return max(1, $this->contenido);
    }

    public function unidades(): int
    {
        return max(0, $this->cantidad) * $this->factor();
    }
}
