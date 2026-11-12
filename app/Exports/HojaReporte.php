<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithFreezePane;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Una hoja de Excel de un reporte: arriba el título, el periodo y la bodega;
 * luego la tabla con sus títulos (fijos al bajar), las filas y el total.
 *
 * Cada columna dice qué lleva: texto (los códigos se quedan como texto, sin
 * perder ceros ni salir en notación científica), entero, dinero (L. con dos
 * decimales) o porcentaje.
 */
final class HojaReporte extends DefaultValueBinder implements FromArray, WithColumnFormatting, WithColumnWidths, WithCustomValueBinder, WithFreezePane, WithStyles, WithTitle
{
    public const TEXTO = 'texto';

    public const ENTERO = 'entero';

    public const DINERO = 'dinero';

    public const PORCENTAJE = 'porcentaje';

    private const FORMATOS = [
        self::ENTERO     => '#,##0',
        self::DINERO     => '#,##0.00',
        self::PORCENTAJE => '0.0%',
    ];

    /**
     * @param list<string> $encabezado líneas de arriba (la primera es el título)
     * @param array<string, string> $columnas título de la columna => texto, entero, dinero o porcentaje
     * @param list<list<string|int|float|null>> $filas
     * @param list<string|int|float|null>|null $total
     * @param array<int, string> $tipoPorFila número de fila (desde 0) => tipo, para las columnas de la segunda en adelante (hojas de resumen)
     */
    public function __construct(
        private readonly string $titulo,
        private readonly array $encabezado,
        private readonly array $columnas,
        private readonly array $filas,
        private readonly ?array $total = null,
        private readonly array $tipoPorFila = [],
    ) {}

    /**
     * @return list<list<string|int|float|null>>
     */
    public function array(): array
    {
        return [
            ...array_map(fn (string $linea): array => [$linea], $this->encabezado),
            // Fila en blanco: [null] y no [] (una fila vacía se la salta el paquete).
            [null],
            array_keys($this->columnas),
            ...$this->filas,
            ...($this->total === null ? [] : [$this->total]),
        ];
    }

    /**
     * Excel no deja más de 31 letras ni : \ / ? * [ ] en el nombre de la hoja.
     */
    public function title(): string
    {
        return mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $this->titulo), 0, 31);
    }

    public function freezePane(): string
    {
        return 'A'.($this->filaTitulos() + 1);
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $desde = $this->filaTitulos() + 1;
        $hasta = max($desde, $this->filaTitulos() + count($this->filas) + ($this->total === null ? 0 : 1));
        $formatos = [];

        foreach (array_values($this->columnas) as $i => $tipo) {
            if (isset(self::FORMATOS[$tipo])) {
                $letra = Coordinate::stringFromColumnIndex($i + 1);
                $formatos["{$letra}{$desde}:{$letra}{$hasta}"] = self::FORMATOS[$tipo];
            }
        }

        $ultima = Coordinate::stringFromColumnIndex(max(2, count($this->columnas)));

        foreach ($this->tipoPorFila as $i => $tipo) {
            if (isset(self::FORMATOS[$tipo])) {
                $fila = $desde + $i;
                $formatos["B{$fila}:{$ultima}{$fila}"] = self::FORMATOS[$tipo];
            }
        }

        return $formatos;
    }

    /**
     * Ancho según lo más largo de cada columna (sin contar el encabezado de arriba).
     *
     * @return array<string, int>
     */
    public function columnWidths(): array
    {
        $anchos = [];

        foreach (array_keys($this->columnas) as $i => $titulo) {
            $largo = mb_strlen($titulo);

            foreach ([...$this->filas, ...($this->total === null ? [] : [$this->total])] as $fila) {
                $valor = $fila[$i] ?? null;
                $largo = max($largo, mb_strlen(is_float($valor) ? number_format($valor, 2) : (string) $valor));
            }

            $anchos[Coordinate::stringFromColumnIndex($i + 1)] = min(60, max(8, $largo + 2));
        }

        return $anchos;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $titulos = $this->filaTitulos();

        $estilos = [
            1        => ['font' => ['bold' => true, 'size' => 14]],
            $titulos => [
                'font'    => ['bold' => true],
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE5E7EB']],
                'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN]],
            ],
        ];

        if ($this->total !== null) {
            $estilos[$titulos + count($this->filas) + 1] = [
                'font'    => ['bold' => true],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN]],
            ];
        }

        return $estilos;
    }

    /**
     * Las columnas de texto quedan como texto aunque parezcan número (códigos).
     */
    public function bindValue(Cell $cell, mixed $value): bool
    {
        $i = Coordinate::columnIndexFromString($cell->getColumn()) - 1;
        $tipo = array_values($this->columnas)[$i] ?? null;

        if ($tipo === self::TEXTO && is_string($value) && $cell->getRow() > $this->filaTitulos()) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    /**
     * Fila de los títulos de la tabla: después del encabezado y una en blanco.
     */
    private function filaTitulos(): int
    {
        return count($this->encabezado) + 2;
    }
}
