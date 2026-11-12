<?php

declare(strict_types=1);

namespace App\Exports;

use App\Domain\Reportes\FilaInventario;
use App\Domain\Reportes\InventarioValorizado;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * El inventario valorizado en Excel: el detalle por producto, el resumen por
 * proveedor y, con todas las bodegas, por bodega.
 */
final class InventarioValorizadoExcel implements Export, WithMultipleSheets
{
    public function __construct(private readonly InventarioValorizado $reporte) {}

    /**
     * @return list<HojaReporte>
     */
    public function sheets(): array
    {
        $hojas = [$this->productos()];

        if ($this->reporte->bodega === null) {
            $hojas[] = $this->resumen('Por bodega', 'Bodega', $this->reporte->porBodega());
        }

        $hojas[] = $this->resumen('Por proveedor', 'Proveedor', $this->reporte->porProveedor());

        return $hojas;
    }

    private function productos(): HojaReporte
    {
        $columnas = [
            'Código'            => HojaReporte::TEXTO,
            'Producto'          => HojaReporte::TEXTO,
            'Proveedor'         => HojaReporte::TEXTO,
            'Existencia'        => HojaReporte::TEXTO,
            'Unidades'          => HojaReporte::ENTERO,
            'Costo por unidad'  => HojaReporte::DINERO,
            'Invertido'         => HojaReporte::DINERO,
            'Invertido con ISV' => HojaReporte::DINERO,
            'Precio por unidad' => HojaReporte::DINERO,
            'A precio de venta' => HojaReporte::DINERO,
            'Ganancia esperada' => HojaReporte::DINERO,
            'Margen'            => HojaReporte::PORCENTAJE,
            'Precio'            => HojaReporte::TEXTO,
        ];

        $renglon = fn (FilaInventario $f): array => [
            (string) $f->codigo,
            $f->nombre,
            (string) $f->detalle,
            (string) $f->existencia,
            $f->unidades,
            $f->costoUnidad,
            $f->invertido,
            $f->invertidoConIsv,
            $f->precioUnidad,
            $f->valorVenta,
            $f->ganancia(),
            $f->margen(),
            $f->precioFijo ? 'Fijo' : 'Calculado',
        ];

        $t = $this->reporte->totales();

        return new HojaReporte('Productos', $this->encabezado(), $columnas, array_map($renglon, $this->reporte->productos()), [
            '', 'Total', '', '', $t->unidades, null, $t->invertido, $t->invertidoConIsv, null, $t->valorVenta, $t->ganancia(), $t->margen(), '',
        ]);
    }

    /**
     * @param list<FilaInventario> $filas
     */
    private function resumen(string $titulo, string $nombre, array $filas): HojaReporte
    {
        $columnas = [
            $nombre             => HojaReporte::TEXTO,
            'Productos'         => HojaReporte::ENTERO,
            'Unidades'          => HojaReporte::ENTERO,
            'Invertido'         => HojaReporte::DINERO,
            'Invertido con ISV' => HojaReporte::DINERO,
            'A precio de venta' => HojaReporte::DINERO,
            'Ganancia esperada' => HojaReporte::DINERO,
            'Margen'            => HojaReporte::PORCENTAJE,
        ];

        $renglon = fn (FilaInventario $f): array => [
            $f->nombre, $f->productos, $f->unidades, $f->invertido, $f->invertidoConIsv, $f->valorVenta, $f->ganancia(), $f->margen(),
        ];

        return new HojaReporte($titulo, $this->encabezado(), $columnas, array_map($renglon, $filas), $renglon($this->reporte->totales()));
    }

    /**
     * @return list<string>
     */
    private function encabezado(): array
    {
        return array_values(array_filter([
            'Inventario valorizado · '.$this->reporte->nombreBodega(),
            'Al '.now()->format('d/m/Y H:i').($this->reporte->filtrosTexto() === null ? '' : ' · '.$this->reporte->filtrosTexto()),
            'Montos en lempiras, sin ISV (salvo "Invertido con ISV"). Invertido = existencia × costo promedio.',
        ]));
    }
}
