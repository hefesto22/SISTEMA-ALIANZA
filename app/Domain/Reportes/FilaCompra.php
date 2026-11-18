<?php

declare(strict_types=1);

namespace App\Domain\Reportes;

/**
 * Un renglón del reporte de compras: un proveedor, una bodega, una
 * categoría, un producto en una presentación, una compra o el total.
 * Montos en lempiras, como vienen en las facturas del proveedor.
 *
 *  subtotal = sin ISV
 *  total    = con ISV
 *  cantidad = cuántas de la presentación (10 CJ48); unidades, en la base (480 UN)
 */
final readonly class FilaCompra
{
    public function __construct(
        public string $nombre,
        public ?string $codigo = null,
        public ?string $detalle = null,
        public ?string $fecha = null,
        public ?string $bodega = null,
        public ?string $proveedor = null,
        public ?string $presentacion = null,
        public int $compras = 0,
        public int $productos = 0,
        public int $cantidad = 0,
        public int $unidades = 0,
        public float $subtotal = 0.0,
        public float $isv = 0.0,
        public float $total = 0.0,
    ) {}

    /**
     * Lo que salió en promedio cada una de la presentación, sin ISV.
     */
    public function costoPromedio(): ?float
    {
        return $this->cantidad > 0 ? round($this->subtotal / $this->cantidad, 2) : null;
    }

    /**
     * Qué parte del total es (0.25 = 25 %); null si el total es 0.
     */
    public function parteDe(float $total): ?float
    {
        return $total > 0 ? round($this->total / $total, 4) : null;
    }
}
