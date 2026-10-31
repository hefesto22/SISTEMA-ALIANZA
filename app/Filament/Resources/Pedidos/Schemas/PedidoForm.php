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
use App\Filament\Schemas\Components\LineasPedido;
use App\Filament\Support\Acceso;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Preventa: el vendedor escoge el cliente y agrega productos (unidad o caja).
 * Los precios que ve son los de hoy; bodega los confirma al despachar.
 *
 * Pensada para el teléfono y para pedidos largos: los productos van en una
 * lista tipo carrito (App\Filament\Schemas\Components\LineasPedido) con el
 * buscador arriba y, abajo, el total con el botón Guardar siempre a la vista.
 *
 * Promociones de la bodega: van dentro de la lista de productos (se agregan
 * de un toque, y al tocar un producto que está en promoción se le pregunta
 * al vendedor si la agrega) y el total estimado ya trae el descuento. Si no
 * hay, el formulario queda como siempre.
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
                            ->getOptionLabelFromRecordUsing(fn (User $record): string => collect([$record->codigo, $record->name])->filter()->implode(' · '))
                            ->searchable(['name', 'codigo'])
                            ->preload()
                            ->required()
                            ->live(condition: $veTodo)
                            ->afterStateUpdated(function (mixed $state, Set $set) use ($veTodo): void {
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
     * con ISV, para mostrarlo en su fila.
     *
     * @param list<array{id: int|null, producto_id: int, unidad: string, cantidad: int}> $lineas
     *
     * @return array{total: float, ahorro: float, promociones: string, descuentos: array<int, float>}
     */
    public static function resumenDe(array $lineas, ?int $bodegaId = null): array
    {
        [$conProducto, $promociones] = self::calcular($lineas, $bodegaId);
        $tasaIsv = CalculadoraPrecio::tasaIsv();
        $total = 0.0;
        $ahorro = 0.0;
        $descuentos = [];

        // Bruto − descuento = neto, ISV sobre el neto.
        foreach ($conProducto as $clave => $linea) {
            $gravado = $linea['producto']->gravado;
            $bruto = round($linea['producto']->precioPara($linea['unidad']) * $linea['cantidad'], 2);
            $aplicado = $promociones->descuentoDe($clave);
            $descuento = round(min($bruto, $aplicado->monto ?? 0.0), 2);
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
            'promociones' => Promocion::query()->vigentes()->paraBodega($bodegaId)->exists() ? self::promocionesAplicadas($promociones) : '',
            'descuentos'  => $descuentos,
        ];
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

    /**
     * Las líneas con su producto, y lo que dejan las promociones vigentes en la bodega.
     *
     * @param list<array{id: int|null, producto_id: int, unidad: string, cantidad: int}> $lineas
     *
     * @return array{0: array<int, array{producto: Producto, unidad: UnidadVenta, cantidad: int}>, 1: ResultadoPromociones}
     */
    private static function calcular(array $lineas, ?int $bodegaId): array
    {
        if ($lineas === []) {
            return [[], new ResultadoPromociones];
        }

        $productos = Producto::query()->whereKey(array_column($lineas, 'producto_id'))->get()->keyBy('id');
        $conProducto = [];
        $paraMotor = [];

        foreach ($lineas as $clave => $linea) {
            $producto = $productos->get($linea['producto_id']);

            if (! $producto instanceof Producto) {
                continue;
            }

            $unidad = UnidadVenta::from($linea['unidad']);

            $conProducto[$clave] = ['producto' => $producto, 'unidad' => $unidad, 'cantidad' => $linea['cantidad']];
            $paraMotor[] = new LineaPromocion($clave, $producto->id, $producto->unidades_por_caja, $unidad, $linea['cantidad'], $producto->precioPara($unidad));
        }

        $vigentes = Promocion::query()->vigentes()->paraBodega($bodegaId)->with(['condiciones.producto', 'productoBeneficio'])->orderBy('id')->get();

        return [$conProducto, app(MotorPromociones::class)->calcular($paraMotor, $vigentes)];
    }

    private static function promocionesAplicadas(ResultadoPromociones $resultado): string
    {
        $filas = [];

        foreach ($resultado->porPromocion() as $grupo) {
            $filas[] = '<div style="color:#16a34a">'.self::icono('heroicon-m-check-circle').' <b>'.e((string) $grupo['promocion']->codigo).'</b> '.e($grupo['promocion']->nombre)
                .': −'.e(lempiras($grupo['monto'])).' (sin ISV)</div>';
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
