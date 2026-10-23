<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Vigente: es la factura que vale del pedido.
 * Anulada: se corrigió el pedido después de imprimirla; la hoja impresa se
 *          retira y se marca ANULADA (Art. 41, Acuerdo 481-2017).
 */
enum EstadoFactura: string implements HasColor, HasLabel
{
    case Vigente = 'vigente';
    case Anulada = 'anulada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Vigente => 'Vigente',
            self::Anulada => 'Anulada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Vigente => 'success',
            self::Anulada => 'danger',
        };
    }
}
