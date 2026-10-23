<?php

declare(strict_types=1);

namespace App\Domain\Promociones;

use App\Enums\UnidadVenta;
use App\Models\Promocion;

/**
 * El cliente ya cumple la condición, pero en el pedido falta (todo o parte
 * de) el producto de regalo. Sirve para avisarle al vendedor.
 */
final readonly class FaltantePromocion
{
    public function __construct(
        public Promocion $promocion,
        public int $cantidad,
        public UnidadVenta $unidad,
    ) {}

    public function texto(): string
    {
        $cantidad = Promocion::cantidadTexto($this->cantidad, $this->unidad);
        $producto = $this->promocion->productoBeneficio->descripcion;

        if ($this->promocion->esMismoProducto()) {
            return "Agregue {$cantidad} más de {$producto} y le sale {$this->promocion->beneficioTexto()} ({$this->promocion->codigo}).";
        }

        $toca = $this->cantidad === 1 ? 'Le toca' : 'Le tocan';

        return "{$toca} {$cantidad} de {$producto} {$this->promocion->beneficioTexto()}: agréguelo al pedido ({$this->promocion->codigo}).";
    }
}
