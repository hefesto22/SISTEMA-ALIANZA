<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Qué precio se le cobra al cliente si el precio cambió entre que se levantó
 * el pedido y que bodega lo despacha (Configuración).
 */
enum PrecioAlDespachar: string implements HasDescription, HasLabel
{
    case Despacho = 'despacho';
    case Pedido = 'pedido';

    public function getLabel(): string
    {
        return match ($this) {
            self::Despacho => 'Precio del día que se despacha',
            self::Pedido   => 'Precio del pedido',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Despacho => 'Si el precio subió o bajó, se cobra el de hoy.',
            self::Pedido   => 'Se cobra lo que se le dijo al cliente. Si ese precio ya queda por debajo del costo, se cobra el precio de hoy para no vender a pérdida.',
        };
    }
}
