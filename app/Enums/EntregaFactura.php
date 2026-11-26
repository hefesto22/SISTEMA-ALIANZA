<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Cómo terminó una factura en su carga (carga_factura.estado).
 *  - En ruta: va en el camión.
 *  - Entregada: se entregó y se cobró en la liquidación.
 *  - Anulada: no se entregó y se anuló, o se anuló ese día al corregir el pedido.
 *  - Otra carga: no se entregó y vuelve a salir en otra carga.
 */
enum EntregaFactura: string implements HasColor, HasLabel
{
    case EnRuta = 'en_ruta';
    case Entregada = 'entregada';
    case Anulada = 'anulada';
    case OtraCarga = 'otra_carga';

    public function getLabel(): string
    {
        return match ($this) {
            self::EnRuta    => 'En ruta',
            self::Entregada => 'Entregada',
            self::Anulada   => 'Anulada',
            self::OtraCarga => 'Sale en otra carga',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::EnRuta    => 'warning',
            self::Entregada => 'success',
            self::Anulada   => 'danger',
            self::OtraCarga => 'info',
        };
    }
}
