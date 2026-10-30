<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

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

    /**
     * Para comparar nombres: sin tildes, sin signos y con un solo espacio.
     * "Pulpería  Ana." y "PULPERIA ANA" dan lo mismo: "PULPERIA ANA".
     */
    public static function clave(string $texto): string
    {
        $simple = strtoupper(Str::ascii(self::mayusculas($texto)));

        return trim((string) preg_replace('/[^A-Z0-9]+/', ' ', $simple));
    }
}
