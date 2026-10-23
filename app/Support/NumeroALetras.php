<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Montos en letras para la factura (Art. 11, Acuerdo 481-2017):
 * 3041.10 → "TRES MIL CUARENTA Y UN LEMPIRAS CON 10/100".
 */
final class NumeroALetras
{
    private const HASTA_VEINTINUEVE = [
        '', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE',
        'VEINTE', 'VEINTIUNO', 'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE',
    ];

    private const DECENAS = [3 => 'TREINTA', 4 => 'CUARENTA', 5 => 'CINCUENTA', 6 => 'SESENTA', 7 => 'SETENTA', 8 => 'OCHENTA', 9 => 'NOVENTA'];

    private const CENTENAS = [
        1 => 'CIENTO', 2 => 'DOSCIENTOS', 3 => 'TRESCIENTOS', 4 => 'CUATROCIENTOS', 5 => 'QUINIENTOS',
        6 => 'SEISCIENTOS', 7 => 'SETECIENTOS', 8 => 'OCHOCIENTOS', 9 => 'NOVECIENTOS',
    ];

    public static function lempiras(float $monto): string
    {
        $centavos = (int) round(abs($monto) * 100);
        $enteros = intdiv($centavos, 100);

        $texto = self::entero($enteros, apocopar: true);

        // "UN MILLÓN DE LEMPIRAS", "DOS MILLONES DE LEMPIRAS".
        if ($enteros >= 1_000_000 && $enteros % 1_000_000 === 0) {
            $texto .= ' DE';
        }

        return sprintf('%s %s CON %02d/100', $texto, $enteros === 1 ? 'LEMPIRA' : 'LEMPIRAS', $centavos % 100);
    }

    /**
     * @param bool $apocopar "UN" en lugar de "UNO" al final (va antes de un sustantivo).
     */
    public static function entero(int $numero, bool $apocopar = false): string
    {
        if ($numero === 0) {
            return 'CERO';
        }

        $millones = intdiv($numero, 1_000_000);
        $miles = intdiv($numero % 1_000_000, 1000);
        $resto = $numero % 1000;

        $partes = [];

        if ($millones > 0) {
            $partes[] = $millones === 1 ? 'UN MILLÓN' : self::entero($millones, apocopar: true).' MILLONES';
        }

        if ($miles > 0) {
            $partes[] = $miles === 1 ? 'MIL' : self::hastaMil($miles, apocopar: true).' MIL';
        }

        if ($resto > 0) {
            $partes[] = self::hastaMil($resto, $apocopar);
        }

        return implode(' ', $partes);
    }

    private static function hastaMil(int $numero, bool $apocopar): string
    {
        if ($numero === 100) {
            return 'CIEN';
        }

        $centena = intdiv($numero, 100);
        $resto = $numero % 100;

        $partes = [];

        if ($centena > 0) {
            $partes[] = self::CENTENAS[$centena];
        }

        if ($resto > 0) {
            $partes[] = self::hastaCien($resto, $apocopar);
        }

        return implode(' ', $partes);
    }

    private static function hastaCien(int $numero, bool $apocopar): string
    {
        if ($numero < 30) {
            $texto = self::HASTA_VEINTINUEVE[$numero];
        } else {
            $unidad = $numero % 10;
            $texto = self::DECENAS[intdiv($numero, 10)].($unidad > 0 ? ' Y '.self::HASTA_VEINTINUEVE[$unidad] : '');
        }

        if (! $apocopar) {
            return $texto;
        }

        if (str_ends_with($texto, 'VEINTIUNO')) {
            return substr($texto, 0, -strlen('VEINTIUNO')).'VEINTIÚN';
        }

        if (str_ends_with($texto, 'UNO')) {
            return substr($texto, 0, -strlen('UNO')).'UN';
        }

        return $texto;
    }
}
