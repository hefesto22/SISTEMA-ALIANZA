<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Ciclo de vida de un pedido de preventa.
 *
 *  Pendiente  → lo levantó el vendedor en ruta, espera validación de bodega.
 *  Despachado → bodega confirmó qué sale; precios y totales quedan cerrados.
 *  Anulado    → se canceló antes de despachar.
 */
enum EstadoPedido: string implements HasColor, HasLabel
{
    case Pendiente = 'pendiente';
    case Despachado = 'despachado';
    case Anulado = 'anulado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pendiente  => 'Pendiente',
            self::Despachado => 'Despachado',
            self::Anulado    => 'Anulado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pendiente  => 'warning',
            self::Despachado => 'success',
            self::Anulado    => 'gray',
        };
    }
}
