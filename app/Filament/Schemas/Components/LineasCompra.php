<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use App\Domain\Precios\CalculadoraPrecio;
use App\Models\Configuracion;
use App\Models\Existencia;
use App\Models\Presentacion;
use App\Models\Producto;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Renderless;

/**
 * Productos de una compra como lista tipo carrito, como en la preventa:
 * arriba un buscador con los productos del proveedor escogido; se toca uno
 * y entra a la lista en su presentación más grande. Cada fila: en qué
 * presentación viene (CJ de 12, CJ de 8, UN…), cantidad con − y +, el costo
 * con ISV como viene en la factura (vacío: se escribe el de la factura) y
 * el total de la línea. Abajo de cada una, cómo queda: "Costo CJ12: está a
 * L X → quedará a L Y" y lo mismo del precio de venta (el fijo se queda),
 * con la misma cuenta que RegistrarCompra (promedio o último, según
 * Configuración). Si el costo se aleja mucho del de hoy, avisa (error de
 * dedo). Abajo, la barra con el total y "Registrar compra".
 *
 * Estado: list<array{producto_id: int, presentacion_id: int, cantidad: int, costo: float}>,
 * igual que lo lee CompraForm::lineas() y lo recibe RegistrarCompra.
 *
 * Vista: resources/views/filament/compras/lineas-compra.blade.php
 */
class LineasCompra extends Field
{
    public const MAXIMO_LINEAS = 300;

    public const MAXIMO_CANTIDAD = 99999;

    /** @var view-string */
    protected string $view = 'filament.compras.lineas-compra';

