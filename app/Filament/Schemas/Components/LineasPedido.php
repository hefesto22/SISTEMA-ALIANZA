<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use App\Domain\Inventario\Disponible;
use App\Domain\Precios\CalculadoraPrecio;
use App\Enums\EstadoPedido;
use App\Filament\Resources\Pedidos\Schemas\PedidoForm;
use App\Filament\Support\Acceso;
use App\Models\Configuracion;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\Promocion;
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
 * le suma uno). Cada producto es una fila corta; al abrirla, un − y + por
 * cada presentación que se vende (UN, CJ12, FD6…), subtotal y quitar. Todo eso pasa en el teléfono, sin ir al servidor por
 * cada toque; al servidor solo van la búsqueda, el total con promociones
 * (cada que se deja de tocar) y Guardar.
 *
 * Estado: list<array{id?: int, producto_id: int, presentacion_id: int, cantidad: int}>.
 * Se guarda en pedido_items (crea, cambia y quita líneas) y después
 * RecalcularPedido pone los precios.
 *
 * Existencia: cada producto trae cuánto hay DISPONIBLE en la bodega del
 * pedido: lo que hay menos lo apartado en otros pedidos pendientes
 * (App\Domain\Inventario\Disponible), así no se vende dos veces lo mismo;
 * en total (unidades base) y de cada presentación ("2 CJ48 · 5 UN"): una
 * caja solo sale de sus cajas; las unidades sueltas, de las sueltas o de
 * abrir cajas. Con "controlar existencias" encendido (Configuración) lo que
 * no tiene disponible se ve en el buscador pero no se agrega, y no se pide
 * (ni se guarda) más de lo disponible.
 *
 * Promociones: las vigentes hoy en la bodega del pedido van arriba de la
 * lista para agregarlas de un toque (lo que pide la promoción más el
 * regalo). Si el vendedor toca un producto que está en una promoción, se le
 * pregunta si agrega la promoción o solo el producto.
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

        // Cada línea en una presentación que se vende (activa y con precio).
        $this->rule(fn (LineasPedido $component): Closure => function (string $attribute, mixed $value, Closure $fail) use ($component): void {
            $aviso = $component->presentacionQueNoSeVende(self::normalizar($value));

            if ($aviso !== null) {
                $fail($aviso);
            }
        });

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
                    'id'              => $item->id,
                    'producto_id'     => $item->producto_id,
                    'presentacion_id' => $item->presentacion_id,
                    'cantidad'        => $item->cantidad,
                ])
                ->values()
                ->all());
        });

        $this->saveRelationshipsUsing(function (LineasPedido $component, Pedido $record): void {
            self::guardar($record, self::normalizar($component->getState()));
        });
    }

    /**
     * Cada línea: producto que existe, una de sus presentaciones, cantidad entera de 1 en adelante.
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
        $rules["{$statePath}.*.presentacion_id"] = ['required', 'integer', Rule::exists('presentaciones', 'id')];
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
            ->with('presentaciones.unidadMedida')
            ->get();

        $porPresentacion = Disponible::porPresentacion($bodega, $productos->modelKeys(), $this->pedidoQueSeEdita());

        return array_values($productos
            ->map(fn (Producto $producto): array => self::datosDe(
                $producto,
                self::entero($producto->getAttribute('existencia_bodega')),
                self::entero($producto->getAttribute('apartado')),
                porPresentacion: $porPresentacion[$producto->id] ?? [],
            ))
            ->all());
    }

    /**
     * Total con promociones de las líneas que tiene el teléfono (se pide
     * cuando se deja de tocar). Lo mismo que verá bodega al despachar.
     *
     * @param array<mixed> $lineas
     *
     * @return array{total: float, ahorro: float, promociones: string, descuentos: array<int, float>}
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function resumen(array $lineas): array
    {
        $this->sinRedibujar();

        return PedidoForm::resumenDe(self::normalizar($lineas), $this->bodegaDelPedido());
    }

    /**
     * Las promociones de hoy otra vez (administración cambió la bodega).
     *
     * @return array{promos: list<array<string, mixed>>, productos: array<int, array<string, mixed>>}
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function promocionesDeHoy(): array
    {
        $this->sinRedibujar();

        return $this->getPromocionesDeHoy();
    }

    /**
     * Promociones vigentes hoy en la bodega del pedido, como las usa el
     * teléfono: qué hay que agregar para llevarla ("lineas": lo que pide la
     * promoción más el regalo, en su presentación), qué productos la activan
     * ("compra") y cuál es el regalo; y los datos de esos productos, con lo
     * disponible.
     *
     * @return array{promos: list<array<string, mixed>>, productos: array<int, array<string, mixed>>}
     */
    public function getPromocionesDeHoy(): array
    {
        $bodega = $this->bodegaDelPedido();
        $promociones = Promocion::query()
            ->vigentes()
            ->paraBodega($bodega)
            ->with(['condiciones.producto.presentaciones.unidadMedida', 'productoBeneficio.presentaciones.unidadMedida', ...Promocion::PARA_CALCULAR])
            ->orderBy('id')
            ->get();

        if ($promociones->isEmpty()) {
            return ['promos' => [], 'productos' => []];
        }

        $productos = [];

        foreach ($promociones as $promocion) {
            foreach ($promocion->condiciones as $condicion) {
                $productos[$condicion->producto_id] = $condicion->producto;
            }

            $productos[$promocion->producto_beneficio_id] = $promocion->productoBeneficio;
        }

        $ids = array_keys($productos);
        $hay = Disponible::existencia($bodega, $ids);
        $apartado = Disponible::apartado($bodega, $ids, $this->pedidoQueSeEdita());
        $porPresentacion = Disponible::porPresentacion($bodega, $ids, $this->pedidoQueSeEdita());
        $datos = [];

        foreach ($productos as $id => $producto) {
            $datos[$id] = self::datosDe($producto, $hay[$id] ?? 0, $apartado[$id] ?? 0, porPresentacion: $porPresentacion[$id] ?? []);
        }

        $promos = [];

        foreach ($promociones as $promocion) {
            $lineas = [];

            foreach ($promocion->condiciones as $condicion) {
                self::sumarLinea($lineas, $condicion->producto_id, $condicion->presentacion_id, $condicion->cantidad);
            }

            self::sumarLinea($lineas, $promocion->producto_beneficio_id, $promocion->beneficio_presentacion_id, $promocion->cantidad_beneficio);

            $promos[] = [
                'id'      => $promocion->id,
                'codigo'  => (string) $promocion->codigo,
                'nombre'  => $promocion->nombre,
                'resumen' => $promocion->resumen(),
                'nota'    => $promocion->descripcion,
                'compra'  => array_values(array_unique($promocion->condiciones->pluck('producto_id')->all())),
                'regalo'  => $promocion->producto_beneficio_id,
                'lineas'  => array_values($lineas),
            ];
        }

        return ['promos' => $promos, 'productos' => $datos];
    }

    /**
     * El último pedido de este cliente que le toca a quien levanta la
     * preventa (el vendedor: uno suyo, aunque ya esté validado), para agregar
     * los mismos productos de un toque (las rutas se repiten cada semana).
     * Con los precios de hoy; los productos que ya no están activos (o que ya
     * no se venden en esa presentación) no se agregan.
     *
     * @return array{numero: string, fecha: string, lineas: list<array{producto_id: int, presentacion_id: int, cantidad: int}>, productos: array<int, array<string, mixed>>, fuera: int}|null
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
            ->with(['items.producto.presentaciones.unidadMedida', 'items.presentacion'])
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
        $porPresentacion = Disponible::porPresentacion($bodega, $ids, $this->pedidoQueSeEdita());

        foreach ($pedido->items as $item) {
            $enBodega = $hay[$item->producto_id] ?? 0;
            $disponible = $enBodega - ($apartado[$item->producto_id] ?? 0);

            // Lo que bodega agregó al validar (pidió 0) no es del pedido del cliente.
            if ($item->cantidad < 1) {
                continue;
            }

            // No se vende (o ya no en esa presentación, o sin precio) o, si se controla la existencia, ya no hay disponible.
            if (! $item->producto->activo || ! self::seVende($item->presentacion) || ($controlar && $disponible < 1)) {
                $fuera++;

                continue;
            }

            $lineas[] = ['producto_id' => $item->producto_id, 'presentacion_id' => $item->presentacion_id, 'cantidad' => $item->cantidad];
            $productos[$item->producto_id] = self::datosDe($item->producto, $enBodega, $apartado[$item->producto_id] ?? 0, porPresentacion: $porPresentacion[$item->producto_id] ?? []);
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
        $lineas = self::normalizar($this->getState());
        $ids = array_values(array_unique(array_column($lineas, 'producto_id')));

        if ($ids === []) {
            return [];
        }

        $productos = [];
        $bodega = $this->bodegaDelPedido();
        $hay = Disponible::existencia($bodega, $ids);
        $apartado = Disponible::apartado($bodega, $ids, $this->pedidoQueSeEdita());
        $porPresentacion = Disponible::porPresentacion($bodega, $ids, $this->pedidoQueSeEdita());

        // Las que ya lleva el pedido salen aunque ya no se vendan, para poder verlas y quitarlas.
        $enLineas = array_column($lineas, 'presentacion_id');

        foreach (Producto::query()->whereKey($ids)->with('presentaciones.unidadMedida')->get() as $producto) {
            $productos[$producto->id] = self::datosDe($producto, $hay[$producto->id] ?? 0, $apartado[$producto->id] ?? 0, $enLineas, $porPresentacion[$producto->id] ?? []);
        }

        return $productos;
    }

    /**
     * Lo que el teléfono necesita de un producto para mostrarlo y sacar su
     * subtotal igual que el servidor (precio sin ISV × cantidad, más ISV),
     * con sus presentaciones que se venden (la más grande primero). "hay" es
     * lo disponible en unidades base (lo de la bodega menos lo apartado en
     * otros pedidos pendientes); "hay_por", lo disponible de cada
     * presentación (una caja solo sale de sus cajas) y "hay_texto", eso
     * mismo para leer: "2 CJ48 · 5 UN".
     *
     * @param list<int> $incluir presentaciones que salen aunque ya no se vendan (las que ya lleva el pedido)
     * @param array<int, int> $porPresentacion [presentacion_id => disponible en ella]
     *
     * @return array<string, mixed>
     */
    public static function datosDe(Producto $producto, ?int $enBodega = null, int $apartado = 0, array $incluir = [], array $porPresentacion = []): array
    {
        $mayor = $producto->presentacionMayor();
        $presentaciones = [];

        foreach ($producto->presentaciones->sortByDesc('contenido') as $presentacion) {
            if (! self::seVende($presentacion) && ! in_array($presentacion->id, $incluir, true)) {
                continue;
            }

            $presentaciones[] = [
                'id'        => $presentacion->id,
                'sigla'     => $presentacion->sigla(),
                'nombre'    => $presentacion->nombre(),
                'plural'    => $presentacion->palabra(2),
                'contenido' => $presentacion->contenido,
                'es_base'   => $presentacion->es_base,
                'precio'    => $presentacion->precio,
                'precioIsv' => self::comoEnElPedido($presentacion->precio, $producto->gravado),
            ];
        }

        return [
            'id'              => $producto->id,
            'codigo'          => $producto->codigo,
            'descripcion'     => $producto->descripcion,
            'gravado'         => $producto->gravado,
            'activo'          => $producto->activo,
            'presentaciones'  => $presentaciones,
            'base_sigla'      => $producto->presentacionBase()->sigla(),
            'mayor_sigla'     => $mayor?->sigla(),
            'mayor_contenido' => $mayor === null ? 1 : $mayor->contenido,
            // En unidades base, en la bodega del pedido (o en todas, sin bodega escogida).
            'hay'       => $enBodega === null ? null : $enBodega - $apartado,
            'en_bodega' => $enBodega,
            'apartado'  => $apartado,
            'hay_por'   => (object) $porPresentacion,
            'hay_texto' => $producto->existenciaPorPresentacion(array_filter($porPresentacion, fn (int $cantidad): bool => $cantidad > 0)),
        ];
    }

    /**
     * Se puede vender en esa presentación: activa y con precio.
     */
    public static function seVende(Presentacion $presentacion): bool
    {
        return $presentacion->activo && $presentacion->precio > 0;
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
     * lo disponible en la bodega ("No alcanza ACEITE… en CJ12: disponible 1
     * CJ12 · 3 UN."); null si alcanza todo o no se controla. Cada caja sale
     * de sus cajas; las sueltas, de las sueltas o de abrir cajas.
     *
     * @param list<array{id: int|null, producto_id: int, presentacion_id: int, cantidad: int}> $lineas
     */
    public function pasaDeLoDisponible(array $lineas): ?string
    {
        $bodega = $this->bodegaDelPedido();

        if ($lineas === [] || $bodega === null || ! Configuracion::actual()->controlar_existencias) {
            return null;
        }

        $presentaciones = Presentacion::query()->whereKey(array_column($lineas, 'presentacion_id'))->get()->keyBy('id');
        $pide = [];
        $porPresentacion = [];

        foreach ($lineas as $linea) {
            $presentacion = $presentaciones->get($linea['presentacion_id']);

            if ($presentacion instanceof Presentacion) {
                $pide[$presentacion->producto_id] = ($pide[$presentacion->producto_id] ?? 0) + $linea['cantidad'] * $presentacion->contenido;
                $porPresentacion[$presentacion->producto_id][$presentacion->id] = ($porPresentacion[$presentacion->producto_id][$presentacion->id] ?? 0) + $linea['cantidad'];
            }
        }

        $disponible = Disponible::en($bodega, array_keys($pide), $this->pedidoQueSeEdita());
        $hayPor = Disponible::porPresentacion($bodega, array_keys($pide), $this->pedidoQueSeEdita());
        $productos = Producto::query()->whereKey(array_keys($pide))->with('presentaciones.unidadMedida')->get()->keyBy('id');

        foreach ($pide as $id => $unidades) {
            $producto = $productos->get($id);

            if (! $producto instanceof Producto) {
                continue;
            }

            $hay = $hayPor[$id] ?? [];
            $texto = $producto->existenciaPorPresentacion(array_filter($hay, fn (int $cantidad): bool => $cantidad > 0));
            $faltan = Disponible::faltantes(
                $porPresentacion[$id] ?? [],
                $hay,
                $producto->presentaciones->mapWithKeys(fn (Presentacion $presentacion): array => [$presentacion->id => $presentacion->contenido])->all(),
                $producto->presentacionBase()->id,
            );

            if ($unidades > ($disponible[$id] ?? 0) || $faltan !== []) {
                $en = $faltan === [] ? '' : ' en '.$producto->presentaciones->firstWhere('id', array_key_first($faltan))?->sigla();

                return "No alcanza {$producto->descripcion}{$en}: disponible {$texto}.";
            }
        }

        return null;
    }

    /**
     * La primera línea en una presentación que ya no se vende (o sin precio,
     * o que no es de su producto): "ACEITE… ya no se vende en CJ12."; null si
     * todas se pueden vender. Las que ya traía el pedido así como estaban no
     * se revisan (al editar no se obliga a cambiarlas).
     *
     * @param list<array{id: int|null, producto_id: int, presentacion_id: int, cantidad: int}> $lineas
     */
    public function presentacionQueNoSeVende(array $lineas): ?string
    {
        $pedido = $this->getRecord();
        $yaEstaban = $pedido instanceof Pedido && $pedido->exists
            ? $pedido->items()->get(['id', 'presentacion_id', 'cantidad'])->keyBy('id')
            : collect();

        $presentaciones = Presentacion::query()->with(['producto', 'unidadMedida'])->whereKey(array_column($lineas, 'presentacion_id'))->get()->keyBy('id');

        foreach ($lineas as $linea) {
            $presentacion = $presentaciones->get($linea['presentacion_id']);

            if (! $presentacion instanceof Presentacion || $presentacion->producto_id !== $linea['producto_id']) {
                return 'Hay un producto con una presentación que no es suya: vuelva a agregarlo.';
            }

            $antes = $linea['id'] !== null ? $yaEstaban->get($linea['id']) : null;

            if ($antes instanceof PedidoItem && $antes->presentacion_id === $presentacion->id && $antes->cantidad === $linea['cantidad']) {
                continue;
            }

            if (! self::seVende($presentacion)) {
                return $presentacion->activo
                    ? "{$presentacion->producto->descripcion} no tiene precio en {$presentacion->sigla()}."
                    : "{$presentacion->producto->descripcion} ya no se vende en {$presentacion->sigla()}.";
            }
        }

        return null;
    }

    /**
     * Suma una línea de la promoción (el mismo producto en la misma
     * presentación va junto: "compre 2 y lleve 1 más" son 3).
     *
     * @param array<string, array{producto_id: int, presentacion_id: int, cantidad: int}> $lineas
     */
    private static function sumarLinea(array &$lineas, int $productoId, int $presentacionId, int $cantidad): void
    {
        $clave = $productoId.'-'.$presentacionId;
        $lineas[$clave] ??= ['producto_id' => $productoId, 'presentacion_id' => $presentacionId, 'cantidad' => 0];
        $lineas[$clave]['cantidad'] += max(1, $cantidad);
    }

    private static function entero(mixed $valor): int
    {
        return is_numeric($valor) ? (int) $valor : 0;
    }

    /**
     * Lo que cuesta 1 (de esa presentación) con ISV, redondeado igual que la línea
     * del pedido (precio a 2 decimales y el ISV sobre eso). Así el precio que
     * se ve y el subtotal de 1 nunca difieren por un centavo.
     */
    private static function comoEnElPedido(float $precio, bool $gravado): float
    {
        $bruto = round($precio, 2);

        return round($bruto + ($gravado ? round($bruto * CalculadoraPrecio::tasaIsv(), 2) : 0.0), 2);
    }

    /**
     * Deja solo líneas bien formadas: producto, presentación y cantidad de 1
     * en adelante. Lo demás que mande el navegador se ignora. Un producto
     * lleva a lo más una línea por presentación: si viene repetida, se suma
     * (y se queda el id de la primera).
     *
     * @return list<array{id: int|null, producto_id: int, presentacion_id: int, cantidad: int}>
     */
    public static function normalizar(mixed $estado): array
    {
        if (! is_array($estado)) {
            return [];
        }

        $lineas = [];

        foreach ($estado as $linea) {
            if (! is_array($linea) || ! is_numeric($linea['producto_id'] ?? null) || ! is_numeric($linea['presentacion_id'] ?? null)) {
                continue;
            }

            $presentacion = (int) $linea['presentacion_id'];
            $cantidad = is_numeric($linea['cantidad'] ?? null) ? (int) $linea['cantidad'] : 0;

            if ($cantidad < 1) {
                continue;
            }

            $clave = ((int) $linea['producto_id']).'-'.$presentacion;

            if (isset($lineas[$clave])) {
                $lineas[$clave]['cantidad'] = min($lineas[$clave]['cantidad'] + $cantidad, self::MAXIMO_CANTIDAD);

                continue;
            }

            $lineas[$clave] = [
                'id'              => is_numeric($linea['id'] ?? null) ? (int) $linea['id'] : null,
                'producto_id'     => (int) $linea['producto_id'],
                'presentacion_id' => $presentacion,
                'cantidad'        => min($cantidad, self::MAXIMO_CANTIDAD),
            ];
        }

        return array_values($lineas);
    }

    /**
     * Pasa las líneas a pedido_items: cambia las que ya estaban (solo las de
     * este pedido), crea las nuevas y quita las que ya no vienen.
     *
     * @param list<array{id: int|null, producto_id: int, presentacion_id: int, cantidad: int}> $lineas
     */
    private static function guardar(Pedido $pedido, array $lineas): void
    {
        $actuales = $pedido->items()->get()->keyBy('id');
        $quedan = [];

        foreach ($lineas as $linea) {
            $datos = [
                'producto_id'     => $linea['producto_id'],
                'presentacion_id' => $linea['presentacion_id'],
                'cantidad'        => $linea['cantidad'],
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
