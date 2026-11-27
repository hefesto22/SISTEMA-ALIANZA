<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * En qué está lo que se le debe llevar a un cliente por un cambio.
 *  - Por entregar: espera la próxima carga.
 *  - En ruta: va en un camión (ya salió de la bodega).
 *  - Entregada: se le dio (se marcó al liquidar la carga).
 */
enum EstadoReposicion: string implements HasColor, HasLabel
{
    case PorEntregar = 'por_entregar';
    case EnRuta = 'en_ruta';
    case Entregada = 'entregada';

    public function getLabel(): string
    {
        return match ($this) {
            self::PorEntregar => 'Por entregar',
            self::EnRuta      => 'En ruta',
            self::Entregada   => 'Entregada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PorEntregar => 'warning',
            self::EnRuta      => 'info',
            self::Entregada   => 'success',
        };
    }
}
