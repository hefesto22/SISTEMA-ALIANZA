<?php

declare(strict_types=1);

namespace App\Domain\Precios;

use App\Domain\Exceptions\ReglaNegocioException;

/**
 * La fórmula de precios que usaban los Excel de todos los proveedores,
 * en un solo lugar:
 *
 *   costo por unidad = (costo × cantidad − descuentos) ÷ cantidad ÷ (UxC si se compró por caja)
 *   precio de venta  = costo ÷ (1 − margen)          ← margen sobre la venta
 *   precio con ISV   = precio × 1.15                  ← solo si es gravado (G)
 *
 * Costo y precio se manejan sin ISV y con 4 decimales, igual que en los Excel.
 */
final class CalculadoraPrecio
{
    private const DECIMALES = 4;

    public static function tasaIsv(): float
    {
        return (float) config('honduras.impuestos.isv.tasa_general', 0.15);
    }

    /**
     * Precio de venta sin ISV a partir del costo y el margen (0.16 = 16%).
     */
    public static function precioVenta(float $costoUnidad, float $margen): float
    {
        if ($costoUnidad < 0) {
            throw new ReglaNegocioException('El costo no puede ser negativo.');
        }

        if ($margen < 0 || $margen >= 1) {
            throw new ReglaNegocioException('El margen debe estar entre 0% y 99.99%.');
        }

        return round($costoUnidad / (1 - $margen), self::DECIMALES);
    }

    public static function conIsv(float $precio, bool $gravado): float
    {
        if (! $gravado) {
            return round($precio, self::DECIMALES);
        }

        return round($precio * (1 + self::tasaIsv()), self::DECIMALES);
    }

    /**
     * Lleva el costo de la factura del proveedor a costo por unidad sin ISV.
     *
     * @param float $costo Costo sin ISV por caja o por unidad, según $porCaja
     * @param int $cantidad Cajas o unidades compradas (para repartir el descuento)
     * @param float $descuento Descuento total de la línea en lempiras
     * @param int $unidadesPorCaja UxC del producto
     */
    public static function costoUnitario(
        float $costo,
        bool $porCaja,
        int $unidadesPorCaja,
        int $cantidad = 1,
        float $descuento = 0.0,
    ): float {
        if ($cantidad < 1) {
            throw new ReglaNegocioException('La cantidad comprada debe ser al menos 1.');
        }

        if ($unidadesPorCaja < 1) {
            throw new ReglaNegocioException('Las unidades por caja deben ser al menos 1.');
        }

        $costoNeto = (($costo * $cantidad) - $descuento) / $cantidad;

        if ($costoNeto < 0) {
            throw new ReglaNegocioException('El descuento no puede ser mayor que el total de la compra.');
        }

        $porUnidad = $porCaja ? $costoNeto / $unidadesPorCaja : $costoNeto;

        return round($porUnidad, self::DECIMALES);
    }

    /**
     * Margen real que deja un precio dado (para mostrar cuando se ajusta a mano).
     */
    public static function margenReal(float $costoUnidad, float $precioUnidad): float
    {
        if ($precioUnidad <= 0) {
            return 0.0;
        }

        return round(1 - ($costoUnidad / $precioUnidad), self::DECIMALES);
    }
}
