<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reglas de texto del sistema: todo se guarda en MAYÚSCULAS.
 */
final class Texto
{
    /**
     * "  pulpería la bendición " → "PULPERÍA LA BENDICIÓN" (con tildes y ñ).
     */
    public static function mayusculas(string $texto): string
    {
        return mb_strtoupper(trim($texto), 'UTF-8');
    }
}
