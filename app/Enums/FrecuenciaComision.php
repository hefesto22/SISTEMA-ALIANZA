<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Cada cuánto se calculan y se pagan las comisiones (Comisiones → Periodo de pago).
 */
enum FrecuenciaComision: string implements HasDescription, HasLabel
{
    case Quincenal = 'quincenal';
    case Mensual = 'mensual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Quincenal => 'Quincenal',
            self::Mensual   => 'Mensual',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Quincenal => 'Dos periodos al mes: del 1 al día de corte y del día siguiente a fin de mes. Las metas son por quincena.',
            self::Mensual   => 'Un periodo por mes. Las metas son por mes.',
        };
    }

    /**
     * "quincena" / "mes", para los textos ("meta de la quincena").
     */
    public function periodo(): string
    {
        return match ($this) {
            self::Quincenal => 'quincena',
            self::Mensual   => 'mes',
        };
    }
}
