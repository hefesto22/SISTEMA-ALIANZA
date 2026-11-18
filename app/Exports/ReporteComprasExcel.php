<?php

declare(strict_types=1);

namespace App\Exports;

use App\Domain\Reportes\ComprasDelPeriodo;
use App\Domain\Reportes\FilaCompra;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * El reporte de compras en Excel: una hoja por parte (resumen, por
 * proveedor o por categoría, por bodega, por producto, por compra), según
 * lo que se escogió. Con un proveedor, es el archivo que se le manda.
 */
final class ReporteComprasExcel implements Export, WithMultipleSheets
{
    public function __construct(
        private readonly ComprasDelPeriodo $reporte,
        private readonly string $ver,
    ) {}

    /**
     * @return list<HojaReporte>
     */
    public function sheets(): array
    {
        $ver = ComprasDelPeriodo::ver($this->ver);
        $hojas = [];

        if ($ver === 'todo') {
            $hojas[] = $this->resumen();
            $hojas[] = $this->reporte->proveedor === null
                ? $this->grupo('Por proveedor', 'Proveedor', $this->reporte->porProveedor(), conCodigo: true)
                : $this->grupo('Por categoría', 'Categoría', $this->reporte->porCategoria());

            $bodegas = $this->reporte->bodega === null ? $this->reporte->porBodega() : [];

            if (count($bodegas) > 1) {
                // Un producto puede entrar a las dos bodegas: el total de productos no se suma.
                $hojas[] = $this->grupo('Por bodega', 'Bodega', $bodegas, conCodigo: true, productosEnTotal: false);
            }
        }

        if (ComprasDelPeriodo::incluye($ver, 'productos')) {
            $hojas[] = $this->productos();
        }

        if (ComprasDelPeriodo::incluye($ver, 'compras')) {
            $hojas[] = $this->compras();
        }

        return $hojas;
    }

    private function resumen(): HojaReporte
    {
        $t = $this->reporte->totales();

        $filas = [
            ['Compras (facturas del proveedor)', $t->compras],
            ...($this->reporte->proveedor === null ? [['Proveedores', $this->reporte->cuantosProveedores()]] : []),
            ['Productos', $t->productos],
            ['Unidades', $t->unidades],
            ['Sin ISV', $t->subtotal],
            ['ISV', $t->isv],
            ['Total comprado (con ISV)', $t->total],
            ['Promedio por compra (con ISV)', $t->compras > 0 ? round($t->total / $t->compras, 2) : 0.0],
        ];
        $enteros = $this->reporte->proveedor === null ? 4 : 3;

        return new HojaReporte('Resumen', $this->encabezado(), [
            'Concepto' => HojaReporte::TEXTO,
            'Monto'    => HojaReporte::DINERO,
        ], $filas, tipoPorFila: array_fill(0, $enteros, HojaReporte::ENTERO));
    }

    /**
     * Proveedores, categorías o bodegas, con la parte de cada uno.
     *
     * @param list<FilaCompra> $filas
     */
    private function grupo(string $titulo, string $nombre, array $filas, bool $conCodigo = false, bool $productosEnTotal = true): HojaReporte
    {
        $t = $this->reporte->totales();

        $columnas = [
            ...($conCodigo ? ['Código' => HojaReporte::TEXTO] : []),
            $nombre     => HojaReporte::TEXTO,
            'Compras'   => HojaReporte::ENTERO,
            'Productos' => HojaReporte::ENTERO,
            'Unidades'  => HojaReporte::ENTERO,
            'Sin ISV'   => HojaReporte::DINERO,
            'ISV'       => HojaReporte::DINERO,
            'Total'     => HojaReporte::DINERO,
            'Parte'     => HojaReporte::PORCENTAJE,
        ];

        $renglon = fn (FilaCompra $f, bool $esTotal = false): array => [
            ...($conCodigo ? [$esTotal ? '' : (string) $f->codigo] : []),
            $f->nombre,
            $f->compras,
            $esTotal && ! $productosEnTotal ? null : $f->productos,
            $f->unidades,
            $f->subtotal,
            $f->isv,
            $f->total,
            $esTotal ? 1.0 : $f->parteDe($t->total),
        ];

        return new HojaReporte(
            $titulo,
            $this->encabezado(),
            $columnas,
            array_map(fn (FilaCompra $f): array => $renglon($f), $filas),
            $renglon($t, true),
        );
    }