    protected function setUp(): void
    {
        parent::setUp();

        $this->default([]);
        $this->required();
        $this->rule('array');
        $this->rule('max:'.self::MAXIMO_LINEAS);
        $this->validationMessages([
            'required' => 'Agregue al menos un producto.',
            'max'      => 'Una compra puede tener hasta '.self::MAXIMO_LINEAS.' productos.',
        ]);
        // Aquí y no por línea: el error de una línea no se vería en la lista.
        $this->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            $sinCosto = self::sinCosto($value);

            if ($sinCosto !== []) {
                $fail('Falta el costo de '.implode(', ', $sinCosto).'.');
            }
        });
    }

    /**
     * @param array<string, array<mixed>> $rules
     */
    public function dehydrateValidationRules(array &$rules): void
    {
        parent::dehydrateValidationRules($rules);

        $statePath = $this->getStatePath();

        $rules["{$statePath}.*.producto_id"] = ['required', 'integer', Rule::exists('productos', 'id')];
        $rules["{$statePath}.*.presentacion_id"] = ['required', 'integer', Rule::exists('presentaciones', 'id')];
        $rules["{$statePath}.*.cantidad"] = ['required', 'integer', 'min:1', 'max:'.self::MAXIMO_CANTIDAD];
        $rules["{$statePath}.*.costo"] = ['required', 'numeric', 'min:0.01'];
    }

    /**
     * Buscador: productos activos del proveedor escogido por código o nombre
     * (vacío: los primeros por nombre). Primero el código exacto.
     *
     * @return list<array<string, mixed>>
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function buscarProductos(string $buscar): array
    {
        $this->getLivewire()->skipRender();
        $proveedor = $this->makeGetUtility()('proveedor_id');

        if (! is_numeric($proveedor)) {
            return [];
        }

        $buscar = trim($buscar);

        $productos = Producto::query()
            ->activos()
            ->where('proveedor_id', (int) $proveedor)
            ->buscar($buscar)
            ->orderByRaw('CASE WHEN UPPER(codigo) = ? THEN 0 ELSE 1 END', [mb_strtoupper($buscar)])
            ->orderBy('descripcion')
            ->limit(30)
            ->with(['categoria', 'presentaciones.unidadMedida'])
            ->get();

        $existencias = self::existencias($productos->modelKeys());

        return array_values($productos
            ->map(fn (Producto $producto): array => self::datosDe($producto, $existencias[$producto->id] ?? 0))
            ->all());
    }

    /**
     * Datos de los productos que ya están en las líneas (al volver de un
     * error), para dibujarlas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProductosDeLineas(): array
    {
        $ids = [];

        foreach (is_array($this->getState()) ? $this->getState() : [] as $linea) {
            if (is_array($linea) && is_numeric($linea['producto_id'] ?? null)) {
                $ids[] = (int) $linea['producto_id'];
            }
        }

        $productos = [];
        $existencias = self::existencias(array_values(array_unique($ids)));

        foreach (Producto::query()->whereKey(array_unique($ids))->with(['categoria', 'presentaciones.unidadMedida'])->get() as $producto) {
            $productos[$producto->id] = self::datosDe($producto, $existencias[$producto->id] ?? 0);
        }

        return $productos;
    }

    /**
     * Nombres de los productos que no traen costo (o traen 0).
     *
     * @return list<string>
     */
    public static function sinCosto(mixed $lineas): array
    {
        $ids = [];

        foreach (is_array($lineas) ? $lineas : [] as $linea) {
            if (! is_array($linea) || ! is_numeric($linea['producto_id'] ?? null)) {
                continue;
            }

            if (! is_numeric($linea['costo'] ?? null) || (float) $linea['costo'] <= 0) {
                $ids[] = (int) $linea['producto_id'];
            }
        }

        if ($ids === []) {
            return [];
        }

        /** @var list<string> $nombres */
        $nombres = Producto::query()->whereKey(array_unique($ids))->orderBy('descripcion')->pluck('descripcion')->all();

        return $nombres;
    }

    /**
     * Lo que el teléfono necesita de un producto: nombre, si es gravado, sus
     * presentaciones que se compran (la más grande primero) con su costo y
     * precio de hoy CON ISV (si es gravado), y lo necesario para sacar cómo
     * quedan con la compra igual que RegistrarCompra: el costo por unidad
     * sin ISV, lo que hay en todas las bodegas, el % de utilidad y si el
     * precio es fijo.
     *
     * @return array<string, mixed>
     */
    public static function datosDe(Producto $producto, int $existencia = 0): array
    {
        $presentaciones = [];

        foreach ($producto->presentacionesActivas()->sortByDesc('contenido') as $presentacion) {
            $presentaciones[] = self::presentacionDe($presentacion, $producto);
        }

        return [
            'id'             => $producto->id,
            'codigo'         => $producto->codigo,
            'descripcion'    => $producto->descripcion,
            'gravado'        => $producto->gravado,
            'proveedor_id'   => $producto->proveedor_id,
            'presentaciones' => $presentaciones,
            'costoUnidad'    => $producto->costo_unidad,
            'existencia'     => $existencia,
            'margen'         => $producto->margenAplicado(),
        ];
    }

    /**
     * Una presentación como la usa la compra: con su costo y precio de hoy.
     *
     * @return array<string, mixed>
     */
    private static function presentacionDe(Presentacion $presentacion, Producto $producto): array
    {
        return [
            'id'        => $presentacion->id,
            'sigla'     => $presentacion->sigla(),
            'nombre'    => $presentacion->nombre(),
            'plural'    => $presentacion->palabra(2),
            'contenido' => $presentacion->contenido,
            'fijo'      => $presentacion->precio_fijo,
            'costoHoy'  => $producto->costo_unidad > 0 ? CalculadoraPrecio::aPantalla($producto->costo_unidad, $producto->gravado, $presentacion->contenido) : 0.0,
            'precioHoy' => round(CalculadoraPrecio::conIsv($presentacion->precio, $producto->gravado), 2),
        ];
    }

    /**
     * Lo que hay de cada producto en TODAS las bodegas (el costo promedio es
     * uno solo): [producto_id => unidades base].
     *
     * @param array<mixed> $ids
     *
     * @return array<int, int>
     */
    private static function existencias(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var array<int, int> $hay */
        $hay = Existencia::query()
            ->whereIn('producto_id', $ids)
            ->groupBy('producto_id')
            ->selectRaw('producto_id, SUM(cantidad) as total')
            ->pluck('total', 'producto_id')
            ->map(fn (mixed $cantidad): int => (int) $cantidad)
            ->all();

        return $hay;
    }

    /**
     * Para la cuenta de "quedará a": promedio o último costo (Configuración).
     */
    public static function metodoCosto(): string
    {
        return Configuracion::actual()->metodo_costo->value;
    }
}
