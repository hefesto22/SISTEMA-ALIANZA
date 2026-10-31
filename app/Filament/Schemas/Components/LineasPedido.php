<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use App\Domain\Inventario\Disponible;
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
use Closure;
use Filament\Forms\Components\Contracts\HasNestedRecursiveValidationRules;
use Filament\Forms\Components\Field;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
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
 * Existencia: cada producto trae cuánto hay DISPONIBLE en la bodega del
 * pedido: lo que hay menos lo apartado en otros pedidos pendientes
 * (App\Domain\Inventario\Disponible), así no se vende dos veces lo mismo.
 * Con "controlar existencias" encendido (Configuración) lo que no tiene
 * disponible se ve en el buscador pero no se agrega, y no se pide (ni se
 * guarda) más de lo disponible.
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

        // Controlando la existencia, no se pide más de lo disponible.
        $this->rule(fn (LineasPedido $component): Closure => function (string $attribute, mixed $value, Closure $fail) use ($component): void {
            $aviso = $component->pasaDeLoDisponible(self::normalizar($value));

            if ($aviso !== null) {
                $fail($aviso);
            }
        });

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
     * precios y lo disponible en la bodega del pedido (administración sin
     * bodega escogida: lo de todas). Primero los que tienen disponible,
     * después el código exacto y luego por nombre. Los que no tienen salen
     * igual, marcados: si se controla la existencia no se pueden agregar (y
     * sin bodega escogida no sale ninguno).
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

        // Con lo que hay en la bodega y lo apartado en pedidos pendientes, para
        // ordenar (y filtrar) por lo disponible.
        $conDisponible = Disponible::conColumnas(Producto::query()->activos()->buscar($buscar), $bodega, $this->pedidoQueSeEdita());

        $productos = Producto::query()
            ->fromSub($conDisponible, 'productos')
            ->orderByRaw('CASE WHEN existencia_bodega - apartado > 0 THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN UPPER(codigo) = ? THEN 0 ELSE 1 END', [mb_strtoupper($buscar)])
            ->orderBy('descripcion')
            ->limit(25)
            ->get();

        return array_values($productos
            ->map(fn (Producto $producto): array => self::datosDe(
                $producto,
                self::entero($producto->getAttribute('existencia_bodega')),
                self::entero($producto->getAttribute('apartado')),
            ))
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
     * El último pedido de este cliente que le toca a quien levanta la
     * preventa (el vendedor: uno suyo, aunque ya esté validado), para agregar
     * los mismos productos de un toque (las rutas se repiten cada semana).
     * Con los precios de hoy; los productos que ya no están activos no se
     * agregan.
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
            ->delAlcanceDe($usuario)
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
        $ids = $pedido->items->pluck('producto_id')->all();
        $hay = Disponible::existencia($bodega, $ids);
        $apartado = Disponible::apartado($bodega, $ids, $this->pedidoQueSeEdita());

        foreach ($pedido->items as $item) {
            $enBodega = $hay[$item->producto_id] ?? 0;
            $disponible = $enBodega - ($apartado[$item->producto_id] ?? 0);

            // Lo que bodega agregó al validar (pidió 0) no es del pedido del cliente.
            if ($item->cantidad < 1) {
                continue;
            }

            // No se vende o (si se controla la existencia) ya no hay disponible.
            if (! $item->producto->activo || ($controlar && $disponible < 1)) {
                $fuera++;

                continue;
            }

            $lineas[] = ['producto_id' => $item->producto_id, 'unidad' => $item->unidad->value, 'cantidad' => $item->cantidad];
            $productos[$item->producto_id] = self::datosDe($item->producto, $enBodega, $apartado[$item->producto_id] ?? 0);
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
        $hay = Disponible::existencia($bodega, $ids);
        $apartado = Disponible::apartado($bodega, $ids, $this->pedidoQueSeEdita());

        foreach (Producto::query()->whereKey($ids)->get() as $producto) {
            $productos[$producto->id] = self::datosDe($producto, $hay[$producto->id] ?? 0, $apartado[$producto->id] ?? 0);
        }

        return $productos;
    }

    /**
     * Lo que el teléfono necesita de un producto para mostrarlo y sacar su
     * subtotal igual que el servidor (precio sin ISV × cantidad, más ISV).
     * "hay" es lo disponible: lo de la bodega menos lo apartado en otros
     * pedidos pendientes.
     *
     * @return array<string, mixed>
     */
    public static function datosDe(Producto $producto, ?int $enBodega = null, int $apartado = 0): array
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
            // En unidades, en la bodega del pedido (o en todas, sin bodega escogida).
            'hay'       => $enBodega === null ? null : $enBodega - $apartado,
            'en_bodega' => $enBodega,
            'apartado'  => $apartado,
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
     * El pedido que se está editando (sus líneas no cuentan como apartadas
     * contra él mismo); null al crear.
     */
    public function pedidoQueSeEdita(): ?int
    {
        $pedido = $this->getRecord();

        return $pedido instanceof Pedido && $pedido->exists ? $pedido->id : null;
    }

    /**
     * Con "controlar existencias": el aviso del primer producto que pasa de
     * lo disponible en la bodega ("No alcanza ACEITE…: disponible 1 CJ + 3
     * UN."); null si alcanza todo o no se controla.
     *
     * @param list<array{id: int|null, producto_id: int, unidad: string, cantidad: int}> $lineas
     */
    public function pasaDeLoDisponible(array $lineas): ?string
    {
        $bodega = $this->bodegaDelPedido();

        if ($lineas === [] || $bodega === null || ! Configuracion::actual()->controlar_existencias) {
            return null;
        }

        $productos = Producto::query()->whereKey(array_column($lineas, 'producto_id'))->get()->keyBy('id');
        $pide = [];

        foreach ($lineas as $linea) {
            $producto = $productos->get($linea['producto_id']);
            $unidad = UnidadVenta::tryFrom($linea['unidad']);

            if ($producto instanceof Producto && $unidad !== null) {
                $pide[$producto->id] = ($pide[$producto->id] ?? 0) + $linea['cantidad'] * $producto->unidadesEn($unidad);
            }
        }

        $disponible = Disponible::en($bodega, array_keys($pide), $this->pedidoQueSeEdita());

        foreach ($pide as $id => $unidades) {
            $producto = $productos->get($id);
            $hay = $disponible[$id] ?? 0;

            if ($producto instanceof Producto && $unidades > $hay) {
                return "No alcanza {$producto->descripcion}: disponible ".Existencia::enCajas(max(0, $hay), $producto->unidades_por_caja).'.';
            }
        }

        return null;
    }

    private static function entero(mixed $valor): int
    {
        return is_numeric($valor) ? (int) $valor : 0;
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
