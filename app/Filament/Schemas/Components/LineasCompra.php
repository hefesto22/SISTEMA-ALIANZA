<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use App\Domain\Precios\CalculadoraPrecio;
use App\Enums\UnidadVenta;
use App\Models\Producto;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Renderless;

/**
 * Productos de una compra como lista tipo carrito, como en la preventa:
 * arriba un buscador con los productos del proveedor escogido; se toca uno
 * y entra a la lista con el costo de hoy ya puesto. Cada fila: caja o
 * unidad, cantidad con − y +, costo con ISV como viene en la factura y el
 * total de la línea; si el costo se aleja mucho del de hoy, avisa (error de
 * dedo). Abajo, la barra con el total y "Registrar compra".
 *
 * Estado: list<array{producto_id: int, unidad: string, cantidad: int, costo: float}>,
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
        $rules["{$statePath}.*.unidad"] = ['required', Rule::enum(UnidadVenta::class)];
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

        return array_values(Producto::query()
            ->activos()
            ->where('proveedor_id', (int) $proveedor)
            ->buscar($buscar)
            ->orderByRaw('CASE WHEN UPPER(codigo) = ? THEN 0 ELSE 1 END', [mb_strtoupper($buscar)])
            ->orderBy('descripcion')
            ->limit(30)
            ->get()
            ->map(fn (Producto $producto): array => self::datosDe($producto))
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

        foreach (Producto::query()->whereKey(array_unique($ids))->get() as $producto) {
            $productos[$producto->id] = self::datosDe($producto);
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
     * Lo que el teléfono necesita de un producto: nombre, caja, si es gravado
     * y el costo de hoy CON ISV (si es gravado) por unidad y por caja, como
     * viene en una factura.
     *
     * @return array<string, mixed>
     */
    public static function datosDe(Producto $producto): array
    {
        return [
            'id'           => $producto->id,
            'codigo'       => $producto->codigo,
            'descripcion'  => $producto->descripcion,
            'caja'         => $producto->unidades_por_caja,
            'gravado'      => $producto->gravado,
            'proveedor_id' => $producto->proveedor_id,
            'costoUnidad'  => round(CalculadoraPrecio::aPantalla($producto->costo_unidad, $producto->gravado), 2),
            'costoCaja'    => round(CalculadoraPrecio::aPantalla($producto->costo_unidad, $producto->gravado, $producto->unidades_por_caja), 2),
        ];
    }
}
