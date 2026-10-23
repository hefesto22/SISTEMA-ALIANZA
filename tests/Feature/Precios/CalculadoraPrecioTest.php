<?php

declare(strict_types=1);

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Precios\CalculadoraPrecio;

// Valores tomados de los Excel de los proveedores.

test('el precio de venta es costo ÷ (1 − margen), igual que en el Excel', function (): void {
    // DiLImport: caramelo relleno, costo 30.435, Dulces 13% → 34.9828
    expect(CalculadoraPrecio::precioVenta(30.435, 0.13))->toBe(34.9828);

    // Intercosmo: abrillantador ROSS, costo real 34.20, HC 30% → 48.8571
    expect(CalculadoraPrecio::precioVenta(34.2, 0.30))->toBe(48.8571);
});

test('el costo por caja se reparte entre las unidades de la caja', function (): void {
    // DCASA: café gurmé, caja de 36 a L 1,060 → 29.4444 por unidad
    expect(CalculadoraPrecio::costoUnitario(1060, porCaja: true, unidadesPorCaja: 36, cantidad: 2))->toBe(29.4444);
});

test('el descuento de la línea baja el costo por unidad', function (): void {
    // Intercosmo: 48 unidades a L 36 con L 86.40 de descuento → 34.20
    expect(CalculadoraPrecio::costoUnitario(36, porCaja: false, unidadesPorCaja: 48, cantidad: 48, descuento: 86.4))->toBe(34.2);
});

test('el ISV solo se suma a los productos gravados', function (): void {
    expect(CalculadoraPrecio::conIsv(100, true))->toBe(115.0)
        ->and(CalculadoraPrecio::conIsv(100, false))->toBe(100.0);
});

test('la utilidad es después del ISV: el precio con ISV deja el mismo % sobre el costo con ISV', function (): void {
    // MERHONSA: jabón DK12, costo neto 9.2619, 14% → L. 12.39 al cliente
    $precio = CalculadoraPrecio::precioVenta(9.2619, 0.14);
    $venta = CalculadoraPrecio::conIsv($precio, true);
    $costoConIsv = CalculadoraPrecio::conIsv(9.2619, true);

    expect(round($venta, 2))->toBe(12.39)
        ->and($costoConIsv)->toBe(10.6512)
        ->and(round(1 - $costoConIsv / $venta, 4))->toBe(0.14);
});

test('lo que se escribe con ISV por caja se guarda por unidad sin ISV, y vuelve igual', function (): void {
    // Caja de 48 a L. 594.72 con ISV → 10.7739 por unidad sin ISV
    $guardado = CalculadoraPrecio::aUnidadSinIsv(594.72, true, 48);

    expect($guardado)->toBe(10.7739)
        ->and(CalculadoraPrecio::aPantalla($guardado, true, 48))->toBe(594.72)
        ->and(CalculadoraPrecio::aPantalla($guardado, true))->toBe(12.39)
        // exento: sin ISV, solo se reparte entre la caja
        ->and(CalculadoraPrecio::aUnidadSinIsv(120.0, false, 12))->toBe(10.0);
});

test('no acepta margen de 100% o más', function (): void {
    CalculadoraPrecio::precioVenta(10, 1.0);
})->throws(ReglaNegocioException::class);

test('no acepta un descuento mayor que la compra', function (): void {
    CalculadoraPrecio::costoUnitario(10, porCaja: false, unidadesPorCaja: 1, cantidad: 1, descuento: 20);
})->throws(ReglaNegocioException::class);
