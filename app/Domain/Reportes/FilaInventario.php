<?php

declare(strict_types=1);

namespace App\Domain\Reportes;

/**
 * Un renglón del inventario valorizado: un producto, o el resumen de una
 * bodega, de un proveedor o del total. Montos en lempiras, sin ISV salvo
 * $invertidoConIsv.
 *
 *  invertido   = existencia × costo promedio (lo que costó lo que hay)
 *  valorVenta  = existencia × precio de venta (lo que daría si se vende todo)
 *  ganancia    = valorVenta − invertido
 */
final readonly class FilaInventario
{
    public function __construct(
        public string $nombre,
        public ?string $codigo,
        public ?string $detalle,
        public int $productos,
        public int $unidades,
        public ?string $existencia,
        public ?float $costoUnidad,
        public ?float $precioUnidad,
        public float $invertido,
        public float $invertidoConIsv,
        public float $valorVenta,
        public bool $precioFijo = false,
    ) {}

    public function ganancia(): float
    {
        return round($this->valorVenta - $this->invertido, 2);
    }

    /**
     * Ganancia ÷ valor a precio de venta (0.16 = 16 %); null si no vale nada.
     */
    public function margen(): ?float
    {
        return $this->valorVenta > 0 ? round($this->ganancia() / $this->valorVenta, 4) : null;
    }
}
