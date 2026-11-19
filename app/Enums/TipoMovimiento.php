<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Por qué entró o salió mercadería de una bodega (kárdex).
 */
enum TipoMovimiento: string implements HasColor, HasLabel
{
    case Compra = 'compra';
    case Despacho = 'despacho';
    case Correccion = 'correccion';
    case Ajuste = 'ajuste';
    case Apertura = 'apertura';
    case Devolucion = 'devolucion';
    case Reposicion = 'reposicion';

    public function getLabel(): string
    {
        return match ($this) {
            self::Compra     => 'Compra',
            self::Despacho   => 'Despacho',
            self::Correccion => 'Corrección de pedido',
            self::Ajuste     => 'Ajuste por conteo',
            self::Apertura   => 'Caja abierta',
            self::Devolucion => 'Devolución',
            self::Reposicion => 'Reposición (cambio)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Compra     => 'success',
            self::Despacho   => 'info',
            self::Correccion => 'warning',
            self::Ajuste     => 'gray',
            self::Apertura   => 'primary',
            self::Devolucion => 'success',
            self::Reposicion => 'warning',
        };
    }
}
