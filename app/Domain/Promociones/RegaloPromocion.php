<?php

declare(strict_types=1);

namespace App\Domain\Promociones;

use App\Models\Presentacion;
use App\Models\Promocion;

/**
 * Bonificación que le toca al pedido por una promoción gratis: cuántas
 * del regalo, en su presentación. RecalcularPedido la pone en su propia
 * línea del pedido, con 100 % de descuento.
 */
final readonly class RegaloPromocion
{
    public function __construct(
        public Promocion $promocion,
        public Presentacion $presentacion,
        public int $cantidad,
    ) {}

    /**
     * "Regalo: 1 caja de 18 de JABÓN X (PROMO-0003)".
     */
    public function texto(): string
    {
        return "Regalo: {$this->presentacion->enPalabras($this->cantidad)} de {$this->promocion->productoBeneficio->descripcion} ({$this->promocion->codigo}).";
    }
}
