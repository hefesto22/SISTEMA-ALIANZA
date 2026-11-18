<?php

declare(strict_types=1);

namespace App\Domain\Comisiones;

/**
 * Lo de un vendedor en un grupo de comisión en un periodo.
 *
 *  venta    = facturas del periodo (y las que se facturaron con fecha de un
 *             periodo ya cerrado, después del cierre), sin ISV y ya con
 *             descuentos.
 *  anulado  = facturas que ya se le pagaron y después se anularon (al
 *             corregir el pedido): se descuentan aquí.
 *  base     = venta − anulado: sobre esto va el escalón y el %.
 */
final class FilaComision
{
    public function __construct(
        public readonly int $grupoId,
        public readonly string $grupo,
        public readonly float $venta,
        public readonly float $anulado,
        public readonly int $facturas,
        public readonly Escala $escala,
        public readonly bool $propia,
        public readonly float $base,
        public readonly float $porcentaje,
        public readonly float $comision,
        public readonly ?float $meta,
    ) {}

    public static function calcular(int $grupoId, string $grupo, float $venta, float $anulado, int $facturas, Escala $escala, bool $propia): self
    {
        $base = round($venta - $anulado, 2);

        return new self(
            grupoId: $grupoId,
            grupo: $grupo,
            venta: round($venta, 2),
            anulado: round($anulado, 2),
            facturas: $facturas,
            escala: $escala,
            propia: $propia,
            base: $base,
            porcentaje: $escala->porcentaje($base),
            comision: $escala->comision($base),
            meta: $escala->meta(),
        );
    }

    /**
     * El escalón que sigue, si no ha llegado al último.
     *
     * @return array{desde: float, porcentaje: float}|null
     */
    public function siguiente(): ?array
    {
        return $this->escala->siguiente($this->base);
    }

    /**
     * Cuánto le falta vender para el escalón que sigue.
     */
    public function faltante(): ?float
    {
        $siguiente = $this->siguiente();

        return $siguiente === null ? null : round($siguiente['desde'] - max(0.0, $this->base), 2);
    }

    /**
     * Cuánto lleva de la meta (0.85 = 85 %; puede pasar de 1).
     */
    public function avance(): ?float
    {
        return $this->meta === null ? null : max(0.0, $this->base) / $this->meta;
    }

    public function llegoALaMeta(): bool
    {
        return $this->meta !== null && $this->base + 0.000001 >= $this->meta;
    }
}
