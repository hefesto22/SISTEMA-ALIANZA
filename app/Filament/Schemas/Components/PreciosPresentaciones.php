<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use App\Domain\Precios\CalculadoraPrecio;
use App\Models\CompraItem;
use App\Models\Existencia;
use App\Models\Presentacion;
use App\Models\Producto;
use Filament\Forms\Components\Field;

/**
 * Precios de venta de un producto, en el modal "Actualizar precio"
 * (App\Filament\Resources\Productos\Actions\ActualizarPrecioAction).
 *
 * Arriba, para leer: el costo promedio que dejan las compras, la última
 * compra y la existencia; "Corregir costo" abre un campo solo para cuando
 * está mal. Abajo, una fila por presentación: costo promedio (con y sin
 * ISV), % de utilidad, precio (con ISV si es gravado), cuánto gana y si el
 * precio es "Con %" (sale del costo con ese % y lo mueven las compras) o
 * "Fijo" (lo pone la empresa; las compras no lo mueven), con una línea que
 * lo explica con los números de esa fila. Escribir un % la deja "Con %";
 * escribir otro precio la deja "Fijo". Las cuentas pasan en el navegador
 * mientras se escribe; al guardar, ActualizarPrecioAction las revisa y
 * ActualizarPrecioProducto las guarda.
 *
 * Estado: array{corregir: bool, costo_base: float, filas: list<array{id: int,
 * modo: string, margen: float|null, precio_base: float|null}>} con el
 * costo por unidad base y los precios SIN ISV; margen en % (14 = 14 %).
 *
 * Vista: resources/views/filament/productos/precios-presentaciones.blade.php
 */
class PreciosPresentaciones extends Field
{
    public const CALCULADO = 'calculado';

    public const FIJO = 'fijo';

    /** Un precio que se aleja más que esto del de hoy se avisa (error de dedo). */
    public const CAMBIO_SOSPECHOSO = 0.25;

    /** @var view-string */
    protected string $view = 'filament.productos.precios-presentaciones';

    protected function setUp(): void
    {
        parent::setUp();

        $this->default([]);
        $this->rule('array');
        $this->hiddenLabel();
    }

    /**
     * Lo que abre el modal: el costo de hoy y una fila por presentación.
     *
     * @return array{corregir: bool, costo_base: float, filas: list<array{id: int, modo: string, margen: float|null, precio_base: float}>}
     */
    public static function estadoInicial(Producto $producto): array
    {
        $filas = [];

        foreach (self::presentaciones($producto) as $presentacion) {
            $fijo = $presentacion->precio_fijo;

            $filas[] = [
                'id'          => $presentacion->id,
                'modo'        => $fijo ? self::FIJO : self::CALCULADO,
                'margen'      => $fijo ? self::margenReal($producto->costo_unidad, $presentacion) : self::regla($presentacion, $producto),
                'precio_base' => $presentacion->precio,
            ];
        }

        return [
            'corregir'   => false,
            'costo_base' => $producto->costo_unidad,
            'filas'      => $filas,
        ];
    }

    /**
     * Las que se venden, la más grande primero (si ninguna se vende, todas).
     *
     * @return list<Presentacion>
     */
    public static function presentaciones(Producto $producto): array
    {
        $producto->loadMissing(['categoria', 'presentaciones.unidadMedida']);
        $activas = $producto->presentacionesActivas();
        $lista = $activas->isEmpty() ? $producto->presentaciones : $activas;

        return array_values($lista->sortByDesc('contenido')->all());
    }

    /**
     * Lo que la vista necesita del producto para dibujar y hacer las cuentas.
     *
     * @return array<string, mixed>
     */
    public function getDatos(): array
    {
        $producto = $this->getRecord();

        if (! $producto instanceof Producto) {
            return [];
        }

        $presentaciones = [];

        foreach (self::presentaciones($producto) as $presentacion) {
            $presentaciones[$presentacion->id] = [
                'id'        => $presentacion->id,
                'sigla'     => $presentacion->sigla(),
                'nombre'    => $presentacion->nombre(),
                'contenido' => $presentacion->contenido,
                'precioHoy' => $presentacion->precio,
                'regla'     => self::regla($presentacion, $producto),
            ];
        }

        $ultima = CompraItem::query()
            ->with(['compra', 'presentacion.unidadMedida'])
            ->where('producto_id', $producto->id)
            ->latest('id')
            ->first();

        return [
            'gravado'        => $producto->gravado,
            'tasa'           => CalculadoraPrecio::tasaIsv(),
            'simbolo'        => (string) config('honduras.moneda.simbolo', 'L.'),
            'base'           => $producto->presentacionBase()->sigla(),
            'costoHoy'       => $producto->costo_unidad,
            'presentaciones' => (object) $presentaciones,
            'existencia'     => $producto->existenciaEnTexto((int) Existencia::query()->where('producto_id', $producto->id)->sum('cantidad')),
            'ultima'         => $ultima instanceof CompraItem ? [
                'monto'     => $ultima->costo_ingresado,
                'sigla'     => $ultima->presentacion->sigla(),
                'contenido' => $ultima->presentacion->contenido,
                'fecha'     => $ultima->compra->fecha->format('d/m/Y'),
                'numero'    => (string) $ultima->compra->numero,
            ] : null,
            'sospechoso' => self::CAMBIO_SOSPECHOSO,
        ];
    }

    /**
     * El % con que se calcula (el de la presentación o el del producto), 14 = 14 %.
     */
    private static function regla(Presentacion $presentacion, Producto $producto): float
    {
        return round(($presentacion->margen ?? $producto->margenAplicado()) * 100, 2);
    }

    /**
     * El % que de verdad deja su precio con ese costo; null sin costo o sin precio.
     */
    private static function margenReal(float $costoBase, Presentacion $presentacion): ?float
    {
        if ($costoBase <= 0 || $presentacion->precio <= 0) {
            return null;
        }

        return round(CalculadoraPrecio::margenReal(round($costoBase * $presentacion->contenido, 4), $presentacion->precio) * 100, 2);
    }
}