    private function productos(): HojaReporte
    {
        $conProveedor = $this->reporte->proveedor === null;

        $columnas = [
            'Código'   => HojaReporte::TEXTO,
            'Producto' => HojaReporte::TEXTO,
            ...($conProveedor ? ['Proveedor' => HojaReporte::TEXTO] : []),
            'Categoría'             => HojaReporte::TEXTO,
            'Presentación'          => HojaReporte::TEXTO,
            'Cantidad'              => HojaReporte::ENTERO,
            'Unidades'              => HojaReporte::ENTERO,
            'Costo prom. (sin ISV)' => HojaReporte::DINERO,
            'Sin ISV'               => HojaReporte::DINERO,
            'ISV'                   => HojaReporte::DINERO,
            'Total'                 => HojaReporte::DINERO,
            'Compras'               => HojaReporte::ENTERO,
        ];

        $renglon = fn (FilaCompra $f, bool $esTotal = false): array => [
            $esTotal ? '' : (string) $f->codigo,
            $f->nombre,
            ...($conProveedor ? [$esTotal ? '' : (string) $f->proveedor] : []),
            $esTotal ? '' : (string) $f->detalle,
            $esTotal ? '' : (string) $f->presentacion,
            $esTotal ? null : $f->cantidad,
            $f->unidades,
            $esTotal ? null : $f->costoPromedio(),
            $f->subtotal,
            $f->isv,
            $f->total,
            $esTotal ? null : $f->compras,
        ];

        return new HojaReporte(
            'Por producto',
            $this->encabezado(),
            $columnas,
            array_map(fn (FilaCompra $f): array => $renglon($f), $this->reporte->porProducto()),
            $renglon($this->reporte->totales(), true),
        );
    }

    private function compras(): HojaReporte
    {
        $conProveedor = $this->reporte->proveedor === null;
        $conBodega = $this->reporte->bodega === null;

        $columnas = [
            'Fecha'                 => HojaReporte::TEXTO,
            'Factura del proveedor' => HojaReporte::TEXTO,
            'Compra'                => HojaReporte::TEXTO,
            ...($conProveedor ? ['Proveedor' => HojaReporte::TEXTO] : []),
            ...($conBodega ? ['Bodega' => HojaReporte::TEXTO] : []),
            'Productos' => HojaReporte::ENTERO,
            'Unidades'  => HojaReporte::ENTERO,
            'Sin ISV'   => HojaReporte::DINERO,
            'ISV'       => HojaReporte::DINERO,
            'Total'     => HojaReporte::DINERO,
        ];

        $renglon = fn (FilaCompra $f, bool $esTotal = false): array => [
            $esTotal ? 'Total' : (string) $f->fecha,
            $esTotal ? '' : (string) $f->codigo,
            $esTotal ? '' : $f->nombre,
            ...($conProveedor ? [$esTotal ? '' : (string) $f->proveedor] : []),
            ...($conBodega ? [$esTotal ? '' : (string) $f->bodega] : []),
            $esTotal ? null : $f->productos,
            $f->unidades,
            $f->subtotal,
            $f->isv,
            $f->total,
        ];

        return new HojaReporte(
            'Por compra',
            $this->encabezado(),
            $columnas,
            array_map(fn (FilaCompra $f): array => $renglon($f), $this->reporte->porCompra()),
            $renglon($this->reporte->totales(), true),
        );
    }

    /**
     * @return list<string>
     */
    private function encabezado(): array
    {
        return array_values(array_filter([
            $this->reporte->titulo(ComprasDelPeriodo::ver($this->ver)),
            $this->reporte->fichaProveedor(),
            'Periodo: '.$this->reporte->periodo->texto().' · '.$this->reporte->nombreBodega(),
            'Montos en lempiras, como vienen en las facturas del proveedor (por la fecha de la factura). Sacado el '.now()->format('d/m/Y H:i').'.',
        ], fn (?string $linea): bool => $linea !== null));
    }
}
