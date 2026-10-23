<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Openplain\FilamentShadcnTheme\Color as Shadcn;

/**
 * Temas de color del panel, del paquete openplain/filament-shadcn-theme
 * (los 8 oficiales de ui.shadcn.com/themes). Cambian entre modo claro y
 * oscuro como en shadcn: el "Default" pone botones negros en claro y
 * blancos en oscuro. Se escoge en Sistema → Configuración.
 */
enum TemaPanel: string implements HasLabel
{
    case Default = 'default';
    case Azul = 'blue';
    case Verde = 'green';
    case Naranja = 'orange';
    case Rojo = 'red';
    case Rosa = 'rose';
    case Violeta = 'violet';
    case Amarillo = 'yellow';

    public function getLabel(): string
    {
        return match ($this) {
            self::Default  => 'Shadcn (blanco y negro)',
            self::Azul     => 'Azul',
            self::Verde    => 'Verde',
            self::Naranja  => 'Naranja',
            self::Rojo     => 'Rojo',
            self::Rosa     => 'Rosa',
            self::Violeta  => 'Violeta',
            self::Amarillo => 'Amarillo',
        };
    }

    /**
     * Paleta para ->colors(['primary' => ...]) del panel.
     *
     * @return array<int, string>
     */
    public function paleta(): array
    {
        return match ($this) {
            self::Default  => Shadcn::Default,
            self::Azul     => Shadcn::Blue,
            self::Verde    => Shadcn::Green,
            self::Naranja  => Shadcn::Orange,
            self::Rojo     => Shadcn::Red,
            self::Rosa     => Shadcn::Rose,
            self::Violeta  => Shadcn::Violet,
            self::Amarillo => Shadcn::Yellow,
        };
    }
}
