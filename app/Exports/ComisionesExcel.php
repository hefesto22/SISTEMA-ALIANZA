<?php

declare(strict_types=1);

namespace App\Exports;

use App\Domain\Comisiones\ComisionesDelPeriodo;
use App\Domain\Comisiones\FilaComision;
use App\Domain\Comisiones\ResumenVendedor;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Las comisiones de un periodo en Excel, para pagar: una hoja con lo de
 * cada vendedor (venta y comisión) y otra con el detalle por grupo
 * (venta, anuladas, devoluciones con crédito, base, venta del mes, %,
 * comisión del mes, lo ya adelantado, lo que se paga y la meta). La meta
 * es del mes.
 */
final class ComisionesExcel implements Export, WithMultipleSheets
{
    public function __construct(
        private readonly ComisionesDelPeriodo $calculo,
        private readonly string $alcance,
    ) {}

    /**
     * @return list<HojaReporte>
     */
    public function sheets(): array
    {
        return [$this->porVendedor(), $this->porGrupo()];
    }

    private function porVendedor(): HojaReporte
    {
        $vendedores = $this->calculo->vendedores();
        $t = $this->calculo->totales();

        return new HojaReporte(
            'Por vendedor',
            $this->encabezado(),
            [
                'Código'               => HojaReporte::TEXTO,
                'Vendedor'             => HojaReporte::TEXTO,
                'Bodega'               => HojaReporte::TEXTO,
                'Facturas'             => HojaReporte::ENTERO,
                'Venta neta (sin ISV)' => HojaReporte::DINERO,
                'Venta en grupos'      => HojaReporte::DINERO,
                'A pagar'              => HojaReporte::DINERO,
            ],
            array_map(fn (ResumenVendedor $v): array => [
                (string) $v->codigo,
                $v->nombre,
                (string) $v->bodega,
                $v->facturas,
                $v->ventaTotal,
                $v->ventaEnGrupos(),
                $v->comision(),
            ], $vendedores),
            ['', 'Total', '', $t['facturas'], $t['venta'], $t['base'], $t['comision']],
        );
    }

    private function porGrupo(): HojaReporte
    {
        $filas = [];

        foreach ($this->calculo->vendedores() as $v) {
            foreach ($v->filas as $f) {
                $filas[] = [
                    (string) $v->codigo,
                    $v->nombre,
                    $f->grupo,
                    $f->propia ? 'Propia' : ($f->deBodega ? 'De la bodega' : 'Del grupo'),
                    $f->venta,
                    $f->anulado,
                    $f->devuelto,
                    $f->base,
                    $f->baseMes(),
                    $f->porcentaje / 100,
                    $f->comisionMes(),
                    $f->pagadoAntes,
                    $f->comision,
                    $f->meta,
                    self::avance($f),
                ];
            }
        }

        $t = $this->calculo->totales();

        return new HojaReporte(
            'Por grupo',
            $this->encabezado(),
            [
                'Código'           => HojaReporte::TEXTO,
                'Vendedor'         => HojaReporte::TEXTO,
                'Grupo'            => HojaReporte::TEXTO,
                'Escala'           => HojaReporte::TEXTO,
                'Venta'            => HojaReporte::DINERO,
                'Anuladas'         => HojaReporte::DINERO,
                'Devoluciones'     => HojaReporte::DINERO,
                'Base del periodo' => HojaReporte::DINERO,
                'Venta del mes'    => HojaReporte::DINERO,
                '%'                => HojaReporte::PORCENTAJE,
                'Comisión del mes' => HojaReporte::DINERO,
                'Ya adelantado'    => HojaReporte::DINERO,
                'A pagar'          => HojaReporte::DINERO,
                'Meta del mes'     => HojaReporte::DINERO,
                'Avance a la meta' => HojaReporte::PORCENTAJE,
            ],
            $filas,
            ['', 'Total', '', '', null, null, null, $t['base'], null, null, null, $this->calculo->adelantos(), $t['comision'], null, null],
        );
    }

    /**
     * @return list<string>
     */
    private function encabezado(): array
    {
        $cierre = $this->calculo->cierre();

        return [
            'Comisiones · '.$this->calculo->periodo->texto(),
            $this->alcance,
            $cierre === null
                ? 'Sin cerrar: así va al '.now()->format('d/m/Y h:i A').' (puede cambiar)'
                : 'Cerrado el '.$cierre->cerrado_at->format('d/m/Y h:i A').($cierre->cerradoPor ? ' por '.$cierre->cerradoPor->name : ''),
            'Venta neta facturada, sin ISV y ya con descuentos',
        ];
    }

    private static function avance(FilaComision $fila): ?float
    {
        $avance = $fila->avance();

        return $avance === null ? null : round($avance, 4);
    }
}
