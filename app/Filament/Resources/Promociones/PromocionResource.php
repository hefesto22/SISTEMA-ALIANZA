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
use Illuminate\Support\Facades\Gate;

/**
 * Promociones "si compra X, Y sale gratis / a mitad / con N%".
 *
 * Cada una vale en una o varias bodegas (ninguna = todas). Administración
 * las crea y cambia todas; el encargado, las de su bodega. Se activan o
 * desactivan con el interruptor. El vendedor solo ve las vigentes de su
 * bodega, y el menú le aparece únicamente cuando hay alguna; si no hay, la
 * preventa sigue igual que siempre.
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
     * Administración ve todas; los demás, las que valen en su bodega (el
     * vendedor, solo las vigentes hoy).
     */
    public static function getEloquentQuery(): Builder
    {
        $usuario = Acceso::usuario();
        $query = Promocion::query()
            ->with(['condiciones.producto', 'productoBeneficio', 'bodegas'])
            ->withCount('items');

        if ($usuario === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->visiblesPara($usuario);

        return Acceso::esVendedor() ? $query->vigentes() : $query;
    }

    public static function shouldRegisterNavigation(): bool
    {
        if (! parent::shouldRegisterNavigation()) {
            return false;
        }

        return ! Acceso::esVendedor() || self::vigentesQueVe() > 0;
    }

    public static function getNavigationBadge(): ?string
    {
        $vigentes = self::vigentesQueVe();

        return $vigentes > 0 ? (string) $vigentes : null;
    }

    /**
     * Vigentes hoy de las que ve quien entró (las de su bodega).
     */
    public static function vigentesQueVe(): int
    {
        $usuario = Acceso::usuario();

        return $usuario === null ? 0 : Promocion::query()->vigentes()->visiblesPara($usuario)->count();
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
                TextColumn::make('bodegas_texto')
                    ->label('Bodegas')
                    ->state(fn (Promocion $record): string => $record->bodegasTexto())
                    ->color('gray')
                    ->wrap()
                    ->visible(! $esVendedor),
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
                    // El encargado no apaga las de varias bodegas ni las de todas.
                    ->disabled(fn (Promocion $record): bool => ! Gate::allows('update', $record))
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
