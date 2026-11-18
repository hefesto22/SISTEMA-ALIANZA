<?php

declare(strict_types=1);

namespace App\Domain\Comisiones;

/**
 * Las comisiones de un vendedor en el periodo: una fila por grupo y su
 * venta total (con y sin grupo, sin ISV).
 */
final class ResumenVendedor
{
    /**
     * @param list<FilaComision> $filas
     */
    public function __construct(
        public readonly int $id,
        public readonly string $nombre,
        public readonly ?string $codigo,
        public readonly ?string $bodega,
        public readonly array $filas,
        public readonly float $ventaTotal,
        public readonly int $facturas,
    ) {}

    public function comision(): float
    {
        return round(array_sum(array_map(fn (FilaComision $fila): float => $fila->comision, $this->filas)), 2);
    }

    /**
     * Lo vendido de los grupos que pagan comisión (ya con lo anulado descontado).
     */
    public function ventaEnGrupos(): float
    {
        return round(array_sum(array_map(fn (FilaComision $fila): float => $fila->base, $this->filas)), 2);
    }

    /**
     * "V01 · JUAN PÉREZ".
     */
    public function etiqueta(): string
    {
        return filled($this->codigo) ? $this->codigo.' · '.$this->nombre : $this->nombre;
    }

    public function fila(int $grupoId): ?FilaComision
    {
        foreach ($this->filas as $fila) {
            if ($fila->grupoId === $grupoId) {
                return $fila;
            }
        }

        return null;
    }
}
