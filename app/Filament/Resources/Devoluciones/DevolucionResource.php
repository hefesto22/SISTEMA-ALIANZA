<?php

declare(strict_types=1);

namespace App\Filament\Resources\Devoluciones;

use App\Enums\TipoDevolucion;
use App\Filament\Resources\Devoluciones\Pages\ListDevoluciones;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Filament\Support\Acceso;
use App\Models\Devolucion;
use App\Models\DevolucionItem;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Historial de devoluciones, una por renglón: qué regresó (bueno o dañado),
 * si fue cambio o crédito y su nota de crédito. Se registran desde el
 * pedido ("Devolución"); aquí solo se consultan y se imprimen.
 *
 * @extends \Filament\Resources\Resource<Devolucion>
 */
class DevolucionResource extends Resource
{
    protected static ?string $model = Devolucion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static ?string $recordTitleAttribute = 'numero';

    protected static ?string $modelLabel = 'Devolución';

    protected static ?string $pluralModelLabel = 'Devoluciones';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Ventas';
    }

    /**
     * Las de los pedidos que esta persona ve.
     */
    public static function getEloquentQuery(): Builder
    {
        $usuario = Acceso::usuario();
        $query = Devolucion::query()->with(['pedido:id,numero', 'cliente:id,nombre', 'bodega:id,nombre', 'items.producto:id,descripcion', 'items.presentacion.unidadMedida']);

        return $usuario === null ? $query->whereRaw('1 = 0') : $query->visiblesPara($usuario);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('numero')
                    ->label('Devolución')
                    ->fontFamily(FontFamily::Mono)
                    ->size(TextSize::Small)
                    ->weight(FontWeight::SemiBold)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q): Builder => $q
                        ->where('devoluciones.numero', 'ilike', "%{$search}%")
                        ->orWhere('nota_numero', 'ilike', "%{$search}%")
                        ->orWhereHas('pedido', fn (Builder $p): Builder => $p->where('numero', 'ilike', "%{$search}%"))
                        ->orWhereHas('cliente', fn (Builder $c): Builder => $c->where('nombre', 'ilike', "%{$search}%")))),
                TextColumn::make('fecha')
                    ->date('d/m/Y')
                    ->size(TextSize::Small)
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('cliente.nombre')
                    ->label('Cliente')
                    ->wrap(),
                TextColumn::make('pedido.numero')
                    ->label('Pedido')
                    ->size(TextSize::Small)
                    ->color('gray')
                    ->visibleFrom('lg'),
                TextColumn::make('productos')
                    ->label('Qué regresó')
                    ->state(fn (Devolucion $record): string => self::productos($record))
                    ->size(TextSize::ExtraSmall)
                    ->wrap()
                    ->visibleFrom('md'),
                TextColumn::make('tipo')
                    ->badge(),
                TextColumn::make('nota_numero')
                    ->label('Nota de crédito')
                    ->placeholder(fn (Devolucion $record): string => $record->tipo === TipoDevolucion::Credito ? 'Interno' : '—')
                    ->fontFamily(FontFamily::Mono)
                    ->size(TextSize::Small)
                    ->visibleFrom('md'),
                TextColumn::make('total')
                    ->formatStateUsing(fn (float $state): string => lempiras($state))
                    ->weight(FontWeight::SemiBold)
                    ->alignEnd(),
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
            ->searchPlaceholder('Devolución, nota, pedido o cliente…')
            ->filters([
                SelectFilter::make('tipo')
                    ->options(TipoDevolucion::class),
                SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre')
                    ->visible(fn (): bool => Acceso::veTodo()),
            ])
            ->recordUrl(fn (Devolucion $record): string => PedidoResource::getUrl('view', ['record' => $record->pedido_id]))
            ->recordActions([
                Action::make('imprimir')
                    ->label(fn (Devolucion $record): string => $record->conNota() ? 'Imprimir nota' : 'Imprimir constancia')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->iconButton()
                    ->tooltip(fn (Devolucion $record): string => $record->conNota() ? 'Imprimir nota de crédito' : 'Imprimir constancia')
                    ->url(fn (Devolucion $record): string => route('devoluciones.imprimir', $record), shouldOpenInNewTab: true),
            ]);
    }

    /**
     * "2 UN JABON (dañadas) · 1 CJ12 ACEITE".
     */
    public static function productos(Devolucion $devolucion): string
    {
        return $devolucion->items
            ->map(fn (DevolucionItem $item): string => "{$item->cantidad} {$item->presentacion->sigla()} {$item->producto->descripcion}".($item->danado ? ' (dañado)' : ''))
            ->implode(' · ');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDevoluciones::route('/'),
        ];
    }
}
