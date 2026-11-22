<?php

declare(strict_types=1);

namespace App\Domain\Promociones;

use App\Models\Presentacion;
use App\Models\Promocion;

/**
 * Bonificación que le toca al pedido por una promoción gratis: cuántas
 * del regalo, en su presentación. RecalcularPedido la pone en su propia
 * línea del pedido, con 100 % de descuento.
 *
 * Con tope (plan del proveedor): $ganadas es lo que le tocaba y $cantidad
 * lo que se le da (lo que quedaba de lo autorizado). Con $cantidad = 0 la
 * promoción está agotada (ResultadoPromociones::$agotadas).
 */
final readonly class RegaloPromocion
{
    public function __construct(
        public Promocion $promocion,
        public Presentacion $presentacion,
        public int $cantidad,
        public int $ganadas = 0,
    ) {}

    /**
     * Le tocaba más de lo que quedaba autorizado.
     */
    public function recortado(): bool
    {
        return $this->ganadas > $this->cantidad;
    }

    /**
     * "Regalo: 1 caja de 18 de JABÓN X (PROMO-0003)."; recortado: "…: le
     * tocaban 3, solo quedaban 2 de las autorizadas."; agotada: "PROMO-0010
     * agotada: le tocaban 3 unidades de X, pero ya se regalaron todas las
     * autorizadas."
     */
    public function texto(): string
    {
        $producto = $this->promocion->productoBeneficio->descripcion;

        if ($this->cantidad <= 0) {
            return "{$this->promocion->codigo} agotada: le tocaban {$this->presentacion->enPalabras($this->ganadas)} de {$producto}, pero ya se regalaron todas las autorizadas.";
        }

        $texto = "Regalo: {$this->presentacion->enPalabras($this->cantidad)} de {$producto} ({$this->promocion->codigo})";

        return $this->recortado()
            ? "{$texto}: le tocaban {$this->ganadas}, solo quedaban {$this->cantidad} de las autorizadas."
            : "{$texto}.";
    }
}
