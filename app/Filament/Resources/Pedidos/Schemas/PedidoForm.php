<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Schemas;

use App\Domain\Precios\CalculadoraPrecio;
use App\Domain\Promociones\LineaPromocion;
use App\Domain\Promociones\MotorPromociones;
use App\Domain\Promociones\ResultadoPromociones;
use App\Enums\Rol;
use App\Enums\UnidadVenta;
use App\Filament\Resources\Clientes\Schemas\ClienteForm;
use App\Filament\Support\Acceso;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Livewire\Component;

/**
 * Preventa: el vendedor escoge el cliente y agrega productos (unidad o caja).
 * Los precios que ve son los de hoy; bodega los confirma al despachar.
 *
 * Pensada para el teléfono: cada producto es una tarjeta (producto, unidad o
 * caja en botones, cantidad con − y +) y abajo queda siempre a la vista el
 * total con el botón Guardar (resources/views/filament/pedidos/barra-preventa.blade.php).
 *
 * Si hay promociones vigentes se muestran arriba de los productos y el total
 * estimado ya trae el descuento. Si no hay, el formulario queda como siempre.
 */
class PedidoForm
{
    public static function configure(Schema $schema): Schema
    {
        $veTodo = Acceso::veTodo();
        $esVendedor = Acceso::esVendedor();

        return $schema
            ->components([
                Section::make('Cliente')
                    ->icon(Heroicon::OutlinedUser)
                    ->columns(['default' => 1, 'md' => 2])
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'pv'])
                    ->schema([
                        Select::make('cliente_id')
                            ->label('Cliente')
                            ->relationship(
                                'cliente',
                                'nombre',
                                fn (Builder $query, Get $get, ?Model $record): Builder => self::clientesVisibles(
                                    $query,
                                    fueraDeRuta: (bool) $get('fuera_de_ruta'),
                                    actual: $record instanceof Pedido ? $record->cliente_id : null,
                                ),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Cliente $record): string => $record->etiqueta())
                            ->searchable(['nombre', 'codigo', 'rtn'])
                            ->searchPrompt('Escriba nombre, código o RTN')
                            ->placeholder('Buscar cliente…')
                            ->required()
                            ->createOptionForm(ClienteForm::components())
                            ->createOptionUsing(fn (array $data): int => (int) Cliente::query()->create(ClienteForm::completar($data))->getKey())
                            ->columnSpanFull(),
                        Toggle::make('fuera_de_ruta')
                            ->label('El cliente no es de mi ruta')
                            ->helperText('Para venderle a un cliente de otra ruta o bodega. El pedido queda marcado como fuera de ruta, con el motivo.')
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                if (! $state) {
                                    $set('cliente_id', null);
                                    $set('motivo_fuera_ruta', null);
                                }
                            })
                            ->visible($esVendedor)
                            ->columnSpanFull(),
                        TextInput::make('motivo_fuera_ruta')
                            ->label('Motivo')
                            ->placeholder('Ej. Cubriendo la ruta de HO04 / El cliente llamó para pedir')
                            ->required()
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $esVendedor && (bool) $get('fuera_de_ruta'))
                            ->columnSpanFull(),
                        Select::make('bodega_id')
                            ->label('Bodega que despacha')
                            ->relationship('bodega', 'nombre', fn (Builder $query): Builder => $query->where('activa', true))
                            ->preload()
                            ->required()
                            ->default(fn (): ?int => Acceso::usuario()?->bodega_id)
                            ->visible($veTodo),
                        Select::make('vendedor_id')
                            ->label('Vendedor')
                            ->relationship(
                                'vendedor',
                                'name',
                                fn (Builder $query): Builder => self::vendedoresQuePuedeEscoger($query),
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->visible(! $esVendedor),
                        DatePicker::make('fecha')
                            ->label('Fecha')
                            ->default(now())
                            ->required(),
                    ]),

                Section::make('Promociones de hoy')
                    ->description(fn (): string => self::cuantasPromociones().'. Se aplican solas cuando el cliente lleva los productos.')
                    ->icon(Heroicon::OutlinedGift)
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed()
                    ->extraAttributes(['class' => 'pv'])
                    ->visible(fn (): bool => Promocion::query()->vigentes()->exists())
                    ->schema([
                        TextEntry::make('promociones_vigentes')
                            ->hiddenLabel()
                            ->state(fn (): HtmlString => self::listaPromociones()),
                    ]),

                Section::make('Productos')
                    ->description('Precios de hoy con ISV; bodega los confirma al despachar.')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'pv'])
                    ->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->relationship()
                            ->extraAttributes(['class' => 'pv-items'])
                            // Teléfono: producto, unidad o caja, y cantidad con subtotal. Tableta: producto y abajo lo demás. Pantalla grande: una fila.
                            ->columns(['default' => 5, 'md' => 12])
                            ->schema([
                                Select::make('producto_id')
                                    ->label('Producto')
                                    ->placeholder('Buscar por código o nombre…')
                                    ->searchable()
                                    ->searchPrompt('Escriba el código o parte del nombre')
                                    ->noSearchResultsMessage('No hay productos activos con ese código o nombre.')
                                    ->searchDebounce(400)
                                    ->getSearchResultsUsing(fn (string $search): array => Producto::query()
                                        ->activos()
                                        ->buscar($search)
                                        ->orderBy('descripcion')
                                        ->limit(50)
                                        ->get()
                                        // Al buscar se ve el precio de la unidad con ISV.
                                        ->mapWithKeys(fn (Producto $p): array => [$p->id => $p->etiqueta().' · '.lempiras($p->precioUnidadConIsv())])
                                        ->all())
                                    ->getOptionLabelUsing(fn (mixed $value): ?string => Producto::query()->whereKey($value)->first()?->etiqueta())
                                    ->required()
                                    ->live()
                                    ->columnSpan(['default' => 5, 'md' => 12, 'xl' => 5]),
                                ToggleButtons::make('unidad')
                                    ->label('Vende en')
                                    ->options(fn (Get $get): array => self::opcionesUnidad($get('producto_id')))
                                    ->default(UnidadVenta::Unidad->value)
                                    ->inline()
                                    ->grouped()
                                    ->fullWidth()
                                    ->required()
                                    ->live()
                                    ->columnSpan(['default' => 5, 'md' => 4, 'xl' => 3]),
                                TextInput::make('cantidad')
                                    ->label('Cantidad')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->live(debounce: 500)
                                    ->prefixAction(self::paso('menos', 'Uno menos', Heroicon::Minus, -1))
                                    ->suffixAction(self::paso('mas', 'Uno más', Heroicon::Plus, 1))
                                    ->extraInputAttributes(['inputmode' => 'numeric', 'style' => 'text-align:center'])
                                    ->extraFieldWrapperAttributes(['class' => 'pv-cantidad'])
                                    ->columnSpan(['default' => 3, 'md' => 4, 'xl' => 2]),
                                TextEntry::make('precio_info')
                                    ->label('Subtotal')
                                    ->state(fn (Get $get): HtmlString => self::precioLinea($get))
                                    ->columnSpan(['default' => 2, 'md' => 4, 'xl' => 2]),
                            ])
                            // Con muchos productos se pueden contraer: la cabecera dice qué es, cuánto y el subtotal.
                            ->itemLabel(fn (array $state, ?int $index): HtmlString => self::etiquetaLinea($state, $index))
                            ->collapsible()
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Agregar producto')
                            // Al agregar otro, los de arriba se contraen (queda su resumen) y el nuevo queda abierto.
                            ->addAction(fn (Action $action, Repeater $component): Action => $action
                                ->icon(Heroicon::Plus)
                                ->color('primary')
                                ->outlined()
                                ->after(function (Component $livewire) use ($component): void {
                                    $livewire->js(self::contraerAnteriores($component->getStatePath()));
                                }))
                            ->reorderable(false),
                        TextEntry::make('promociones_aplicadas')
                            ->label('Promociones en este pedido')
                            ->state(fn (Get $get): HtmlString => self::promocionesAplicadas($get))
                            ->visible(fn (): bool => Promocion::query()->vigentes()->exists()),
                    ]),

                Section::make('Notas para bodega')
                    ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)
                    ->columnSpanFull()
                    ->compact()
                    ->extraAttributes(['class' => 'pv'])
                    ->schema([
                        Textarea::make('notas')
                            ->hiddenLabel()
                            ->placeholder('Ej. Entregar por la mañana, cobrar en efectivo…')
                            ->rows(2)
                            ->autosize(),
                    ]),

                // Total y Guardar: abajo, siempre a la vista en el teléfono.
                View::make('filament.pedidos.barra-preventa')
                    ->viewData(fn (Get $get, string $operation): array => [
                        ...self::resumen($get),
                        'edicion' => $operation === 'edit',
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Totales del pedido para la barra de abajo: lo mismo que verá bodega,
     * con los precios de hoy y las promociones que ya aplican.
     *
     * @return array{total: string, lineas: int, ahorro: string|null}
     */
    public static function resumen(Get $get): array
    {
        [$lineas, $promociones] = self::calcular($get);
        $tasaIsv = CalculadoraPrecio::tasaIsv();
        $total = 0.0;
        $ahorro = 0.0;

        // Misma cuenta que RecalcularPedido: bruto − descuento = neto, ISV sobre el neto.
        foreach ($lineas as $clave => $linea) {
            $bruto = round($linea['producto']->precioPara($linea['unidad']) * $linea['cantidad'], 2);
            $aplicado = $promociones->descuentoDe($clave);
            $descuento = round(min($bruto, $aplicado->monto ?? 0.0), 2);
            $neto = round($bruto - $descuento, 2);

            $ahorro += $descuento;
            $total += $neto + ($linea['producto']->gravado ? round($neto * $tasaIsv, 2) : 0.0);
        }

        return [
            'total'  => lempiras(round($total, 2)),
            'lineas' => count($lineas),
            'ahorro' => $ahorro > 0 ? lempiras(round($ahorro, 2)) : null,
        ];
    }

    /**
     * El vendedor que le vende a un cliente que no es de sus rutas: el pedido
     * queda marcado con el motivo. Se calcula aquí con el cliente escogido,
     * sin confiar en lo que mandó el formulario.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function marcarFueraDeRuta(array $data, User $vendedor): array
    {
        $fuera = filled($data['cliente_id'] ?? null)
            && ! Cliente::query()->visiblePara($vendedor)->whereKey($data['cliente_id'])->exists();

        $motivo = trim(is_string($data['motivo_fuera_ruta'] ?? null) ? $data['motivo_fuera_ruta'] : '');

        $data['fuera_de_ruta'] = $fuera;
        $data['motivo_fuera_ruta'] = $fuera ? ($motivo !== '' ? $motivo : 'Sin motivo') : null;

        return $data;
    }

    /**
     * Vendedores para el pedido: administración escoge cualquiera; el
     * encargado, solo los de su bodega (si no, el pedido se le iría a otra).
     *
     * @param Builder<Model> $query
     *
     * @return Builder<Model>
     */
    private static function vendedoresQuePuedeEscoger(Builder $query): Builder
    {
        $usuario = Acceso::usuario();
        $query->whereHas('roles', fn (Builder $q): Builder => $q->where('name', Rol::Vendedor->value));

        if ($usuario === null) {
            return $query->whereRaw('1 = 0');
        }

        if (! $usuario->veTodasLasBodegas()) {
            $query->where('bodega_id', $usuario->bodega_id);
        }

        return $query;
    }

    /**
     * Clientes activos que le tocan a quien levanta el pedido: al vendedor,
     * los de los municipios de sus rutas (o todos si marcó fuera de ruta).
     * Al editar, el cliente que ya tiene el pedido siempre se puede dejar.
     *
     * @param Builder<Model> $query
     *
     * @return Builder<Model>
     */
    private static function clientesVisibles(Builder $query, bool $fueraDeRuta = false, ?int $actual = null): Builder
    {
        $usuario = Acceso::usuario();
        $query->with('municipio');

        if ($usuario === null || ($fueraDeRuta && $usuario->esVendedor())) {
            return $query->where('activo', true);
        }

        return $query->where(function (Builder $q) use ($usuario, $actual): void {
            $q->where(fn (Builder $visibles): Builder => Cliente::filtrarVisibles($visibles->where('activo', true), $usuario));

            if ($actual !== null) {
                $q->orWhere($q->qualifyColumn('id'), $actual);
            }
        });
    }

    private static function unidad(mixed $valor): UnidadVenta
    {
        if ($valor instanceof UnidadVenta) {
            return $valor;
        }

        return UnidadVenta::tryFrom(is_string($valor) ? $valor : '') ?? UnidadVenta::Unidad;
    }

    /**
     * Unidad o caja; la caja dice cuántas trae el producto escogido.
     *
     * @return array<string, string>
     */
    private static function opcionesUnidad(mixed $productoId): array
    {
        $producto = filled($productoId) ? Producto::query()->whereKey($productoId)->first() : null;

        return [
            UnidadVenta::Unidad->value => 'Unidad',
            UnidadVenta::Caja->value   => $producto instanceof Producto ? "Caja de {$producto->unidades_por_caja}" : 'Caja',
        ];
    }

    /**
     * − y + de la cantidad: cambian el número en el teléfono sin ir al servidor
     * por cada toque; el subtotal y el total se actualizan al dejar de tocar.
     */
    private static function paso(string $nombre, string $etiqueta, Heroicon $icono, int $cambio): Action
    {
        return Action::make($nombre)
            ->label($etiqueta)
            ->icon($icono)
            ->iconButton()
            ->color('gray')
            ->alpineClickHandler(
                "(() => { const campo = \$el.closest('.fi-input-wrp').querySelector('input'); "
                ."campo.value = Math.max(1, (parseInt(campo.value, 10) || 0) + ({$cambio})); "
                ."campo.dispatchEvent(new Event('input', { bubbles: true })); })()"
            );
    }

    /**
     * Precio de la línea con los precios de hoy (con ISV), sin promociones.
     *
     * @return array{producto: Producto, unidad: UnidadVenta, cantidad: int, precio: float, subtotal: float}|null
     */
    private static function linea(mixed $productoId, mixed $unidad, mixed $cantidad): ?array
    {
        $producto = filled($productoId) ? Producto::query()->whereKey($productoId)->first() : null;

        if (! $producto instanceof Producto) {
            return null;
        }

        $unidad = self::unidad($unidad);
        $precio = CalculadoraPrecio::conIsv($producto->precioPara($unidad), $producto->gravado);
        $cantidad = max(0, is_numeric($cantidad) ? (int) $cantidad : 0);

        return [
            'producto' => $producto,
            'unidad'   => $unidad,
            'cantidad' => $cantidad,
            'precio'   => $precio,
            'subtotal' => round($precio * $cantidad, 2),
        ];
    }

    private static function precioLinea(Get $get): HtmlString
    {
        $linea = self::linea($get('producto_id'), $get('unidad'), $get('cantidad'));

        if ($linea === null) {
            return new HtmlString('<div class="pv-precio"><span class="pv-precio-unit">Escoja el producto</span></div>');
        }

        $producto = $linea['producto'];
        $detalle = $linea['unidad'] === UnidadVenta::Caja ? "la caja de {$producto->unidades_por_caja}" : 'c/u';

        return new HtmlString(
            '<div class="pv-precio">'
            .'<span class="pv-precio-linea">'.e(lempiras($linea['subtotal'])).'</span>'
            .'<span class="pv-precio-unit">'.e(lempiras($linea['precio'])).' '.e($detalle).($producto->gravado ? ' con ISV' : ' · exento').'</span>'
            .'</div>'
        );
    }

    /**
     * Cabecera de cada producto: "1. ACEITE EL DORADO 20X750ML" y abajo "2 cajas · L 1,200.00".
     * Sirve sobre todo con la tarjeta contraída.
     *
     * @param array<string, mixed> $state
     */
    private static function etiquetaLinea(array $state, ?int $index): HtmlString
    {
        $numero = $index !== null ? ($index + 1).'. ' : '';
        $linea = self::linea($state['producto_id'] ?? null, $state['unidad'] ?? null, $state['cantidad'] ?? null);

        if ($linea === null) {
            return new HtmlString('<span class="pv-item-nombre pv-item-vacio">'.e($numero).'Producto nuevo</span>');
        }

        $cantidad = $linea['cantidad'];
        $unidades = $linea['unidad'] === UnidadVenta::Caja
            ? ($cantidad === 1 ? 'caja' : 'cajas')
            : ($cantidad === 1 ? 'unidad' : 'unidades');

        return new HtmlString(
            '<span class="pv-item-nombre">'.e($numero.$linea['producto']->descripcion).'</span>'
            .'<span class="pv-item-resumen">'.e("{$cantidad} {$unidades} · ".lempiras($linea['subtotal'])).'</span>'
        );
    }

    /**
     * Después de agregar un producto: contrae los de arriba y deja abierto el
     * nuevo (el último). Corre en el navegador al volver la respuesta.
     */
    private static function contraerAnteriores(string $statePath): string
    {
        return 'setTimeout(() => { '
            ."window.dispatchEvent(new CustomEvent('repeater-collapse', { detail: '{$statePath}' })); "
            ."const items = document.querySelectorAll('.pv-items .fi-fo-repeater-item'); "
            ."items[items.length - 1]?.dispatchEvent(new CustomEvent('expand')); "
            .'}, 50)';
    }

    private static function cuantasPromociones(): string
    {
        $cuantas = Promocion::query()->vigentes()->count();

        return $cuantas === 1 ? '1 vigente' : "{$cuantas} vigentes";
    }

    /**
     * Las líneas del formulario con su producto, y lo que dejan las promociones vigentes.
     *
     * @return array{0: array<int|string, array{producto: Producto, unidad: UnidadVenta, cantidad: int}>, 1: ResultadoPromociones}
     */
    private static function calcular(Get $get): array
    {
        $items = $get('items');

        if (! is_array($items) || $items === []) {
            return [[], new ResultadoPromociones];
        }

        $ids = collect($items)->pluck('producto_id')->filter()->unique()->all();
        $productos = Producto::query()->whereKey($ids)->get()->keyBy('id');
        $lineas = [];
        $paraMotor = [];

        foreach ($items as $clave => $item) {
            if (! is_array($item)) {
                continue;
            }

            $producto = $productos->get($item['producto_id'] ?? null);

            if (! $producto instanceof Producto) {
                continue;
            }

            $unidad = self::unidad($item['unidad'] ?? null);
            $cantidad = max(0, (int) ($item['cantidad'] ?? 0));

            $lineas[$clave] = ['producto' => $producto, 'unidad' => $unidad, 'cantidad' => $cantidad];
            $paraMotor[] = new LineaPromocion($clave, $producto->id, $producto->unidades_por_caja, $unidad, $cantidad, $producto->precioPara($unidad));
        }

        $vigentes = Promocion::query()->vigentes()->with(['condiciones.producto', 'productoBeneficio'])->orderBy('id')->get();

        return [$lineas, app(MotorPromociones::class)->calcular($paraMotor, $vigentes)];
    }

    private static function listaPromociones(): HtmlString
    {
        $filas = Promocion::query()
            ->vigentes()
            ->with(['condiciones.producto', 'productoBeneficio'])
            ->orderBy('id')
            ->get()
            ->map(fn (Promocion $p): string => '<li style="margin-bottom:4px"><b>'.e((string) $p->codigo).'</b> · '.e($p->nombre)
                .'<br><span style="opacity:.75">'.e($p->resumen()).'</span>'
                .($p->descripcion ? '<br><span style="opacity:.75">'.e($p->descripcion).'</span>' : '').'</li>')
            ->implode('');

        return new HtmlString('<ul style="font-size:.875rem">'.$filas.'</ul>');
    }

    private static function promocionesAplicadas(Get $get): HtmlString
    {
        [, $resultado] = self::calcular($get);
        $filas = [];

        foreach ($resultado->porPromocion() as $grupo) {
            $filas[] = '<div style="color:#16a34a">'.self::icono('heroicon-m-check-circle').' <b>'.e((string) $grupo['promocion']->codigo).'</b> '.e($grupo['promocion']->nombre)
                .': −'.e(lempiras($grupo['monto'])).' (sin ISV)</div>';
        }

        foreach ($resultado->faltantes as $faltante) {
            $filas[] = '<div style="color:#d97706">'.self::icono('heroicon-m-exclamation-triangle').' '.e($faltante->texto()).'</div>';
        }

        if ($filas === []) {
            return new HtmlString('<span style="opacity:.6">Todavía no aplica ninguna promoción a este pedido.</span>');
        }

        return new HtmlString('<div style="font-size:.875rem">'.implode('', $filas).'</div>');
    }

    private static function icono(string $nombre): string
    {
        return svg($nombre, '', ['style' => 'display:inline-block;width:1rem;height:1rem;vertical-align:-3px'])->toHtml();
    }
}
