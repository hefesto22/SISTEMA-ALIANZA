<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Qué se le da al cliente por lo que devuelve.
 */
enum TipoDevolucion: string implements HasColor, HasDescription, HasLabel
{
    case Cambio = 'cambio';
    case Credito = 'credito';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cambio  => 'Cambio',
            self::Credito => 'Crédito',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Cambio  => 'Se le repone lo mismo (sale de la bodega); no cambia lo que pagó.',
            self::Credito => 'Se le abona lo que pagó por eso.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Cambio  => 'info',
            self::Credito => 'warning',
        };
    }
}
