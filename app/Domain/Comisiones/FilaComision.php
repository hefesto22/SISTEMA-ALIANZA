<?php

declare(strict_types=1);

namespace App\Domain\Comisiones;

/**
 * Lo de un vendedor en un grupo de comisión en un periodo.
 *
 *  venta       = facturas del periodo (y las que se facturaron con fecha de
 *                un periodo ya cerrado, después del cierre), sin ISV y ya
 *                con descuentos.
 *  anulado     = facturas que ya se le pagaron y después se anularon (al
 *                corregir el pedido): se descuentan aquí.
 *  base        = venta − anulado: lo de este periodo.
 *
 * La meta y los escalones son del MES. Con quincenal, la segunda quincena
 * suma lo de la primera:
 *
 *  baseAntes   = lo que ya llevaba el mes (la primera quincena).
 *  pagadoAntes = lo que ya se le pagó en el mes (el adelanto de la primera).
 *  baseMes     = baseAntes + base: con esto va el escalón y el %.
 *  comisionMes = el % del escalón sobre toda la venta del mes.
 *  comision    = comisionMes − pagadoAntes: lo que se paga en este periodo.
 *
 * Ej. 0.5 % desde 0 y 2 % desde 100,000: vende 10,000 del 1 al 14 → se le
 * adelantan 50; vende 98,000 del 15 a fin de mes → el mes va en 108,000 →
 * 2 % = 2,160 → se le pagan 2,110.
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
        public readonly float $baseAntes = 0.0,
        public readonly float $pagadoAntes = 0.0,
    ) {}

    public static function calcular(
        int $grupoId,
        string $grupo,
        float $venta,
        float $anulado,
        int $facturas,
        Escala $escala,
        bool $propia,
        float $baseAntes = 0.0,
        float $pagadoAntes = 0.0,
    ): self {
        $base = round($venta - $anulado, 2);
        $baseMes = round($base + $baseAntes, 2);

        return new self(
            grupoId: $grupoId,
            grupo: $grupo,
            venta: round($venta, 2),
            anulado: round($anulado, 2),
            facturas: $facturas,
            escala: $escala,
            propia: $propia,
            base: $base,
            porcentaje: $escala->porcentaje($baseMes),
            comision: round($escala->comision($baseMes) - $pagadoAntes, 2),
            meta: $escala->meta(),
            baseAntes: round($baseAntes, 2),
            pagadoAntes: round($pagadoAntes, 2),
        );
    }

    /**
     * Lo que lleva vendido en el mes (con lo de la quincena anterior).
     */
    public function baseMes(): float
    {
        return round($this->base + $this->baseAntes, 2);
    }

    /**
     * Lo que gana en todo el mes (lo de este periodo más lo ya pagado).
     */
    public function comisionMes(): float
    {
        return round($this->comision + $this->pagadoAntes, 2);
    }

    /**
     * Si el mes ya traía algo de antes (una quincena anterior).
     */
    public function sumaElMes(): bool
    {
        return $this->baseAntes != 0.0 || $this->pagadoAntes != 0.0;
    }

    /**
     * El escalón que sigue, si no ha llegado al último.
     *
     * @return array{desde: float, porcentaje: float}|null
     */
    public function siguiente(): ?array
    {
        return $this->escala->siguiente($this->baseMes());
    }

    /**
     * Cuánto le falta vender en el mes para el escalón que sigue.
     */
    public function faltante(): ?float
    {
        $siguiente = $this->siguiente();

        return $siguiente === null ? null : round($siguiente['desde'] - max(0.0, $this->baseMes()), 2);
    }

    /**
     * Cuánto lleva de la meta del mes (0.85 = 85 %; puede pasar de 1).
     */
    public function avance(): ?float
    {
        return $this->meta === null ? null : max(0.0, $this->baseMes()) / $this->meta;
    }

    public function llegoALaMeta(): bool
    {
        return $this->meta !== null && $this->baseMes() + 0.000001 >= $this->meta;
    }
}
