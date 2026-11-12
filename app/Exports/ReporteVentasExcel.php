<?php

declare(strict_types=1);

namespace App\Exports;

use App\Domain\Reportes\FilaVenta;
use App\Domain\Reportes\VentasDelPeriodo;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * El reporte de ventas en Excel: una hoja por parte (resumen, por día, por
 * bodega, por vendedor, por producto, por cliente), según lo que se escogió.
 */
final class ReporteVentasExcel implements Export, WithMultipleSheets
{
    public function __construct(
        private readonly VentasDelPeriodo $reporte,
        private readonly string $ver,
    ) {}

    /**
     * @return list<HojaReporte>
     */
    public function sheets(): array
    {
        $ver = VentasDelPeriodo::ver($this->ver);
        $hojas = [];

        if (VentasDelPeriodo::incluye($ver, 'totales')) {
            $hojas[] = $this->resumen();
            $hojas[] = $this->hoja('Por día', 'Día', $this->reporte->porDia(), conCodigo: false);

            if ($this->reporte->bodega === null) {
                $hojas[] = $this->hoja('Por bodega', 'Bodega', $this->reporte->porBodega());
            }
        }

        if (VentasDelPeriodo::incluye($ver, 'vendedores')) {
            $hojas[] = $this->hoja('Por vendedor', 'Vendedor', $this->reporte->porVendedor());
        }

        if (VentasDelPeriodo::incluye($ver, 'productos')) {
            $hojas[] = $this->productos();
        }

        if (VentasDelPeriodo::incluye($ver, 'clientes')) {
            $hojas[] = $this->hoja('Por cliente', 'Cliente', $this->reporte->porCliente(), conDetalle: 'Último despacho', conClientes: false);
        }

        return $hojas;
    }

    private function resumen(): HojaReporte
    {
        $t = $this->reporte->totales();
        $estimados = $this->reporte->pedidosEstimados();

        $filas = [
            ['Pedidos despachados', $t->pedidos],
            ['Clientes', $t->clientes],
            ['Venta (sin ISV, ya con descuentos)', $t->venta],
            ['Descuentos por promociones', $t->descuento],
            ['ISV', $t->isv],
            ['Total facturado (con ISV)', $t->total],
            ['Costo de lo vendido', $t->costo],
            ['Ganancia', $t->ganancia()],
            ['Margen (ganancia ÷ venta)', $t->margen()],
        ];
        $tipos = [0 => HojaReporte::ENTERO, 1 => HojaReporte::ENTERO, 8 => HojaReporte::PORCENTAJE];

        if ($estimados > 0) {
            $filas[] = ['Pedidos con costo estimado (sin salida en el kárdex)', $estimados];
            $tipos[9] = HojaReporte::ENTERO;
        }

        return new HojaReporte('Resumen', $this->encabezado(), [
            'Concepto' => HojaReporte::TEXTO,
            'Monto'    => HojaReporte::DINERO,
        ], $filas, tipoPorFila: $tipos);
    }

    /**
     * @param list<FilaVenta> $filas
     */
    private function hoja(string $titulo, string $nombre, array $filas, bool $conCodigo = true, ?string $conDetalle = null, bool $conClientes = true): HojaReporte
    {
        $columnas = [
            ...($conCodigo ? ['Código' => HojaReporte::TEXTO] : []),
            $nombre => HojaReporte::TEXTO,
            ...($conDetalle === null ? [] : [$conDetalle => HojaReporte::TEXTO]),
            'Pedidos' => HojaReporte::ENTERO,
            ...($conClientes ? ['Clientes' => HojaReporte::ENTERO] : []),
            'Venta'      => HojaReporte::DINERO,
            'Descuentos' => HojaReporte::DINERO,
            'ISV'        => HojaReporte::DINERO,
            'Total'      => HojaReporte::DINERO,
            'Costo'      => HojaReporte::DINERO,
            'Ganancia'   => HojaReporte::DINERO,
            'Margen'     => HojaReporte::PORCENTAJE,
        ];

        $renglon = fn (FilaVenta $f, bool $esTotal = false): array => [
            ...($conCodigo ? [$esTotal ? '' : (string) $f->codigo] : []),
            $f->nombre,
            ...($conDetalle === null ? [] : [$esTotal ? '' : str_replace('Último: ', '', (string) $f->detalle)]),
            $f->pedidos,
            ...($conClientes ? [$f->clientes] : []),
            $f->venta,
            $f->descuento,
            $f->isv,
            $f->total,
            $f->costo,
            $f->ganancia(),
            $f->margen(),
        ];

        return new HojaReporte(
            $titulo,
            $this->encabezado(),
            $columnas,
            array_map(fn (FilaVenta $f): array => $renglon($f), $filas),
            $renglon($this->reporte->totales(), true),
        );
    }

    private function productos(): HojaReporte
    {
        $columnas = [
            'Código'     => HojaReporte::TEXTO,
            'Producto'   => HojaReporte::TEXTO,
            'Proveedor'  => HojaReporte::TEXTO,
            'Salió'      => HojaReporte::TEXTO,
            'Unidades'   => HojaReporte::ENTERO,
            'Pedidos'    => HojaReporte::ENTERO,
            'Clientes'   => HojaReporte::ENTERO,
            'Venta'      => HojaReporte::DINERO,
            'Descuentos' => HojaReporte::DINERO,
            'Costo'      => HojaReporte::DINERO,
            'Ganancia'   => HojaReporte::DINERO,
            'Margen'     => HojaReporte::PORCENTAJE,
        ];

        $renglon = fn (FilaVenta $f, bool $esTotal = false): array => [
            $esTotal ? '' : (string) $f->codigo,
            $f->nombre,
            $esTotal ? '' : (string) $f->detalle,
            $esTotal ? '' : (string) $f->cantidad,
            $f->unidades,
            $f->pedidos,
            $f->clientes,
            $f->venta,
            $f->descuento,
            $f->costo,
            $f->ganancia(),
            $f->margen(),
        ];

        return new HojaReporte(
            'Por producto',
            $this->encabezado(),
            $columnas,
            array_map(fn (FilaVenta $f): array => $renglon($f), $this->reporte->porProducto()),
            $renglon($this->reporte->totales(), true),
        );
    }

    /**
     * @return list<string>
     */
    private function encabezado(): array
    {
        return [
            $this->reporte->titulo(VentasDelPeriodo::ver($this->ver)),
            'Periodo: '.$this->reporte->periodo->texto().' · '.$this->reporte->nombreBodega(),
            'Montos en lempiras. Venta, costo y ganancia sin ISV; el total lleva ISV. Sacado el '.now()->format('d/m/Y H:i').'.',
        ];
    }
}
