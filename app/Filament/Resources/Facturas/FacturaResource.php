<?php

declare(strict_types=1);

namespace App\Filament\Resources\Facturas;

use App\Enums\ModoImpresion;
use App\Filament\Resources\Facturas\Pages\ListFacturas;
use App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Filament\Support\Acceso;
use App\Models\Factura;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Historial de facturas en tarjetas (como Pedidos): las vigentes y las
 * anuladas (con quién, cuándo, por qué y cuál la sustituyó). Las anuladas
 * hay que retirarlas al cliente y guardarlas marcadas ANULADA.
 *
 * Solo consulta: las facturas se emiten al imprimir un pedido y se anulan
 * al corregirlo.
 *
 * @extends \Filament\Resources\Resource<Factura>
 */
class FacturaResource extends Resource
{
    protected static ?string $model = Factura::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'numero';

    protected static ?string $modelLabel = 'Factura';

    protected static ?string $pluralModelLabel = 'Facturas';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Ventas';
    }

    /**
     * Las de los pedidos que esta persona ve: administración todas, bodega
     * las de su bodega.
     */
    public static function getEloquentQuery(): Builder
    {
        $usuario = Acceso::usuario();
        $query = Factura::query()->with(['pedido.vendedor', 'bodega', 'reemplazaA', 'reemplazo', 'anulador', 'emisor']);

        return $usuario === null ? $query->whereRaw('1 = 0') : $query->visiblesPara($usuario);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Stack::make([
                    ViewColumn::make('tarjeta')
                        ->view('filament.facturas.tarjeta')
                        ->state(fn (Factura $record): string => $record->numero)
                        ->searchable(query: fn (Builder $query, string $search): Builder => self::buscar($query, $search)),
                ]),
            ])
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->extraAttributes(['class' => 'facturas-tarjetas'])
            ->defaultSort('fecha_emision', 'desc')
            ->paginated([12, 24, 48])
            ->defaultPaginationPageOption(12)
            ->searchPlaceholder('Buscar por número de factura o de pedido, cliente o RTN…')
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(['md' => 2, 'xl' => 4])
            ->filters([
                SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre')
                    ->visible(fn (): bool => Acceso::veTodo()),
                Filter::make('fecha_emision')
                    ->label('Fecha de emisión')
                    ->schema([
                        DatePicker::make('desde'),
                        DatePicker::make('hasta'),
                    ])
                    ->columns(2)
                    ->columnSpan(2)
                    ->query(function (Builder $query, array $data): Builder {
                        if (filled($data['desde'] ?? null)) {
                            $query->whereDate('fecha_emision', '>=', $data['desde']);
                        }

                        if (filled($data['hasta'] ?? null)) {
                            $query->whereDate('fecha_emision', '<=', $data['hasta']);
                        }

                        return $query;
                    }),
            ])
            ->recordUrl(fn (Factura $record): string => PedidoResource::getUrl('view', ['record' => $record->pedido_id]))
            ->recordActions([
                Action::make('pedido')
                    ->label('Ver pedido')
                    ->icon(Heroicon::OutlinedEye)
                    ->button()
                    ->outlined()
                    ->color('gray')
                    ->url(fn (Factura $record): string => PedidoResource::getUrl('view', ['record' => $record->pedido_id])),
                Action::make('copia')
                    ->label('Imprimir copia')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->button()
                    ->outlined()
                    ->visible(fn (Factura $record): bool => ! $record->estaAnulada() && Gate::allows('imprimir', $record->pedido))
                    ->action(function (Factura $record, Component $livewire): void {
                        ImprimirPedidoAction::ejecutar($livewire, [$record->pedido_id], ModoImpresion::Copia);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFacturas::route('/'),
        ];
    }

    /**
     * Número de factura o de pedido, cliente o RTN.
     *
     * @param Builder<Model> $query
     *
     * @return Builder<Model>
     */
    private static function buscar(Builder $query, string $search): Builder
    {
        $like = '%'.$search.'%';

        return $query->where(fn (Builder $q): Builder => $q
            ->where('numero', 'ilike', $like)
            ->orWhere('cliente_nombre', 'ilike', $like)
            ->orWhere('cliente_rtn', 'ilike', $like)
            ->orWhereHas('pedido', fn (Builder $p): Builder => $p->where('numero', 'ilike', $like)));
    }

    /**
     * "Sustituye a … · Anulada 23/10/2026 por ANA: motivo · Sustituida por …"
     */
    public static function historial(Factura $factura): string
    {
        $partes = [];

        if ($factura->reemplazaA !== null) {
            $partes[] = "Sustituye a {$factura->reemplazaA->numero}";
        }

        if ($factura->impresiones > 1) {
            $partes[] = ($factura->impresiones - 1).' copia(s)';
        }

        if ($factura->reintentos > 0) {
            $partes[] = "Reimpresa {$factura->reintentos} vez(es) porque no salió";
        }

        if ($factura->estaAnulada()) {
            $partes[] = trim('Anulada '.$factura->anulada_at?->format('d/m/Y H:i').' '.($factura->anulador->name ?? ''))
                .($factura->motivo_anulacion ? ": {$factura->motivo_anulacion}" : '');
            $partes[] = $factura->reemplazo !== null
                ? "Sustituida por {$factura->reemplazo->numero}"
                : 'Falta reimprimir el pedido';
        }

        return $partes === [] ? '—' : implode(' · ', $partes);
    }
}
