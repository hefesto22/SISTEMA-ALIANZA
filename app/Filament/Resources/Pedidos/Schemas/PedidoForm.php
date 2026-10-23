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
use App\Models\Producto;
use App\Models\Promocion;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Preventa: el vendedor escoge el cliente y agrega productos (unidad o caja).
 * Los precios que ve son los de hoy; bodega los confirma al despachar.
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
                Section::make('Pedido')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('cliente_id')
                            ->label('Cliente')
                            ->relationship(
                                'cliente',
                                'nombre',
                                fn (Builder $query): Builder => self::clientesVisibles($query),
                            )
                            ->searchable(['nombre', 'codigo', 'rtn'])
                            ->required()
                            ->createOptionForm(ClienteForm::components())
                            ->createOptionUsing(fn (array $data): int => (int) Cliente::query()->create(ClienteForm::completar($data))->getKey())
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
                                fn (Builder $query): Builder => $query->whereHas('roles', fn (Builder $q): Builder => $q->where('name', Rol::Vendedor->value)),
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->visible(! $esVendedor),
                        DatePicker::make('fecha')
                            ->label('Fecha')
                            ->default(now())
                            ->required(),
                        Textarea::make('notas')
                            ->label('Notas para bodega')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Section::make('Promociones de hoy')
                    ->description('Se aplican solas cuando el cliente lleva los productos.')
                    ->icon(Heroicon::OutlinedGift)
                    ->columnSpanFull()
                    ->collapsible()
                    ->visible(fn (): bool => Promocion::query()->vigentes()->exists())
                    ->schema([
                        TextEntry::make('promociones_vigentes')
                            ->hiddenLabel()
                            ->state(fn (): HtmlString => self::listaPromociones()),
                    ]),

                Section::make('Productos')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->relationship()
                            ->table([
                                TableColumn::make('Producto')->markAsRequired(),
                                TableColumn::make('Vende en'),
                                TableColumn::make('Cantidad')->markAsRequired(),
                                TableColumn::make('Precio de hoy'),
                            ])
                            ->schema([
                                Select::make('producto_id')
                                    ->hiddenLabel()
                                    ->placeholder('Buscar por código o nombre…')
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search): array => Producto::query()
                                        ->activos()
                                        ->buscar($search)
                                        ->orderBy('descripcion')
                                        ->limit(50)
                                        ->get()
                                        ->mapWithKeys(fn (Producto $p): array => [$p->id => $p->etiqueta()])
                                        ->all())
                                    ->getOptionLabelUsing(fn (mixed $value): ?string => Producto::query()->whereKey($value)->first()?->etiqueta())
                                    ->required()
                                    ->live(),
                                Select::make('unidad')
                                    ->hiddenLabel()
                                    ->options(UnidadVenta::class)
                                    ->default(UnidadVenta::Unidad->value)
                                    ->selectablePlaceholder(false)
                                    ->required()
                                    ->live(),
                                TextInput::make('cantidad')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->live(debounce: 500),
                                TextEntry::make('precio_info')
                                    ->hiddenLabel()
                                    ->state(fn (Get $get): HtmlString => self::precioLinea($get)),
                            ])
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Agregar producto')
                            ->reorderable(false),
                        TextEntry::make('promociones_aplicadas')
                            ->label('Promociones en este pedido')
                            ->state(fn (Get $get): HtmlString => self::promocionesAplicadas($get))
                            ->visible(fn (): bool => Promocion::query()->vigentes()->exists()),
                        TextEntry::make('total_estimado')
                            ->label('Total estimado con precios de hoy')
                            ->state(fn (Get $get): string => self::totalEstimado($get))
                            ->weight('bold')
                            ->size('lg')
                            ->helperText('Bodega confirma cantidades y precios al despachar.'),
                    ]),
            ]);
    }

    /**
     * @param Builder<Model> $query
     *
     * @return Builder<Model>
     */
    private static function clientesVisibles(Builder $query): Builder
    {
        $usuario = Acceso::usuario();
        $query->where('activo', true);

        if ($usuario === null || $usuario->veTodasLasBodegas()) {
            return $query;
        }

        return $query->where('bodega_id', $usuario->bodega_id);
    }

    private static function unidad(mixed $valor): UnidadVenta
    {
        if ($valor instanceof UnidadVenta) {
            return $valor;
        }

        return UnidadVenta::tryFrom(is_string($valor) ? $valor : '') ?? UnidadVenta::Unidad;
    }

    private static function precioLinea(Get $get): HtmlString
    {
        $producto = filled($get('producto_id')) ? Producto::query()->whereKey($get('producto_id'))->first() : null;

        if (! $producto instanceof Producto) {
            return new HtmlString('<span class="text-gray-400">—</span>');
        }

        $unidad = self::unidad($get('unidad'));
        $precio = $producto->precioPara($unidad);
        $conIsv = CalculadoraPrecio::conIsv($precio, $producto->gravado);
        $cantidad = max(0, (int) $get('cantidad'));

        $detalle = $unidad === UnidadVenta::Caja ? "caja de {$producto->unidades_por_caja}" : 'c/u';

        return new HtmlString(
            '<div class="text-sm"><b>'.e(lempiras($conIsv)).'</b> '.e($detalle).($producto->gravado ? ' con ISV' : ' (exento)')
            .'<br><span class="text-gray-500">Línea: '.e(lempiras(round($conIsv * $cantidad, 2))).'</span></div>'
        );
    }

    private static function totalEstimado(Get $get): string
    {
        [$lineas, $promociones] = self::calcular($get);
        $tasaIsv = CalculadoraPrecio::tasaIsv();
        $total = 0.0;

        // Misma cuenta que RecalcularPedido: bruto − descuento = neto, ISV sobre el neto.
        foreach ($lineas as $clave => $linea) {
            $bruto = round($linea['producto']->precioPara($linea['unidad']) * $linea['cantidad'], 2);
            $aplicado = $promociones->descuentoDe($clave);
            $neto = round($bruto - min($bruto, $aplicado->monto ?? 0.0), 2);

            $total += $neto + ($linea['producto']->gravado ? round($neto * $tasaIsv, 2) : 0.0);
        }

        return lempiras(round($total, 2));
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

        $vigentes = Promocion::query()->vigentes()->with(['productoCondicion', 'productoBeneficio'])->orderBy('id')->get();

        return [$lineas, app(MotorPromociones::class)->calcular($paraMotor, $vigentes)];
    }

    private static function listaPromociones(): HtmlString
    {
        $filas = Promocion::query()
            ->vigentes()
            ->with(['productoCondicion', 'productoBeneficio'])
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
            $filas[] = '<div style="color:#16a34a">✓ <b>'.e((string) $grupo['promocion']->codigo).'</b> '.e($grupo['promocion']->nombre)
                .': −'.e(lempiras($grupo['monto'])).' (sin ISV)</div>';
        }

        foreach ($resultado->faltantes as $faltante) {
            $filas[] = '<div style="color:#d97706">⚠ '.e($faltante->texto()).'</div>';
        }

        if ($filas === []) {
            return new HtmlString('<span style="opacity:.6">Todavía no aplica ninguna promoción a este pedido.</span>');
        }

        return new HtmlString('<div style="font-size:.875rem">'.implode('', $filas).'</div>');
    }
}
