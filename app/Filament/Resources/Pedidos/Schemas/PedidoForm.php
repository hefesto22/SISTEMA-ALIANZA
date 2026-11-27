<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Schemas;

use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\VentaEnBodega;
use App\Domain\Precios\CalculadoraPrecio;
use App\Domain\Promociones\LineaPromocion;
use App\Domain\Promociones\MotorPromociones;
use App\Domain\Promociones\ResultadoPromociones;
use App\Domain\Promociones\UsoDePromociones;
use App\Enums\Rol;
use App\Filament\Resources\Clientes\Schemas\ClienteForm;
use App\Filament\Schemas\Components\LineasPedido;
use App\Filament\Support\Acceso;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Preventa: el vendedor escoge el cliente y agrega productos, cada uno en
 * sus presentaciones (UN, CJ12, FD6…).
 * Los precios que ve son los de hoy; bodega los confirma al despachar.
 *
 * Pensada para el teléfono y para pedidos largos: los productos van en una
 * lista tipo carrito (App\Filament\Schemas\Components\LineasPedido) con el
 * buscador arriba y, abajo, el total con el botón Guardar siempre a la vista.
 *
 * Promociones de la bodega: van dentro de la lista de productos (se agregan
 * de un toque, y al tocar un producto que está en promoción se le pregunta
 * al vendedor si la agrega) y el total estimado ya trae el descuento. El
 * regalo de las gratis no se escribe: se avisa y se agrega solo al guardar
 * (bonificación). Si no hay promociones, el formulario queda como siempre.
 *
 * Venta en bodega (mayoreo): el usuario de bodega puede quedar como
 * vendedor (él le vende al cliente que llega a la bodega). Entonces sale
 * "Venta en bodega": un descuento a todo el pedido hasta su tope (Usuarios)
 * y si el cliente se lo lleva o va en camión. Al guardar queda validada
 * (VentaEnBodega).
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
                            ->default(fn (): ?int => self::clienteDeLaUrl())
                            ->required()
                            ->createOptionForm(ClienteForm::components())
                            ->createOptionModalHeading('Nuevo cliente')
                            ->createOptionAction(fn (Action $action): Action => $action
                                ->modalSubmitActionLabel('Guardar cliente')
                                ->extraModalWindowAttributes(['class' => 'modal-telefono'])
                                ->fillForm(fn (Select $component): ?array => self::clienteNuevoDesde($component)))
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
                        // Quién ve qué: el vendedor no escoge vendedor ni bodega (son los
                        // suyos); el encargado escoge el vendedor y la bodega es la suya;
                        // administración escoge los dos (al escoger el vendedor se pone
                        // su bodega, y se puede cambiar).
                        Select::make('vendedor_id')
                            ->label('Vendedor')
                            ->relationship(
                                'vendedor',
                                'name',
                                fn (Builder $query): Builder => self::vendedoresQuePuedeEscoger($query),
                            )
                            ->getOptionLabelFromRecordUsing(fn (User $record): string => collect([$record->codigo, $record->name, $record->vendeEnBodega() ? 'venta en bodega' : null])->filter()->implode(' · '))
                            ->searchable(['name', 'codigo'])
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Set $set) use ($veTodo): void {
                                // Otro vendedor: el descuento se vuelve a escribir (cada quien tiene su tope).
                                $set('descuento_mayoreo_porcentaje', 0);

                                if (! $veTodo || ! is_numeric($state)) {
                                    return;
                                }

                                $bodega = User::query()->whereKey((int) $state)->value('bodega_id');

                                if ($bodega !== null) {
                                    $set('bodega_id', $bodega);
                                }
                            })
                            ->visible(! $esVendedor),
                        Select::make('bodega_id')
                            ->label('Bodega que despacha')
                            ->relationship('bodega', 'nombre', fn (Builder $query): Builder => $query->where('activa', true))
                            ->preload()
                            ->required()
                            ->default(fn (): ?int => Acceso::usuario()?->bodega_id)
                            ->visible($veTodo),
                        DatePicker::make('fecha')
                            ->label('Fecha')
                            ->default(now())
                            ->required(),
                    ]),

                Section::make('Venta en bodega')
                    ->description('El cliente está en la bodega. Al guardar, la venta queda validada (sale de la bodega) y lista para imprimir.')
                    ->icon(Heroicon::OutlinedBuildingStorefront)
                    ->columns(['default' => 1, 'md' => 2])
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'pv'])
                    ->visible(fn (Get $get): bool => self::vendedorEnBodega($get) instanceof User)
                    ->schema([
                        TextInput::make('descuento_mayoreo_porcentaje')
                            ->label('Descuento a todo el pedido')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(fn (Get $get): float => self::topeDe($get))
                            ->step(0.5)
                            ->suffix('%')
                            ->default(0)
                            ->disabled(fn (Get $get): bool => self::topeDe($get) <= 0)
                            ->helperText(fn (Get $get): string => self::topeDe($get) <= 0
                                ? 'Este usuario no tiene descuento autorizado (se pone en Usuarios).'
                                : 'Hasta '.self::porcentajeTexto(self::topeDe($get)).'. Va a todos los productos que no traen promoción, sin bajar del costo.'),
                        ToggleButtons::make('se_lo_lleva')
                            ->label('Entrega')
                            ->boolean('Se lo lleva', 'Va en camión')
                            ->icons([1 => Heroicon::OutlinedShoppingBag, 0 => Heroicon::OutlinedTruck])
                            ->colors([1 => 'primary', 0 => 'gray'])
                            ->default(true)
                            ->required()
                            ->inline()
                            ->helperText('Se lo lleva: no pasa por Cargar camión. Va en camión: queda en Impreso para cargarlo.'),
                    ]),

                Section::make('Productos')
                    ->description('Busque por código o nombre y toque el producto. Precios de hoy con ISV; bodega los confirma al despachar.')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'pv'])
                    ->schema([
                        LineasPedido::make('items')
                            ->label('Productos')
                            ->hiddenLabel(),
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
            ]);
    }

    /**
     * Total del pedido con los precios de hoy y las promociones de la bodega
     * que ya aplican: lo mismo que verá bodega (misma cuenta que
     * RecalcularPedido). Lo pide la lista de productos cada que se deja de
     * tocar. "descuentos" es lo que la promoción le quita a cada producto,
     * con ISV, para mostrarlo en su fila. Las promociones con tope cuentan
     * lo que les queda sin $sinPedido (el que se está editando).
     *
     * Venta en bodega: con $porcentaje (ya revisado contra el tope) cada
     * producto sin promoción lleva el descuento, sin bajar del costo;
     * "rebaja" es lo que suma (sin ISV), aparte de las promociones.
     *
     * Para que la hoja de promociones diga cuánto queda al momento:
     * "regalos" es lo que este pedido lleva de cada promoción y "quedan" lo
     * que le queda a cada una con tope sin contar este pedido (con los
     * pedidos que otros guardaron mientras tanto).
     *
     * @param list<array{id: int|null, producto_id: int, presentacion_id: int, cantidad: int}> $lineas
     *
     * @return array{total: float, ahorro: float, rebaja: float, promociones: string, descuentos: array<int, float>, regalos: array<int, int>, quedan: array<int, int>}
     */
    public static function resumenDe(array $lineas, ?int $bodegaId = null, ?int $sinPedido = null, float $porcentaje = 0.0): array
    {
        [$conProducto, $promociones, $quedan] = self::calcular($lineas, $bodegaId, $sinPedido);
        $tasaIsv = CalculadoraPrecio::tasaIsv();
        $regalos = [];

        foreach ($promociones->regalos as $regalo) {
            $regalos[$regalo->promocion->id] = ($regalos[$regalo->promocion->id] ?? 0) + $regalo->cantidad;
        }
        $total = 0.0;
        $ahorro = 0.0;
        $rebaja = 0.0;
        $descuentos = [];

        // Bruto − descuento = neto, ISV sobre el neto.
        foreach ($conProducto as $clave => $linea) {
            $gravado = $linea['producto']->gravado;
            $bruto = round($linea['presentacion']->precio * $linea['cantidad'], 2);
            $aplicado = $promociones->descuentoDe($clave);
            $descuento = round(min($bruto, $aplicado->monto ?? 0.0), 2);

            // Sin promoción: el descuento de venta en bodega (como RecalcularPedido).
            if ($porcentaje > 0 && $descuento <= 0) {
                $mayoreo = RecalcularPedido::descuentoHastaElCosto($bruto, $linea['presentacion']->costo * $linea['cantidad'], $porcentaje);
                $rebaja += $mayoreo;
                $neto = round($bruto - $mayoreo, 2);
                $total += $neto + ($gravado ? round($neto * $tasaIsv, 2) : 0.0);

                continue;
            }

            $neto = round($bruto - $descuento, 2);

            $ahorro += $descuento;
            $total += $neto + ($gravado ? round($neto * $tasaIsv, 2) : 0.0);

            if ($descuento > 0) {
                $id = $linea['producto']->id;
                $conIsv = ($gravado ? round($bruto * $tasaIsv, 2) - round($neto * $tasaIsv, 2) : 0.0) + $descuento;
                $descuentos[$id] = round(($descuentos[$id] ?? 0.0) + $conIsv, 2);
            }
        }

        return [
            'total'       => round($total, 2),
            'ahorro'      => round($ahorro, 2),
            'rebaja'      => round($rebaja, 2),
            'promociones' => Promocion::query()->vigentes()->paraBodega($bodegaId)->exists() ? self::promocionesAplicadas($promociones) : '',
            'descuentos'  => $descuentos,
            'regalos'     => $regalos,
            'quedan'      => $quedan,
        ];
    }

    /**
     * El % de venta en bodega que vale con lo escogido en el formulario
     * (vendedor y %): 0 si el vendedor no vende en bodega; si no, sin pasar
     * de su tope. Para el total de la preventa.
     */
    public static function porcentajeDe(mixed $vendedorId, mixed $porcentaje): float
    {
        $datos = VentaEnBodega::datos(['vendedor_id' => $vendedorId, 'descuento_mayoreo_porcentaje' => $porcentaje]);

        return is_float($datos['descuento_mayoreo_porcentaje']) ? $datos['descuento_mayoreo_porcentaje'] : 0.0;
    }

    /**
     * El vendedor escogido, si vende en bodega (mayoreo); null si no.
     */
    private static function vendedorEnBodega(Get $get): ?User
    {
        $id = $get('vendedor_id');
        $vendedor = is_numeric($id) ? User::query()->find((int) $id) : null;

        return $vendedor instanceof User && $vendedor->vendeEnBodega() ? $vendedor : null;
    }

    /**
     * Hasta cuánto descuento puede dar el vendedor escogido (0 si no vende en bodega).
     */
    private static function topeDe(Get $get): float
    {
        return self::vendedorEnBodega($get)->descuento_maximo ?? 0.0;
    }

    /**
     * "5 %", "7.5 %".
     */
    public static function porcentajeTexto(float $porcentaje): string
    {
        return rtrim(rtrim(number_format($porcentaje, 2), '0'), '.').' %';
    }

    /**
     * Cliente nuevo desde el pedido: administración ya trae puesta la bodega
     * que escogió en el pedido. A los demás no se les llena nada (la bodega es
     * la suya y quedan los valores por defecto, como su municipio si tiene uno).
     *
     * @return array<string, mixed>|null
     */
    private static function clienteNuevoDesde(Select $cliente): ?array
    {
        if (! Acceso::veTodo()) {
            return null;
        }

        $bodega = $cliente->makeGetUtility()('bodega_id');

        return ['bodega_id' => is_numeric($bodega) ? (int) $bodega : null];
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
     * Vendedores para el pedido: los de ruta y los usuarios de bodega (venta
     * en bodega). Administración escoge cualquiera; el encargado, solo los
     * de su bodega (si no, el pedido se le iría a otra).
     *
     * @param Builder<Model> $query
     *
     * @return Builder<Model>
     */
    private static function vendedoresQuePuedeEscoger(Builder $query): Builder
    {
        $usuario = Acceso::usuario();
        $query->whereHas('roles', fn (Builder $q): Builder => $q->whereIn('name', [Rol::Vendedor->value, Rol::Bodega->value]));

        if ($usuario === null) {
            return $query->whereRaw('1 = 0');
        }

        if (! $usuario->veTodasLasBodegas()) {
            $query->where('bodega_id', $usuario->bodega_id);
        }

        return $query;
    }

    /**
     * "Tomar pedido" desde Clientes (?cliente=5): la preventa empieza con ese
     * cliente escogido, si es de los que le tocan (activo y, al vendedor, de
     * sus rutas). Si no, se escoge como siempre.
     */
    private static function clienteDeLaUrl(): ?int
    {
        $usuario = Acceso::usuario();
        $id = request()->integer('cliente');

        if ($usuario === null || $id <= 0) {
            return null;
        }

        return Cliente::filtrarVisibles(Cliente::query()->where('activo', true), $usuario)->whereKey($id)->exists() ? $id : null;
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

    /**
     * Las líneas con su producto, y lo que dejan las promociones vigentes en la bodega.
     *
     * @param list<array{id: int|null, producto_id: int, presentacion_id: int, cantidad: int}> $lineas
     *
     * @return array{0: array<int, array{producto: Producto, presentacion: Presentacion, cantidad: int}>, 1: ResultadoPromociones, 2: array<int, int>} [líneas, promociones, lo que le queda a cada una con tope]
     */
    private static function calcular(array $lineas, ?int $bodegaId, ?int $sinPedido = null): array
    {
        if ($lineas === []) {
            return [[], new ResultadoPromociones, []];
        }

        $presentaciones = Presentacion::query()->with('producto')->whereKey(array_column($lineas, 'presentacion_id'))->get()->keyBy('id');
        $conProducto = [];
        $paraMotor = [];

        foreach ($lineas as $clave => $linea) {
            $presentacion = $presentaciones->get($linea['presentacion_id']);

            if (! $presentacion instanceof Presentacion || $presentacion->producto_id !== $linea['producto_id']) {
                continue;
            }

            $producto = $presentacion->producto;

            $conProducto[$clave] = ['producto' => $producto, 'presentacion' => $presentacion, 'cantidad' => $linea['cantidad']];
            $paraMotor[] = new LineaPromocion($clave, $producto->id, $presentacion->id, $presentacion->contenido, $linea['cantidad'], $presentacion->precio);
        }

        $vigentes = Promocion::query()->vigentes()->paraBodega($bodegaId)->with([...Promocion::PARA_CALCULAR, 'condiciones.producto'])->orderBy('id')->get();
        $disponibles = UsoDePromociones::disponibles($vigentes, $sinPedido);

        return [$conProducto, app(MotorPromociones::class)->calcular($paraMotor, $vigentes, $disponibles), $disponibles];
    }

    private static function promocionesAplicadas(ResultadoPromociones $resultado): string
    {
        $filas = [];

        foreach ($resultado->porPromocion() as $grupo) {
            $filas[] = '<div style="color:#16a34a">'.self::icono('heroicon-m-check-circle').' <b>'.e((string) $grupo['promocion']->codigo).'</b> '.e($grupo['promocion']->nombre)
                .': −'.e(lempiras($grupo['monto'])).' (sin ISV)</div>';
        }

        foreach ($resultado->regalos as $regalo) {
            $filas[] = '<div style="color:'.($regalo->recortado() ? '#d97706' : '#16a34a').'">'.self::icono('heroicon-m-gift').' '.e($regalo->texto()).' Se agrega solo al guardar.</div>';
        }

        foreach ($resultado->agotadas as $agotada) {
            $filas[] = '<div style="color:#d97706">'.self::icono('heroicon-m-exclamation-triangle').' '.e($agotada->texto()).'</div>';
        }

        foreach ($resultado->faltantes as $faltante) {
            $filas[] = '<div style="color:#d97706">'.self::icono('heroicon-m-exclamation-triangle').' '.e($faltante->texto()).'</div>';
        }

        if ($filas === []) {
            return '<span style="opacity:.6">Todavía no aplica ninguna promoción a este pedido.</span>';
        }

        return '<div style="font-size:.875rem">'.implode('', $filas).'</div>';
    }

    private static function icono(string $nombre): string
    {
        return svg($nombre, '', ['style' => 'display:inline-block;width:1rem;height:1rem;vertical-align:-3px'])->toHtml();
    }
}
