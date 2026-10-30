<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use App\Domain\Precios\CalculadoraPrecio;
use App\Enums\EstadoPedido;
use App\Enums\UnidadVenta;
use App\Filament\Resources\Pedidos\Schemas\PedidoForm;
use App\Filament\Support\Acceso;
use App\Models\Configuracion;
use App\Models\Existencia;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Producto;
use Filament\Forms\Components\Contracts\HasNestedRecursiveValidationRules;
use Filament\Forms\Components\Field;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Renderless;

/**
 * Productos de la preventa como lista tipo carrito, pensada para el
 * teléfono y para pedidos largos (30 productos o más).
 *
 * Arriba un buscador: se toca un producto y entra a la lista (si ya está,
 * le suma uno). Cada línea es una fila corta con unidad o caja, − y +,
 * subtotal y quitar. Todo eso pasa en el teléfono, sin ir al servidor por
 * cada toque; al servidor solo van la búsqueda, el total con promociones
 * (cada que se deja de tocar) y Guardar.
 *
 * Estado: list<array{id?: int, producto_id: int, unidad: string, cantidad: int}>.
 * Se guarda en pedido_items (crea, cambia y quita líneas) y después
 * RecalcularPedido pone los precios.
 *
 * Existencia: cada producto trae cuánto hay en la bodega del pedido. Con
 * "controlar existencias" encendido (Configuración) el buscador solo
 * muestra lo que tiene existencia y en el teléfono no se pide más de lo que hay.
 *
 * Vista: resources/views/filament/pedidos/lineas-pedido.blade.php (también
 * trae la barra de abajo con el total y Guardar).
 */
class LineasPedido extends Field implements HasNestedRecursiveValidationRules
{
    /** Más líneas que esto en un solo pedido es un error de dedo. */
    public const MAXIMO_LINEAS = 200;

    public const MAXIMO_CANTIDAD = 99999;

    /** @var view-string */
    protected string $view = 'filament.pedidos.lineas-pedido';

    protected function setUp(): void
    {
        parent::setUp();

        $this->default([]);
        $this->required();
        $this->rule('array');
        $this->rule('max:'.self::MAXIMO_LINEAS);
        $this->validationMessages([
            'required' => 'Agregue al menos un producto.',
            'max'      => 'Un pedido puede tener hasta '.self::MAXIMO_LINEAS.' productos.',
        ]);

        // No es una columna del pedido: se guarda aparte, en sus líneas.
        $this->dehydrated(false);

        $this->loadStateFromRelationshipsUsing(function (LineasPedido $component, Pedido $record): void {
            $component->state($record->items()->orderBy('id')->get()
                ->map(fn (PedidoItem $item): array => [
                    'id'          => $item->id,
                    'producto_id' => $item->producto_id,
                    'unidad'      => $item->unidad->value,
                    'cantidad'    => $item->cantidad,
                ])
                ->values()
                ->all());
        });

        $this->saveRelationshipsUsing(function (LineasPedido $component, Pedido $record): void {
            self::guardar($record, self::normalizar($component->getState()));
        });
    }

    /**
     * Cada línea: producto que existe, unidad o caja, cantidad entera de 1 en adelante.
     *
     * @return array<mixed>
     */
    public function getNestedRecursiveValidationRules(): array
    {
        return ['array'];
    }

    /**
     * @param array<string, array<mixed>> $rules
     */
    public function dehydrateValidationRules(array &$rules): void
    {
        parent::dehydrateValidationRules($rules);

        $statePath = $this->getStatePath();

        $rules["{$statePath}.*.id"] = ['nullable', 'integer'];
        $rules["{$statePath}.*.producto_id"] = ['required', 'integer', Rule::exists('productos', 'id')];
        $rules["{$statePath}.*.unidad"] = ['required', Rule::enum(UnidadVenta::class)];
        $rules["{$statePath}.*.cantidad"] = ['required', 'integer', 'min:1', 'max:'.self::MAXIMO_CANTIDAD];
    }

