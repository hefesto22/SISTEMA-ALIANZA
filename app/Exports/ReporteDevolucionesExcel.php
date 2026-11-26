<?php

declare(strict_types=1);

namespace App\Exports;

use App\Domain\Reportes\DevolucionesDelPeriodo;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * El reporte de devoluciones y pérdidas en Excel: una hoja por parte
 * (resumen, pérdidas por proveedor, pérdidas por producto, cambio por cambio
 * y cada devolución), según lo que se escogió.
 */
final class ReporteDevolucionesExcel implements Export, WithMultipleSheets
{
    public function __construct(
        private readonly DevolucionesDelPeriodo $reporte,
        private readonly string $ver,
    ) {}

    /**
     * @return list<HojaReporte>
     */
    public function sheets(): array
    {
        $ver = DevolucionesDelPeriodo::ver($this->ver);
        $hojas = [];

        if ($ver === 'todo') {
            $hojas[] = $this->resumen();
        }

        if (DevolucionesDelPeriodo::incluye($ver, 'perdidas')) {
            if ($this->reporte->proveedor === null) {
                $hojas[] = $this->perdidasPorProveedor();
            }

            $hojas[] = $this->perdidas();
        }

        if (DevolucionesDelPeriodo::incluye($ver, 'cambios')) {
            $hojas[] = $this->cambios();
        }

        if (DevolucionesDelPeriodo::incluye($ver, 'devoluciones')) {
            $hojas[] = $this->devoluciones();
        }

        return $hojas;
    }

    private function resumen(): HojaReporte
    {
        $t = $this->reporte->totales();

        $filas = [
            ['Devoluciones', $t['devoluciones']],
            ['De cambio (cambio por cambio)', $t['cambios']],
            ['Con crédito', $t['creditos']],
            ['Productos con pérdida', $t['productos_perdidos']],
            ['Pérdidas: lo que regresó dañado (a costo)', $t['perdida']],
            ['Regresó a bodega: lo bueno (a costo)', $t['regreso']],
            ['Repuesto en los cambios (a costo)', $t['repuesto']],
            ['Devuelto en crédito (con ISV)', $t['credito']],
        ];

        return new HojaReporte('Resumen', $this->encabezado(), [
            'Concepto' => HojaReporte::TEXTO,
            'Monto'    => HojaReporte::DINERO,
        ], $filas, tipoPorFila: array_fill(0, 4, HojaReporte::ENTERO));
    }

    private function perdidasPorProveedor(): HojaReporte
    {
        $t = $this->reporte->totales();

        return new HojaReporte(
            'Pérdidas por proveedor',
            $this->encabezado(),
            [
                'Proveedor' => HojaReporte::TEXTO,
                'Productos' => HojaReporte::ENTERO,
                'Pérdida'   => HojaReporte::DINERO,
            ],
            array_map(fn (array $f): array => [$f['proveedor'], $f['productos'], $f['perdida']], $this->reporte->perdidasPorProveedor()),
            ['Total', $t['productos_perdidos'], $t['perdida']],
        );
    }

    private function perdidas(): HojaReporte
    {
        $conProveedor = $this->reporte->proveedor === null;

        $columnas = [
            'Código'   => HojaReporte::TEXTO,
            'Producto' => HojaReporte::TEXTO,
            ...($conProveedor ? ['Proveedor' => HojaReporte::TEXTO] : []),
            'Presentación' => HojaReporte::TEXTO,
            'Dañado'       => HojaReporte::ENTERO,
            'Costo c/u'    => HojaReporte::DINERO,
            'Pérdida'      => HojaReporte::DINERO,
        ];

        $filas = array_map(fn (array $f): array => [
            $f['codigo'],
            $f['producto'],
            ...($conProveedor ? [$f['proveedor']] : []),
            $f['presentacion'],
            $f['cantidad'],
            $f['costo'],
            $f['perdida'],
        ], $this->reporte->perdidas());

        return new HojaReporte('Pérdidas', $this->encabezado(), $columnas, $filas, [
            '',
            'Total perdido',
            ...($conProveedor ? [''] : []),
            '',
            null,
            null,
            $this->reporte->totales()['perdida'],
        ]);
    }

    private function cambios(): HojaReporte
    {
        $conProveedor = $this->reporte->proveedor === null;

        $columnas = [
            'Código'   => HojaReporte::TEXTO,
            'Producto' => HojaReporte::TEXTO,
            ...($conProveedor ? ['Proveedor' => HojaReporte::TEXTO] : []),
            'Presentación'     => HojaReporte::TEXTO,
            'Regresó a bodega' => HojaReporte::ENTERO,
            'Dañado'           => HojaReporte::ENTERO,
            'Se repuso'        => HojaReporte::ENTERO,
            'A costo'          => HojaReporte::DINERO,
        ];

        $filas = array_map(fn (array $f): array => [
            $f['codigo'],
            $f['producto'],
            ...($conProveedor ? [$f['proveedor']] : []),
            $f['presentacion'],
            $f['buenas'],
            $f['danadas'],
            $f['repuestas'],
            $f['costo'],
        ], $this->reporte->cambios());

        return new HojaReporte('Cambio por cambio', $this->encabezado(), $columnas, $filas, [
            '',
            'Total repuesto',
            ...($conProveedor ? [''] : []),
            '',
            null,
            null,
            null,
            $this->reporte->totales()['repuesto'],
        ]);
    }

    private function devoluciones(): HojaReporte
    {
        $t = $this->reporte->totales();

        $columnas = [
            'Devolución'               => HojaReporte::TEXTO,
            'Fecha'                    => HojaReporte::TEXTO,
            'Cliente'                  => HojaReporte::TEXTO,
            'Factura'                  => HojaReporte::TEXTO,
            'Carga'                    => HojaReporte::TEXTO,
            'Tipo'                     => HojaReporte::TEXTO,
            'Registró'                 => HojaReporte::TEXTO,
            'Motivo'                   => HojaReporte::TEXTO,
            'Regresó a bodega (costo)' => HojaReporte::DINERO,
            'Pérdida (costo)'          => HojaReporte::DINERO,
            'Crédito'                  => HojaReporte::DINERO,
        ];

        $filas = array_map(fn (array $f): array => [
            $f['numero'],
            $f['fecha'],
            $f['cliente'],
            $f['factura'],
            $f['carga'],
            $f['tipo']->getLabel(),
            $f['registro'],
            $f['motivo'],
            $f['regreso'],
            $f['perdida'],
            $f['credito'],
        ], $this->reporte->devoluciones());

        return new HojaReporte('Devoluciones', $this->encabezado(), $columnas, $filas, ['Total', '', '', '', '', '', '', '', $t['regreso'], $t['perdida'], $t['credito']]);
    }

    /**
     * @return list<string>
     */
    private function encabezado(): array
    {
        return [
            $this->reporte->titulo(DevolucionesDelPeriodo::ver($this->ver)),
            'Periodo: '.$this->reporte->periodo->texto().' · '.$this->reporte->alcance(),
            'Pérdidas, lo que regresó a bodega y lo repuesto van a costo; el crédito, con ISV. Por la fecha de la devolución. Sacado el '.now()->format('d/m/Y H:i').'.',
        ];
    }
}
