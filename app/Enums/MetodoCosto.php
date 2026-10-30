<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Cómo queda el costo de un producto cuando entra una compra (Configuración).
 */
enum MetodoCosto: string implements HasDescription, HasLabel
{
    case Promedio = 'promedio';
    case Ultimo = 'ultimo';

    public function getLabel(): string
    {
        return match ($this) {
            self::Promedio => 'Costo promedio',
            self::Ultimo   => 'Último costo',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Promedio => 'Mezcla lo que ya hay con lo que entra: (existencia × costo actual + compra × costo de compra) ÷ total.',
            self::Ultimo   => 'Toma el costo de la última compra.',
        };
    }
}
