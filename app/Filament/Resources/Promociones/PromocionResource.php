<?php

declare(strict_types=1);

namespace App\Filament\Resources\Promociones;

use App\Filament\Resources\Promociones\Pages\ManagePromociones;
use App\Filament\Resources\Promociones\Schemas\PromocionForm;
use App\Filament\Support\Acceso;
use App\Models\Promocion;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Promociones "si compra X, Y sale gratis / a mitad / con N%".
 *
 * Administración las crea y las activa o desactiva con el interruptor.
 * El vendedor solo ve las vigentes, y el menú le aparece únicamente
 * cuando hay alguna; si no hay, la preventa sigue igual que siempre.
 *
 * @extends \Filament\Resources\Resource<Promocion>
 */
class PromocionResource extends Resource
{
    protected static ?string $model = Promocion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Promoción';

    protected static ?string $pluralModelLabel = 'Promociones';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Ventas';
    }

    public static function form(Schema $schema): Schema
    {
        return PromocionForm::configure($schema);
    }

    /**
     * El vendedor solo ve las vigentes hoy.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = Promocion::query()
            ->with(['condiciones.producto', 'productoBeneficio'])
            ->withCount('items');

        return Acceso::esVendedor() ? $query->vigentes() : $query;
    }

    public static function shouldRegisterNavigation(): bool
    {
        if (! parent::shouldRegisterNavigation()) {
            return false;
        }

        return ! Acceso::esVendedor() || Promocion::query()->vigentes()->exists();
    }

    public static function getNavigationBadge(): ?string
    {
        $vigentes = Promocion::query()->vigentes()->count();

        return $vigentes > 0 ? (string) $vigentes : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'success';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Promociones vigentes hoy';
    }

    public static function table(Table $table): Table
    {
        $puedeEditar = Acceso::puede('Update:Promocion');
        $esVendedor = Acceso::esVendedor();

        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('nombre')
                    ->label('Promoción')
                    ->searchable()
                    ->weight('semibold')
                    ->wrap()
                    ->description(fn (Promocion $record): string => $record->resumen()),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->state(fn (Promocion $record): string => $record->estado())
                    ->badge()
                    ->color(fn (Promocion $record): string => $record->colorEstado()),
                TextColumn::make('vigencia')
                    ->label('Vigencia')
                    ->state(fn (Promocion $record): string => self::vigencia($record))
                    ->color('gray'),
                TextColumn::make('items_count')
                    ->label('Aplicada')
                    ->formatStateUsing(fn (mixed $state): string => (int) $state === 1 ? '1 vez' : ((int) $state).' veces')
                    ->alignCenter()
                    ->color('gray')
                    ->visible(! $esVendedor),
                ToggleColumn::make('activa')
                    ->label('Activa')
                    ->onColor('success')
                    ->visible($puedeEditar)
                    ->disabled(! $puedeEditar)
                    ->afterStateUpdated(function (Promocion $record, bool $state): void {
                        Notification::make()
                            ->title($state ? "{$record->codigo} activada" : "{$record->codigo} desactivada")
                            ->body($state ? 'Los vendedores ya la ven en la preventa.' : 'Ya no se aplica en pedidos nuevos.')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                TernaryFilter::make('activa')
                    ->label('Estado')
                    ->trueLabel('Activas')
                    ->falseLabel('Inactivas')
                    ->visible(! $esVendedor),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading(fn (Promocion $record): string => "Editar {$record->codigo}")
                    ->modalWidth(Width::SevenExtraLarge)
                    ->mutateDataUsing(fn (array $data): array => PromocionForm::completar($data)),
                DeleteAction::make()
                    ->hidden(fn (Promocion $record): bool => (int) $record->getAttribute('items_count') > 0),
            ])
            ->emptyStateIcon(Heroicon::OutlinedGift)
            ->emptyStateHeading($esVendedor ? 'Hoy no hay promociones' : 'Todavía no hay promociones')
            ->emptyStateDescription($esVendedor ? 'Levante sus pedidos normal.' : 'Cree una con "Nueva promoción". Cada una lleva su propio código.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePromociones::route('/'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'nombre'];
    }

    private static function vigencia(Promocion $promocion): string
    {
        $desde = $promocion->vigente_desde?->format('d/m/Y');
        $hasta = $promocion->vigente_hasta?->format('d/m/Y');

        return match (true) {
            $desde !== null && $hasta !== null => "{$desde} al {$hasta}",
            $desde !== null                    => "Desde {$desde}",
            $hasta !== null                    => "Hasta {$hasta}",
            default                            => 'Sin fecha límite',
        };
    }
}
