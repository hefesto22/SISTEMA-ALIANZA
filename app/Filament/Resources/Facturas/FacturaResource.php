<?php

declare(strict_types=1);

namespace App\Filament\Resources\Facturas;

use App\Enums\ModoImpresion;
use App\Filament\Resources\Facturas\Pages\ListFacturas;
use App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Filament\Support\Acceso;
use App\Models\Factura;
use App\Models\Pedido;
use App\Models\Ruta;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Historial de facturas, una por renglón: las vigentes y las anuladas (con
 * quién, cuándo, por qué y cuál la sustituyó). Las anuladas hay que
 * retirarlas al cliente y guardarlas marcadas ANULADA.
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
     * las de su bodega. Solo se cargan las columnas que la lista usa.
     */
    public static function getEloquentQuery(): Builder
    {
        $usuario = Acceso::usuario();
        $query = Factura::query()->with([
            'pedido:id,numero,estado,bodega_id,ruta_id',
            'pedido.ruta:id,bodega_id,numero',
            'pedido.ruta.bodega:id,codigo,prefijo_ruta',
            'bodega:id,nombre',
            'reemplazaA:id,numero',
            'reemplazo:id,reemplaza_a_id,numero',
            'anulador:id,name',
        ]);

        return $usuario === null ? $query->whereRaw('1 = 0') : $query->visiblesPara($usuario);
    }

    /**
     * Una factura por renglón, para que mil se lean igual de rápido: número,
     * fecha, cliente, pedido, total y estado. Lo que pasó con ella (a cuál
     * sustituye, por qué se anuló) va en el estado al pasar el mouse, y en
     * su columna en Anuladas y Todas. En el teléfono quedan número, cliente
     * y total.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('numero')
                    ->label('Factura')
                    ->fontFamily(FontFamily::Mono)
                    ->size(TextSize::Small)
                    ->weight(FontWeight::SemiBold)
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::buscar($query, $search)),
                TextColumn::make('fecha_emision')
                    ->label('Emitida')
                    ->dateTime('d/m/Y H:i')
                    ->size(TextSize::Small)
                    ->color('gray')
                    ->sortable()
                    ->visibleFrom('md'),
                // En una línea: toma el espacio que sobra y lo largo se corta con "…"; al pasar el mouse se ve completo.
                TextColumn::make('cliente_nombre')
                    ->label('Cliente')
                    ->tooltip(fn (Factura $record): string => $record->cliente_nombre)
                    ->extraCellAttributes(['class' => 'celda-recortada']),
                TextColumn::make('pedido.numero')
                    ->label('Pedido')
                    ->size(TextSize::Small)
                    ->color('gray')
                    ->visibleFrom('lg'),
                TextColumn::make('ruta')
                    ->label('Ruta')
                    ->state(fn (Factura $record): ?string => $record->pedido->ruta?->codigo())
                    ->placeholder('—')
                    ->size(TextSize::Small)
                    ->weight(FontWeight::SemiBold)
                    ->visibleFrom('md'),
                TextColumn::make('bodega.nombre')
                    ->label('Bodega')
                    ->size(TextSize::Small)
                    ->color('gray')
                    ->visible(fn (): bool => Acceso::veTodo())
                    ->visibleFrom('xl'),
                TextColumn::make('total')
                    ->formatStateUsing(fn (float $state): string => lempiras($state))
                    ->weight(FontWeight::SemiBold)
                    ->color(fn (Factura $record): ?string => $record->estaAnulada() ? 'gray' : null)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('estado')
                    ->badge()
                    ->tooltip(fn (Factura $record): ?string => ($historial = self::historial($record)) === '—' ? null : $historial)
                    ->visibleFrom('sm'),
                TextColumn::make('historial')
                    ->label('Qué pasó')
                    ->state(fn (Factura $record): string => self::historial($record))
                    ->color(fn (Factura $record): string => $record->estaAnulada() && $record->reemplazo === null ? 'danger' : 'gray')
                    ->size(TextSize::ExtraSmall)
                    ->wrap()
                    ->extraCellAttributes(['style' => 'min-width: 13rem'])
                    ->visible(fn (Component $livewire): bool => ! in_array(data_get($livewire, 'activeTab'), [null, 'vigentes'], true))
                    ->visibleFrom('md'),
            ])
            ->defaultSort('fecha_emision', 'desc')
            ->striped()
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->searchPlaceholder('Número de factura o pedido, cliente o RTN…')
            ->filters([
                SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre')
                    ->visible(fn (): bool => Acceso::veTodo()),
                SelectFilter::make('ruta')
                    ->label('Ruta')
                    ->options(fn (): array => self::rutas())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('pedido', fn (Builder $pedido): Builder => $pedido->where('ruta_id', (int) $data['value']))
                        : $query),
                Filter::make('fecha_emision')
                    ->label('Fecha de emisión')
                    ->schema([
                        DatePicker::make('desde'),
                        DatePicker::make('hasta'),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        if (filled($data['desde'] ?? null)) {
                            $query->where('fecha_emision', '>=', Carbon::parse((string) $data['desde'])->startOfDay());
                        }

                        if (filled($data['hasta'] ?? null)) {
                            $query->where('fecha_emision', '<=', Carbon::parse((string) $data['hasta'])->endOfDay());
                        }

                        return $query;
                    }),
            ])
            ->recordUrl(fn (Factura $record): string => PedidoResource::getUrl('view', ['record' => $record->pedido_id]))
            ->recordActions([
                // Abre la vista previa con la impresora (LX-350 o normal) antes de imprimir.
                ImprimirPedidoAction::conVistaPrevia(
                    Action::make('copia')
                        ->label('Imprimir copia')
                        ->tooltip('Imprimir copia')
                        ->icon(Heroicon::OutlinedPrinter)
                        ->iconButton()
                        ->color('gray')
                        ->visible(fn (Factura $record): bool => ! $record->estaAnulada() && Gate::allows('imprimir', $record->pedido))
                        ->modalHeading(fn (Factura $record): string => "Imprimir copia de la factura {$record->numero}")
                        ->fillForm(fn (Factura $record): array => ImprimirPedidoAction::datosDelModal($record->pedido))
                        ->action(function (array $data, Factura $record, Component $livewire): void {
                            ImprimirPedidoAction::ejecutar($livewire, [$record->pedido_id], ModoImpresion::Copia, ImprimirPedidoAction::papel($data['papel'] ?? null));
                        }),
                    ModoImpresion::Copia,
                ),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFacturas::route('/'),
        ];
    }

    /**
     * Las rutas para el filtro: "CO22 · RUTA CENTRO" (bodega: las suyas).
     *
     * @return array<int, string>
     */
    private static function rutas(): array
    {
        $usuario = Acceso::usuario();

        return Ruta::query()
            ->when($usuario !== null && ! $usuario->veTodasLasBodegas(), fn (Builder $q): Builder => $q->where('bodega_id', $usuario?->bodega_id))
            ->with('bodega:id,codigo,prefijo_ruta')
            ->orderBy('bodega_id')
            ->orderBy('numero')
            ->get(['id', 'bodega_id', 'numero', 'nombre'])
            ->mapWithKeys(fn (Ruta $ruta): array => [$ruta->id => $ruta->codigo().' · '.$ruta->nombre])
            ->all();
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
            ->where('facturas.numero', 'ilike', $like)
            ->orWhere('facturas.cliente_nombre', 'ilike', $like)
            ->orWhere('facturas.cliente_rtn', 'ilike', $like)
            ->orWhereIn('facturas.pedido_id', Pedido::query()->where('numero', 'ilike', $like)->select('id')));
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
