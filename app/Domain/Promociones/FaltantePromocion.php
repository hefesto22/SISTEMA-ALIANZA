<?php

declare(strict_types=1);

namespace App\Domain\Promociones;

use App\Models\Presentacion;
use App\Models\Promocion;

/**
 * El cliente ya cumple la condición de una promoción con descuento (a mitad
 * o con un %), pero en el pedido falta (todo o parte de) el producto que se
 * descuenta. Sirve para avisarle al vendedor. La cantidad es en la
 * presentación del regalo. (Las gratis no avisan: se agregan solas.)
 */
final readonly class FaltantePromocion
{
    public function __construct(
        public Promocion $promocion,
        public int $cantidad,
        public Presentacion $presentacion,
    ) {}

    public function texto(): string
    {
        $cantidad = $this->presentacion->enPalabras($this->cantidad);
        $producto = $this->promocion->productoBeneficio->descripcion;

        if ($this->promocion->regaloEnCondicion()) {
            return "Agregue {$cantidad} más de {$producto} y le sale {$this->promocion->beneficioTexto()} ({$this->promocion->codigo}).";
        }

        $toca = $this->cantidad === 1 ? 'Le toca' : 'Le tocan';

        return "{$toca} {$cantidad} de {$producto} {$this->promocion->beneficioTexto()}: agréguelo al pedido ({$this->promocion->codigo}).";
    }
}
