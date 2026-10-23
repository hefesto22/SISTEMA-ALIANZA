<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Por qué cambió el precio de un producto (queda en el historial).
 */
enum MotivoCambioPrecio: string implements HasColor, HasLabel
{
    case Compra = 'compra';
    case Margen = 'margen';
    case Manual = 'manual';
    case CargaInicial = 'carga_inicial';

    public function getLabel(): string
    {
        return match ($this) {
            self::Compra       => 'Costo nuevo',
            self::Margen       => 'Cambio de margen',
            self::Manual       => 'Ajuste manual',
            self::CargaInicial => 'Carga inicial',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Compra       => 'info',
            self::Margen       => 'warning',
            self::Manual       => 'primary',
            self::CargaInicial => 'gray',
        };
    }
}
