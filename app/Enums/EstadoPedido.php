<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Ciclo de vida de un pedido de preventa.
 *
 *  Pendiente    → lo levantó el vendedor en ruta, espera que bodega lo valide.
 *  Por imprimir → bodega confirmó qué sale (quitó lo que no hay, bajó
 *                 cantidades); falta imprimirlo. Si se corrige después de
 *                 impreso, vuelve aquí para reimprimirse.
 *  Impreso      → ya se imprimió; precios y totales quedan cerrados.
 *  Anulado      → se canceló antes de validarlo.
 */
enum EstadoPedido: string implements HasColor, HasLabel
{
    case Pendiente = 'pendiente';
    case PorImprimir = 'por_imprimir';
    case Impreso = 'impreso';
    case Anulado = 'anulado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pendiente   => 'Pendiente',
            self::PorImprimir => 'Por imprimir',
            self::Impreso     => 'Impreso',
            self::Anulado     => 'Anulado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pendiente   => 'warning',
            self::PorImprimir => 'info',
            self::Impreso     => 'success',
            self::Anulado     => 'gray',
        };
    }

    /**
     * Ya pasó por bodega (por imprimir o impreso): se corrige, no se edita.
     */
    public function estaValidado(): bool
    {
        return $this === self::PorImprimir || $this === self::Impreso;
    }
}
