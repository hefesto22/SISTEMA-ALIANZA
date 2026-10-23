<?php

declare(strict_types=1);

namespace App\Filament\Resources\Facturas;

use App\Enums\EstadoFactura;
use App\Filament\Resources\Facturas\Pages\ListFacturas;
use App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Filament\Support\Acceso;
use App\Models\Factura;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Historial de facturas: las vigentes y las anuladas (con quién, cuándo,
 * por qué y cuál la sustituyó). Las anuladas hay que retirarlas al cliente
 * y guardarlas marcadas ANULADA.
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
                TextColumn::make('numero')
                    ->label('Número')
                    ->fontFamily(FontFamily::Mono)
                    ->weight('bold')
                    ->copyable()
                    ->searchable()
                    ->sortable()
                    ->description(fn (Factura $record): string => 'Pedido '.$record->pedido->numero),
                TextColumn::make('fecha_emision')
                    ->label('Emitida')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->description(fn (Factura $record): ?string => $record->emisor?->name),
                TextColumn::make('cliente_nombre')
                    ->label('Cliente')
                    ->searchable()
                    ->wrap()
                    ->description(fn (Factura $record): ?string => $record->cliente_rtn ? 'RTN '.$record->cliente_rtn : null),
                TextColumn::make('bodega.nombre')
                    ->label('Bodega')
                    ->visible(fn (): bool => Acceso::veTodo()),
                TextColumn::make('total')
                    ->formatStateUsing(fn (float $state): string => lempiras($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('estado')
                    ->badge(),
                TextColumn::make('detalle')
                    ->label('Historial')
                    ->state(fn (Factura $record): string => self::historial($record))
                    ->color(fn (Factura $record): ?string => $record->estaAnulada() && $record->reemplazo === null ? 'danger' : null)
                    ->wrap(),
            ])
            ->defaultSort('fecha_emision', 'desc')
            ->searchPlaceholder('Buscar por número de factura o cliente…')
            ->filters([
                SelectFilter::make('estado')
                    ->options(EstadoFactura::class),
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
                Action::make('copia')
                    ->label('Imprimir copia')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->visible(fn (Factura $record): bool => ! $record->estaAnulada() && Gate::allows('imprimir', $record->pedido))
                    ->dispatch(ImprimirPedidoAction::EVENTO, fn (Factura $record): array => ImprimirPedidoAction::datos(route('pedidos.imprimir', $record->pedido_id))),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFacturas::route('/'),
        ];
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

        if ($factura->estaAnulada()) {
            $partes[] = trim('Anulada '.$factura->anulada_at?->format('d/m/Y H:i').' '.($factura->anulador?->name ?? ''))
                .($factura->motivo_anulacion ? ": {$factura->motivo_anulacion}" : '');
            $partes[] = $factura->reemplazo !== null
                ? "Sustituida por {$factura->reemplazo->numero}"
                : 'Falta reimprimir el pedido';
        }

        return $partes === [] ? '—' : implode(' · ', $partes);
    }
}