    /**
     * Buscador de arriba: productos activos por código o nombre, con sus
     * precios y lo que hay en la bodega del pedido. Si se controla la
     * existencia, solo los que tienen; sin bodega escogida, ninguno.
     *
     * @return list<array<string, mixed>>
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function buscarProductos(string $buscar): array
    {
        $this->sinRedibujar();
        $buscar = trim($buscar);
        $bodega = $this->bodegaDelPedido();
        $controlar = Configuracion::actual()->controlar_existencias;

        if ($buscar === '' || ($controlar && $bodega === null)) {
            return [];
        }

        $productos = Producto::query()
            ->activos()
            ->buscar($buscar)
            ->when($controlar, fn (Builder $query): Builder => $query->whereHas('existencias', fn (Builder $existencias): Builder => $existencias
                ->where('bodega_id', $bodega)
                ->where('cantidad', '>', 0)))
            ->orderBy('descripcion')
            ->limit(25)
            ->get();

        $hay = self::existenciasEn($bodega, $productos->modelKeys());

        return array_values($productos
            ->map(fn (Producto $producto): array => self::datosDe($producto, $bodega === null ? null : ($hay[$producto->id] ?? 0)))
            ->all());
    }

    /**
     * Total con promociones de las líneas que tiene el teléfono (se pide
     * cuando se deja de tocar). Lo mismo que verá bodega al despachar.
     *
     * @param array<mixed> $lineas
     *
     * @return array{total: float, ahorro: float, promociones: string}
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function resumen(array $lineas): array
    {
        $this->sinRedibujar();

        return PedidoForm::resumenDe(self::normalizar($lineas));
    }

    /**
     * El último pedido de este cliente que puede ver quien levanta la
     * preventa, para agregar los mismos productos de un toque (las rutas se
     * repiten cada semana). Con los precios de hoy; los productos que ya no
     * están activos no se agregan.
     *
     * @return array{numero: string, fecha: string, lineas: list<array{producto_id: int, unidad: string, cantidad: int}>, productos: array<int, array<string, mixed>>, fuera: int}|null
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function ultimoPedido(int $clienteId): ?array
    {
        $this->sinRedibujar();
        $usuario = Acceso::usuario();

        if ($usuario === null || $clienteId < 1) {
            return null;
        }

        $pedido = Pedido::query()
            ->visiblePara($usuario)
            ->where('cliente_id', $clienteId)
            ->where('estado', '!=', EstadoPedido::Anulado)
            ->whereHas('items')
            ->with('items.producto')
            ->latest('id')
            ->first();

        if (! $pedido instanceof Pedido) {
            return null;
        }

        $lineas = [];
        $productos = [];
        $fuera = 0;
        $bodega = $this->bodegaDelPedido();
        $controlar = Configuracion::actual()->controlar_existencias;
        $hay = self::existenciasEn($bodega, $pedido->items->pluck('producto_id')->all());

        foreach ($pedido->items as $item) {
            $existencia = $bodega === null ? null : ($hay[$item->producto_id] ?? 0);

            // No se vende o (si se controla la existencia) ya no hay en la bodega.
            if (! $item->producto->activo || ($controlar && ($existencia ?? 0) < 1)) {
                $fuera++;

                continue;
            }

            $lineas[] = ['producto_id' => $item->producto_id, 'unidad' => $item->unidad->value, 'cantidad' => $item->cantidad];
            $productos[$item->producto_id] = self::datosDe($item->producto, $existencia);
        }

        return [
            'numero'    => $pedido->numero ?? '',
            'fecha'     => $pedido->fecha->format('d/m'),
            'lineas'    => $lineas,
            'productos' => $productos,
            'fuera'     => $fuera,
        ];
    }

    /**
     * La búsqueda y el total no vuelven a dibujar la página: la lista vive en
     * el teléfono. Sin esto, como el pedido va junto con la llamada, Livewire
     * mandaría la página entera de regreso en cada una.
     */
    private function sinRedibujar(): void
    {
        $this->getLivewire()->skipRender();
    }

    /**
     * Datos de los productos que ya están en las líneas (al editar o al volver
     * de un error), para dibujarlas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProductosDeLineas(): array
    {
        $ids = array_values(array_unique(array_map(
            fn (array $linea): int => $linea['producto_id'],
            self::normalizar($this->getState()),
        )));

        if ($ids === []) {
            return [];
        }

        $productos = [];
        $bodega = $this->bodegaDelPedido();
        $hay = self::existenciasEn($bodega, $ids);

        foreach (Producto::query()->whereKey($ids)->get() as $producto) {
            $productos[$producto->id] = self::datosDe($producto, $bodega === null ? null : ($hay[$producto->id] ?? 0));
        }

        return $productos;
    }

    /**
     * Lo que el teléfono necesita de un producto para mostrarlo y sacar su
     * subtotal igual que el servidor (precio sin ISV × cantidad, más ISV).
     *
     * @return array<string, mixed>
     */
    public static function datosDe(Producto $producto, ?int $hay = null): array
    {
        $unidad = $producto->precioPara(UnidadVenta::Unidad);
        $caja = $producto->precioPara(UnidadVenta::Caja);

        return [
            'id'              => $producto->id,
            'codigo'          => $producto->codigo,
            'descripcion'     => $producto->descripcion,
            'caja'            => $producto->unidades_por_caja,
            'gravado'         => $producto->gravado,
            'activo'          => $producto->activo,
            'precioUnidad'    => $unidad,
            'precioCaja'      => $caja,
            'precioUnidadIsv' => self::comoEnElPedido($unidad, $producto->gravado),
            'precioCajaIsv'   => self::comoEnElPedido($caja, $producto->gravado),
            // En unidades, en la bodega del pedido (null = sin bodega escogida).
            'hay' => $hay,
        ];
    }

