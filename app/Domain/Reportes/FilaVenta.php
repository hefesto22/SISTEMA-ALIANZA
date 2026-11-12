<?php

declare(strict_types=1);

namespace App\Domain\Reportes;

/**
 * Un renglón del reporte de ventas: un día, una bodega, un vendedor, un
 * producto, un cliente o el total. Montos en lempiras.
 *
 *  venta     = lo cobrado sin ISV, ya con los descuentos de promociones
 *  total     = lo facturado, con ISV
 *  costo     = lo que costó lo que salió (costo promedio del día que salió)
 *  ganancia  = venta − costo
 */
final readonly class FilaVenta
{
    public function __construct(
        public string $nombre,
        public ?string $codigo,
        public ?string $detalle,
        public int $pedidos,
        public int $clientes,
        public int $unidades,
        public ?string $cantidad,
        public float $venta,
        public float $descuento,
        public float $isv,
        public float $total,
        public float $costo,
    ) {}

    public function ganancia(): float
    {
        return round($this->venta - $this->costo, 2);
    }

    /**
     * Ganancia ÷ venta (0.25 = 25 %); null si no hubo venta.
     */
    public function margen(): ?float
    {
        return $this->venta > 0 ? round($this->ganancia() / $this->venta, 4) : null;
    }
}
