<?php

declare(strict_types=1);

namespace App\Domain\Promociones;

use App\Models\Promocion;

/**
 * Descuento (sin ISV) que una promoción le deja a una línea del pedido.
 */
final readonly class DescuentoLinea
{
    public function __construct(
        public int|string $clave,
        public Promocion $promocion,
        public float $monto,
        public int $unidades,
    ) {}
}
