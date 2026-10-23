<?php

declare(strict_types=1);

use App\Support\NumeroALetras;

describe('NumeroALetras — total de la factura en letras', function (): void {
    test('montos de factura', function (float $monto, string $letras): void {
        expect(NumeroALetras::lempiras($monto))->toBe($letras);
    })->with([
        [0, 'CERO LEMPIRAS CON 00/100'],
        [1, 'UN LEMPIRA CON 00/100'],
        [16.5, 'DIECISÉIS LEMPIRAS CON 50/100'],
        [21, 'VEINTIÚN LEMPIRAS CON 00/100'],
        [31, 'TREINTA Y UN LEMPIRAS CON 00/100'],
        [100, 'CIEN LEMPIRAS CON 00/100'],
        [101, 'CIENTO UN LEMPIRAS CON 00/100'],
        [415.83, 'CUATROCIENTOS QUINCE LEMPIRAS CON 83/100'],
        [730, 'SETECIENTOS TREINTA LEMPIRAS CON 00/100'],
        [1000, 'MIL LEMPIRAS CON 00/100'],
        [3041.10, 'TRES MIL CUARENTA Y UN LEMPIRAS CON 10/100'],
        [21000, 'VEINTIÚN MIL LEMPIRAS CON 00/100'],
        [101000, 'CIENTO UN MIL LEMPIRAS CON 00/100'],
        [999999.99, 'NOVECIENTOS NOVENTA Y NUEVE MIL NOVECIENTOS NOVENTA Y NUEVE LEMPIRAS CON 99/100'],
        [1000000, 'UN MILLÓN DE LEMPIRAS CON 00/100'],
        [2500000, 'DOS MILLONES QUINIENTOS MIL LEMPIRAS CON 00/100'],
    ]);
});