    /**
     * La bodega que despacha: la del usuario (vendedor y encargado) o la que
     * escogió administración en el pedido.
     */
    public function bodegaDelPedido(): ?int
    {
        $usuario = Acceso::usuario();

        if ($usuario !== null && ! $usuario->veTodasLasBodegas()) {
            return $usuario->bodega_id;
        }

        $bodega = $this->makeGetUtility()('bodega_id');

        return is_numeric($bodega) ? (int) $bodega : null;
    }

    /**
     * [producto_id => unidades] en la bodega.
     *
     * @param array<mixed> $productoIds
     *
     * @return array<int, int>
     */
    private static function existenciasEn(?int $bodega, array $productoIds): array
    {
        if ($bodega === null || $productoIds === []) {
            return [];
        }

        /** @var array<int, int> $hay */
        $hay = Existencia::query()
            ->where('bodega_id', $bodega)
            ->whereIn('producto_id', $productoIds)
            ->pluck('cantidad', 'producto_id')
            ->map(fn (mixed $cantidad): int => (int) $cantidad)
            ->all();

        return $hay;
    }

    /**
     * Lo que cuesta 1 (unidad o caja) con ISV, redondeado igual que la línea
     * del pedido (precio a 2 decimales y el ISV sobre eso). Así el precio que
     * se ve y el subtotal de 1 nunca difieren por un centavo.
     */
    private static function comoEnElPedido(float $precio, bool $gravado): float
    {
        $bruto = round($precio, 2);

        return round($bruto + ($gravado ? round($bruto * CalculadoraPrecio::tasaIsv(), 2) : 0.0), 2);
    }

    /**
     * Deja solo líneas bien formadas: producto, unidad válida y cantidad de 1
     * en adelante. Lo demás que mande el navegador se ignora. Un producto
     * lleva a lo más una línea en unidad y otra en caja: si viene repetido, se
     * suma (y se queda el id de la primera).
     *
     * @return list<array{id: int|null, producto_id: int, unidad: string, cantidad: int}>
     */
    public static function normalizar(mixed $estado): array
    {
        if (! is_array($estado)) {
            return [];
        }

        $lineas = [];

        foreach ($estado as $linea) {
            if (! is_array($linea) || ! is_numeric($linea['producto_id'] ?? null)) {
                continue;
            }

            $unidad = $linea['unidad'] ?? null;
            $unidad = $unidad instanceof UnidadVenta ? $unidad : UnidadVenta::tryFrom(is_string($unidad) ? $unidad : '');
            $cantidad = is_numeric($linea['cantidad'] ?? null) ? (int) $linea['cantidad'] : 0;

            if ($unidad === null || $cantidad < 1) {
                continue;
            }

            $clave = ((int) $linea['producto_id']).'-'.$unidad->value;

            if (isset($lineas[$clave])) {
                $lineas[$clave]['cantidad'] = min($lineas[$clave]['cantidad'] + $cantidad, self::MAXIMO_CANTIDAD);

                continue;
            }

            $lineas[$clave] = [
                'id'          => is_numeric($linea['id'] ?? null) ? (int) $linea['id'] : null,
                'producto_id' => (int) $linea['producto_id'],
                'unidad'      => $unidad->value,
                'cantidad'    => min($cantidad, self::MAXIMO_CANTIDAD),
            ];
        }

        return array_values($lineas);
    }

    /**
     * Pasa las líneas a pedido_items: cambia las que ya estaban (solo las de
     * este pedido), crea las nuevas y quita las que ya no vienen.
     *
     * @param list<array{id: int|null, producto_id: int, unidad: string, cantidad: int}> $lineas
     */
    private static function guardar(Pedido $pedido, array $lineas): void
    {
        $actuales = $pedido->items()->get()->keyBy('id');
        $quedan = [];

        foreach ($lineas as $linea) {
            $datos = [
                'producto_id' => $linea['producto_id'],
                'unidad'      => $linea['unidad'],
                'cantidad'    => $linea['cantidad'],
            ];

            $item = $linea['id'] !== null ? $actuales->get($linea['id']) : null;

            if ($item instanceof PedidoItem && ! in_array($item->id, $quedan, true)) {
                $item->fill($datos)->save();
            } else {
                $item = $pedido->items()->create($datos);
            }

            $quedan[] = $item->id;
        }

        $pedido->items()->whereKeyNot($quedan)->delete();
        $pedido->unsetRelation('items');
    }
}
