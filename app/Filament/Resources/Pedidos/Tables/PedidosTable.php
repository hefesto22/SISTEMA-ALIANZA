<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Tables;

use App\Enums\EstadoPedido;
use App\Filament\Resources\Pedidos\Actions\AnularPedidoAction;
use App\Filament\Resources\Pedidos\Actions\CorregirPedidoAction;
use App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction;
use App\Filament\Resources\Pedidos\Actions\ValidarDespachoAction;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Filament\Support\Acceso;
use App\Models\Cliente;
use App\Models\Pedido;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Pedidos en tarjetas (como Cuentas abiertas): cliente, estado, hace cuánto,
 * número y código del cliente, vendedor y total.
 *
 * Botones según cómo va el pedido (para bodega y administración):
 *  - Pendiente: Ver · Validar
 *  - Por imprimir: Corregir · Imprimir (o Reimprimir)
 *  - Impreso: Ver · Corregir
 * y el resto en "...". Al hacer clic en la tarjeta se abre Validar o
 * Corregir; si no le toca, se abre el pedido.
 *
 * Bodega puede marcar varias (ya validadas) e imprimirlas de un solo, por
 * ejemplo para reimprimir las de un cliente o un vendedor con los filtros.
 */
class PedidosTable
{
    public static function configure(Table $table): Table
    {
        $veTodo = Acceso::veTodo();
        $esVendedor = Acceso::esVendedor();

        return $table
            ->columns([
                Stack::make([
                    ViewColumn::make('tarjeta')
                        ->view('filament.pedidos.tarjeta')
                        ->state(fn (Pedido $record): ?string => $record->numero)
                        ->searchable(query: fn (Builder $query, string $search): Builder => self::buscar($query, $search)),
                ]),
            ])
            ->contentGrid(['md' => 2, 'xl' => 3, '2xl' => 4])
            ->recordAction(fn (Pedido $record): ?string => self::alHacerClic($record))
            ->recordUrl(fn (Pedido $record): ?string => self::alHacerClic($record) === null ? PedidoResource::getUrl('view', ['record' => $record]) : null)
            ->poll('30s')
            ->extraAttributes(['class' => 'pedidos-tarjetas'])
            ->searchPlaceholder('Buscar por cliente, código, número de pedido o de factura, o vendedor…')
            ->defaultSort('created_at', 'desc')
            ->paginated([12, 24, 48])
            ->defaultPaginationPageOption(12)
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(['md' => 2, 'xl' => 4])
            ->checkIfRecordIsSelectableUsing(fn (Pedido $record): bool => $record->estaValidado())
            ->toolbarActions([
                ImprimirPedidoAction::seleccionados(),
            ])
            ->filters([
                SelectFilter::make('cliente_id')
                    ->label('Cliente')
                    ->relationship(
                        'cliente',
                        'nombre',
                        fn (Builder $query): Builder => self::clientesConPedidos($query),
                    )
                    ->getOptionLabelFromRecordUsing(fn (Cliente $record): string => $record->etiqueta())
                    ->searchable(['nombre', 'codigo']),
                SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre')
                    ->visible($veTodo),
                SelectFilter::make('vendedor_id')
                    ->label('Vendedor')
                    ->relationship('vendedor', 'name')
                    ->searchable()
                    ->visible(! $esVendedor),
                TernaryFilter::make('fuera_de_ruta')
                    ->label('Fuera de ruta'),
                Filter::make('fecha')
                    ->schema([
                        DatePicker::make('desde'),
                        DatePicker::make('hasta'),
                    ])
                    ->columns(2)
                    ->columnSpan(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['desde'] ?? null, fn (Builder $q, mixed $fecha): Builder => $q->whereDate('fecha', '>=', $fecha))
                        ->when($data['hasta'] ?? null, fn (Builder $q, mixed $fecha): Builder => $q->whereDate('fecha', '<=', $fecha))),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Ver')
                    ->button()
                    ->outlined()
                    ->color('gray')
                    ->visible(fn (Pedido $record): bool => ! ($record->estado === EstadoPedido::PorImprimir && Gate::allows('corregir', $record))),
                ValidarDespachoAction::make()
                    ->button()
                    ->outlined()
                    ->color('gray'),
                CorregirPedidoAction::make()
                    ->button()
                    ->outlined(),
                ImprimirPedidoAction::make()
                    ->button()
                    ->outlined(),
                ActionGroup::make([
                    EditAction::make(),
                    ImprimirPedidoAction::hoja(),
                    AnularPedidoAction::make(),
                ])
                    ->icon(Heroicon::EllipsisHorizontal)
                    ->color('gray')
                    ->tooltip('Más'),
            ]);
    }

    /**
     * Clic en la tarjeta: Validar (pendiente) o Corregir (ya validado) a
     * quien le toca; null = abrir el pedido.
     */
    private static function alHacerClic(Pedido $pedido): ?string
    {
        return match (true) {
            Gate::allows('validar', $pedido)  => 'validarDespacho',
            Gate::allows('corregir', $pedido) => 'corregir',
            default                           => null,
        };
    }

    /**
     * Número de pedido o de factura, cliente (nombre o código) o vendedor.
     *
     * @param Builder<Model> $query
     *
     * @return Builder<Model>
     */
    private static function buscar(Builder $query, string $search): Builder
    {
        $like = '%'.$search.'%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('numero', 'ilike', $like)
                ->orWhereHas('facturas', fn (Builder $f): Builder => $f->where('numero', 'ilike', $like))
                ->orWhereHas('cliente', fn (Builder $c): Builder => $c->where('nombre', 'ilike', $like)->orWhere('codigo', 'ilike', $like))
                ->orWhereHas('vendedor', fn (Builder $v): Builder => $v->where('name', 'ilike', $like)->orWhere('codigo', 'ilike', $like));
        });
    }

    /**
     * En el filtro solo salen clientes con pedidos que esta persona puede ver.
     *
     * @param Builder<Model> $query
     *
     * @return Builder<Model>
     */
    private static function clientesConPedidos(Builder $query): Builder
    {
        $usuario = Acceso::usuario();

        if ($usuario === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->with('municipio')
            ->whereIn('id', Pedido::query()->visiblePara($usuario)->select('cliente_id'))
            ->orderBy('nombre');
    }
}
