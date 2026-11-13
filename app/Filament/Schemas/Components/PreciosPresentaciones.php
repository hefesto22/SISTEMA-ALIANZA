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
 * Arriba, para leer: la existencia de cada presentación y la última
 * compra; "Corregir costos" deja escribir el costo de cada una, solo para
 * cuando está mal. Abajo, una fila por presentación: SU costo promedio (con
 * y sin ISV), % de utilidad, precio (con ISV si es gravado), cuánto gana y si el
 * precio es "Con %" (sale del costo con ese % y lo mueven las compras) o
 * "Fijo" (lo pone la empresa; las compras no lo mueven), con una línea que
 * lo explica con los números de esa fila. En "Con %" solo se escribe el %
 * (el precio sale solo); en "Fijo" solo el precio (el % muestra lo que
 * deja); se cambia con los botones. Las cuentas pasan en el navegador
 * mientras se escribe; al guardar, ActualizarPrecioAction las revisa y
 * ActualizarPrecioProducto las guarda.
 *
 * Estado: array{corregir: bool, costos: array<int, float>, filas:
 * list<array{id: int, modo: string, margen: float|null, precio_base:
 * float|null}>} con el costo de cada presentación y los precios SIN ISV;
 * margen en % (14 = 14 %).
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
     * Lo que abre el modal: el costo de hoy de cada presentación y una fila por cada una.
     *
     * @return array{corregir: bool, costos: array<int, float>, filas: list<array{id: int, modo: string, margen: float|null, precio_base: float}>}
     */
    public static function estadoInicial(Producto $producto): array
    {
        $filas = [];
        $costos = [];

        foreach (self::presentaciones($producto) as $presentacion) {
            $fijo = $presentacion->precio_fijo;
            $costos[$presentacion->id] = $presentacion->costo;

            $filas[] = [
                'id'          => $presentacion->id,
                'modo'        => $fijo ? self::FIJO : self::CALCULADO,
                'margen'      => $fijo ? self::margenReal($presentacion) : self::regla($presentacion, $producto),
                'precio_base' => $presentacion->precio,
            ];
        }

        return [
            'corregir' => false,
            'costos'   => $costos,
            'filas'    => $filas,
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
        $hay = Existencia::porPresentacion(null, $producto->id);

        foreach (self::presentaciones($producto) as $presentacion) {
            $presentaciones[$presentacion->id] = [
                'id'         => $presentacion->id,
                'sigla'      => $presentacion->sigla(),
                'nombre'     => $presentacion->nombre(),
                'contenido'  => $presentacion->contenido,
                'precioHoy'  => $presentacion->precio,
                'costoHoy'   => $presentacion->costo,
                'existencia' => $hay[$presentacion->id] ?? 0,
                'regla'      => self::regla($presentacion, $producto),
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
            'presentaciones' => (object) $presentaciones,
            'existencia'     => $producto->existenciaPorPresentacion($hay),
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
     * El % que de verdad deja su precio con su costo; null sin costo o sin precio.
     */
    private static function margenReal(Presentacion $presentacion): ?float
    {
        if ($presentacion->costo <= 0 || $presentacion->precio <= 0) {
            return null;
        }

        return round(CalculadoraPrecio::margenReal($presentacion->costo, $presentacion->precio) * 100, 2);
    }
}
