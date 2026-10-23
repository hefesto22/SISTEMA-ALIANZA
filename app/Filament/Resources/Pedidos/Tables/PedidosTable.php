<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Tables;

use App\Filament\Resources\Pedidos\Actions\AnularPedidoAction;
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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Pedidos en tarjetas (como Cuentas abiertas): cliente, estado, hace cuánto,
 * número y código del cliente, vendedor y total. Abajo van Ver, Despachar
 * (bodega y administración) y el resto en "...".
 *
 * Al hacer clic en la tarjeta: a quien le toca despachar se le abre el modal
 * para quitar lo que no hay o bajar cantidades; a los demás, el pedido.
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
            ->recordAction(fn (Pedido $record): ?string => Gate::allows('validar', $record) ? 'validarDespacho' : null)
            ->recordUrl(fn (Pedido $record): ?string => Gate::allows('validar', $record) ? null : PedidoResource::getUrl('view', ['record' => $record]))
            ->extraAttributes(['class' => 'pedidos-tarjetas'])
            ->searchPlaceholder('Buscar por cliente, código, número de pedido o vendedor…')
            ->defaultSort('created_at', 'desc')
            ->paginated([12, 24, 48])
            ->defaultPaginationPageOption(12)
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
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['desde'] ?? null, fn (Builder $q, mixed $fecha): Builder => $q->whereDate('fecha', '>=', $fecha))
                        ->when($data['hasta'] ?? null, fn (Builder $q, mixed $fecha): Builder => $q->whereDate('fecha', '<=', $fecha))),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Ver')
                    ->button()
                    ->outlined()
                    ->color('gray'),
                ValidarDespachoAction::make()
                    ->label('Despachar')
                    ->button()
                    ->outlined()
                    ->color('gray'),
                ActionGroup::make([
                    EditAction::make(),
                    ImprimirPedidoAction::make(),
                    AnularPedidoAction::make(),
                ])
                    ->icon(Heroicon::EllipsisHorizontal)
                    ->color('gray')
                    ->tooltip('Más'),
            ]);
    }

    /**
     * Número de pedido, cliente (nombre o código) o vendedor.
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
