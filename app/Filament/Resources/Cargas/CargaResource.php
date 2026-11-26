<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cargas;

use App\Domain\Cargas\HojaDeCarga;
use App\Enums\EstadoCarga;
use App\Filament\Resources\Cargas\Pages\CreateCarga;
use App\Filament\Resources\Cargas\Pages\EditCarga;
use App\Filament\Resources\Cargas\Pages\Liquidacion;
use App\Filament\Resources\Cargas\Pages\ListCargas;
use App\Filament\Resources\Cargas\Pages\ViewCarga;
use App\Filament\Resources\Cargas\Schemas\CargaForm;
use App\Filament\Support\Acceso;
use App\Models\Carga;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Cargas del camión y su liquidación.
 *
 * La carga: lo que sale un día en un camión (quién sale, el camión y las
 * facturas impresas que lleva). Con cuánto sale es lo que debe regresar en
 * dinero. Al regresar se liquida: cada factura se entregó, o no se entregó
 * y se anula o sale en otra carga; se anotan el efectivo, los cheques y las
 * transferencias, y queda el cuadre (debía traer, trajo, diferencia).
 *
 * Hojas para imprimir: de carga (lo que se sube), de cobro (para anotar en
 * la ruta) y la liquidación (para firmar). Las arman y las liquidan los que
 * despachan (CargaPolicy); el vendedor no las ve.
 *
 * @extends \Filament\Resources\Resource<Carga>
 */
class CargaResource extends Resource
{
    protected static ?string $model = Carga::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $modelLabel = 'Carga';

    protected static ?string $pluralModelLabel = 'Cargas y liquidaciones';

    protected static ?string $navigationLabel = 'Cargas y liquidaciones';

    protected static ?string $breadcrumb = 'Cargas y liquidaciones';

    protected static ?string $slug = 'cargas';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Ventas';
    }

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record instanceof Carga ? 'Carga '.$record->numero() : 'Carga';
    }

    public static function form(Schema $schema): Schema
    {
        return CargaForm::configure($schema);
    }

    /**
     * Ver: en ruta, lo que lleva; liquidada, el cuadre (resources/views/filament/cargas/ver.blade.php).
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                View::make('filament.cargas.ver')
                    ->viewData(fn (Carga $record): array => ['d' => HojaDeCarga::datos($record)]),
            ]);
    }

    /**
     * Administración ve todas; el encargado, las de su bodega.
     */
    public static function getEloquentQuery(): Builder
    {
        $usuario = Acceso::usuario();
        $query = Carga::query()->with(['bodega:id,nombre', 'repartidor:id,name'])->withCount('renglones');

        return $usuario === null ? $query->whereRaw('1 = 0') : $query->visiblesPara($usuario);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Carga')
                    ->formatStateUsing(fn (Carga $record): string => $record->numero())
                    ->fontFamily(FontFamily::Mono)
                    ->size(TextSize::Small)
                    ->weight(FontWeight::SemiBold)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $numero = ltrim((string) preg_replace('/\D/', '', $search), '0');

                        return $query->where(fn (Builder $q): Builder => $q
                            ->whereHas('repartidor', fn (Builder $r): Builder => $r->where('name', 'ilike', "%{$search}%"))
                            ->when($numero !== '' && strlen($numero) <= 9, fn (Builder $c): Builder => $c->orWhere('cargas.id', (int) $numero)));
                    }),
                TextColumn::make('fecha')
                    ->date('d/m/Y')
                    ->size(TextSize::Small)
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('repartidor.name')
                    ->label('Quién sale')
                    ->wrap(),
                TextColumn::make('renglones_count')
                    ->label('Facturas')
                    ->alignEnd()
                    ->visibleFrom('md'),
                TextColumn::make('total_carga')
                    ->label('Sale con')
                    ->formatStateUsing(fn (float $state): string => lempiras($state))
                    ->weight(FontWeight::SemiBold)
                    ->alignEnd(),
                TextColumn::make('estado')
                    ->badge(),
                TextColumn::make('diferencia')
                    ->label('Cuadre')
                    ->state(fn (Carga $record): string => $record->enRuta() ? 'Por liquidar' : $record->diferenciaTexto())
                    ->color(fn (Carga $record): string => $record->colorDiferencia())
                    ->weight(fn (Carga $record): ?FontWeight => $record->enRuta() ? null : FontWeight::SemiBold),
                TextColumn::make('bodega.nombre')
                    ->label('Bodega')
                    ->size(TextSize::Small)
                    ->color('gray')
                    ->visible(fn (): bool => Acceso::veTodo())
                    ->visibleFrom('xl'),
            ])
            ->defaultSort('id', 'desc')
            ->striped()
            ->paginated([25, 50, 100])
            ->searchPlaceholder('Número, quién sale o camión…')
            ->filters([
                SelectFilter::make('estado')
                    ->options(EstadoCarga::class),
                SelectFilter::make('repartidor_id')
                    ->label('Quién sale')
                    ->relationship('repartidor', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre')
                    ->visible(fn (): bool => Acceso::veTodo()),
            ])
            ->recordActions([
                Action::make('liquidar')
                    ->label('Liquidar')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->button()
                    ->size('sm')
                    ->visible(fn (Carga $record): bool => Gate::allows('liquidar', $record))
                    ->url(fn (Carga $record): string => self::getUrl('liquidar', ['record' => $record])),
            ])
            ->emptyStateIcon(Heroicon::OutlinedTruck)
            ->emptyStateHeading('Todavía no hay cargas')
            ->emptyStateDescription('Arme la carga del camión con "Nueva carga" o desde Pedidos → Impresos ("Cargar camión").');
    }

    public static function getPages(): array
    {
        return [
            'index'    => ListCargas::route('/'),
            'create'   => CreateCarga::route('/create'),
            'view'     => ViewCarga::route('/{record}'),
            'edit'     => EditCarga::route('/{record}/edit'),
            'liquidar' => Liquidacion::route('/{record}/liquidar'),
        ];
    }
}
