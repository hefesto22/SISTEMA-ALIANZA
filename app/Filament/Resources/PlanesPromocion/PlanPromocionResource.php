<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanesPromocion;

use App\Domain\Promociones\AvancePlan;
use App\Filament\Resources\PlanesPromocion\Pages\CreatePlanPromocion;
use App\Filament\Resources\PlanesPromocion\Pages\EditPlanPromocion;
use App\Filament\Resources\PlanesPromocion\Pages\ListPlanesPromocion;
use App\Filament\Resources\PlanesPromocion\Pages\ViewPlanPromocion;
use App\Filament\Resources\PlanesPromocion\Schemas\PlanPromocionForm;
use App\Filament\Support\Acceso;
use App\Models\PlanPromocion;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Planes de promociones que manda cada proveedor para el mes (los "OPL").
 * Se registran como vienen en la hoja: una línea por cada mecánica
 * autorizada, con su tope de unidades de regalo. Cada línea queda como una
 * promoción del plan que la preventa aplica sola, sin pasar del tope.
 *
 * En la lista y al verlo: cuánto se ha regalado de lo autorizado (entregado
 * y comprometido) y cuánto vale al costo. Administración los arma para una
 * o varias bodegas (ninguna = todas); el encargado, los de su bodega. El
 * vendedor no los ve (ve las promociones en la preventa).
 *
 * @extends \Filament\Resources\Resource<PlanPromocion>
 */
class PlanPromocionResource extends Resource
{
    protected static ?string $model = PlanPromocion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Plan del proveedor';

    protected static ?string $pluralModelLabel = 'Planes de proveedores';

    protected static ?string $slug = 'planes-proveedor';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return 'Ventas';
    }

    public static function form(Schema $schema): Schema
    {
        return PlanPromocionForm::configure($schema);
    }

    /**
     * Ver: el avance línea por línea (resources/views/filament/planes-promocion/avance.blade.php).
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                View::make('filament.planes-promocion.avance')
                    ->viewData(fn (PlanPromocion $record): array => [
                        'plan'   => $record,
                        'avance' => AvancePlan::de($record),
                        'hoja'   => $record->archivo !== null ? Storage::disk(PlanPromocion::DISCO)->url($record->archivo) : null,
                    ]),
            ]);
    }

    /**
     * Administración ve todos; el encargado, los que valen en su bodega.
     */
    public static function getEloquentQuery(): Builder
    {
        $usuario = Acceso::usuario();
        $query = PlanPromocion::query()->with(['proveedor', 'bodegas'])->withCount('promociones');

        return $usuario === null ? $query->whereRaw('1 = 0') : $query->visiblesPara($usuario);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('proveedor.nombre')
                    ->label('Proveedor')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->wrap(),
                TextColumn::make('nombre')
                    ->label('Plan')
                    ->searchable()
                    ->wrap()
                    ->description(fn (PlanPromocion $record): string => ((int) $record->getAttribute('promociones_count') === 1 ? '1 línea' : ((int) $record->getAttribute('promociones_count')).' líneas').' · '.$record->bodegasTexto()),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->state(fn (PlanPromocion $record): string => $record->estado())
                    ->badge()
                    ->color(fn (PlanPromocion $record): string => $record->colorEstado()),
                TextColumn::make('vigente_desde')
                    ->label('Vigencia')
                    ->state(fn (PlanPromocion $record): string => $record->vigenciaTexto())
                    ->sortable()
                    ->color('gray'),
                ViewColumn::make('avance')
                    ->label('Regalo autorizado')
                    ->view('filament.planes-promocion.columna-avance')
                    ->state(fn (PlanPromocion $record): array => AvancePlan::de($record)['totales']),
            ])
            ->defaultSort('vigente_desde', 'desc')
            ->filters([
                SelectFilter::make('proveedor_id')
                    ->label('Proveedor')
                    ->relationship('proveedor', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make()->label('Ver avance'),
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Se borran el plan y sus promociones. Solo se puede si todavía no se dio ningún regalo del plan.'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentList)
            ->emptyStateHeading('Todavía no hay planes de proveedores')
            ->emptyStateDescription('Registre el plan que manda el proveedor con "Nuevo plan": una línea por cada mecánica autorizada y su tope.');
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListPlanesPromocion::route('/'),
            'create' => CreatePlanPromocion::route('/create'),
            'view'   => ViewPlanPromocion::route('/{record}'),
            'edit'   => EditPlanPromocion::route('/{record}/edit'),
        ];
    }
}
