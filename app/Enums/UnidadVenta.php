<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * En qué se vende una línea del pedido: suelto (unidad) o la caja cerrada.
 * El precio de la caja es el precio por unidad × unidades por caja.
 */
enum UnidadVenta: string implements HasLabel
{
    case Unidad = 'unidad';
    case Caja = 'caja';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unidad => 'Unidad',
            self::Caja   => 'Caja',
        };
    }
}
