<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * En ruta: el camión salió con sus facturas; se puede cambiar lo que lleva.
 * Liquidada: regresó y se cuadró el dinero; ya no cambia.
 */
enum EstadoCarga: string implements HasColor, HasLabel
{
    case EnRuta = 'en_ruta';
    case Liquidada = 'liquidada';

    public function getLabel(): string
    {
        return match ($this) {
            self::EnRuta    => 'En ruta',
            self::Liquidada => 'Liquidada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::EnRuta    => 'warning',
            self::Liquidada => 'success',
        };
    }
}
