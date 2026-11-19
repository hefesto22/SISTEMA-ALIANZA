<?php

declare(strict_types=1);

namespace App\Domain\Promociones;

use App\Models\Promocion;

/**
 * Lo que devuelve el motor: el descuento de cada línea (por clave), las
 * bonificaciones que se agregan solas (promociones gratis) y los avisos de
 * promociones con descuento a las que les falta el producto en el pedido.
 */
final readonly class ResultadoPromociones
{
    /**
     * @param array<int|string, DescuentoLinea> $descuentos
     * @param list<FaltantePromocion> $faltantes
     * @param list<RegaloPromocion> $regalos
     */
    public function __construct(
        public array $descuentos = [],
        public array $faltantes = [],
        public array $regalos = [],
    ) {}

    public function descuentoDe(int|string $clave): ?DescuentoLinea
    {
        return $this->descuentos[$clave] ?? null;
    }

    public function total(): float
    {
        return round(array_sum(array_map(fn (DescuentoLinea $d): float => $d->monto, $this->descuentos)), 2);
    }

    /**
     * Descuento total por promoción, para mostrar "PROMO-0001: −L 25.00".
     *
     * @return array<int, array{promocion: Promocion, monto: float}>
     */
    public function porPromocion(): array
    {
        $grupos = [];

        foreach ($this->descuentos as $descuento) {
            $id = $descuento->promocion->id;
            $grupos[$id] ??= ['promocion' => $descuento->promocion, 'monto' => 0.0];
            $grupos[$id]['monto'] = round($grupos[$id]['monto'] + $descuento->monto, 2);
        }

        return $grupos;
    }
}
