<?php

declare(strict_types=1);

namespace App\Filament\Resources\ComisionGrupos;

use App\Domain\Comisiones\GuardarGrupoComision;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Filament\Resources\ComisionGrupos\Pages\ManageComisionGrupos;
use App\Models\Categoria;
use App\Models\ComisionGrupo;
use App\Support\Texto;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Grupos de comisión: "LIMPIEZA" junta categorías de varios proveedores y
 * tiene su escala (0.5% desde 0, 1% desde 80,000, 2% desde 100,000). Lo
 * que no está en un grupo no paga comisión.
 *
 * Administración los arma; el encargado de bodega los ve (las metas propias
 * de sus vendedores se ponen en Metas por vendedor).
 *
 * @extends \Filament\Resources\Resource<ComisionGrupo>
 */
class ComisionGrupoResource extends Resource
{
    protected static ?string $model = ComisionGrupo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Grupo de comisión';

    protected static ?string $pluralModelLabel = 'Grupos de comisión';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Comisiones';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('nombre')
                    ->label('Nombre del grupo')
                    ->placeholder('Ej. Limpieza')
                    ->required()
                    ->maxLength(80)
                    ->mutateStateForValidationUsing(fn (?string $state): ?string => filled($state) ? Texto::mayusculas($state) : $state)
                    ->unique(ignoreRecord: true),
                Toggle::make('activo')
                    ->label('Paga comisión')
                    ->default(true)
                    ->inline(false)
                    ->helperText('Apagado, lo que se vende de sus categorías no paga comisión.'),
                TextInput::make('descripcion')
                    ->label('Descripción')
                    ->placeholder('Opcional')
                    ->maxLength(255)
                    ->columnSpanFull(),
                Select::make('categorias')
                    ->label('Categorías que entran')
                    ->options(fn (?ComisionGrupo $record): array => self::opcionesCategorias($record))
                    ->multiple()
                    ->searchable()
                    ->placeholder('Buscar categoría…')
                    ->helperText('De cualquier proveedor. Una categoría va en un solo grupo: si escoge una que está en otro, se pasa a este.')
                    ->columnSpanFull(),
                CampoEscalones::make()
                    ->columnSpanFull(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return ComisionGrupo::query()->with('categorias.proveedor')->withCount('metas');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')
                    ->label('Grupo')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (ComisionGrupo $record): ?string => $record->descripcion),
                TextColumn::make('escala')
                    ->label('Escala para todos')
                    ->state(fn (ComisionGrupo $record): string => $record->escala()->texto())
                    ->wrap(),
                TextColumn::make('categorias')
                    ->label('Categorías')
                    ->state(fn (ComisionGrupo $record): array => $record->categorias->map(fn (Categoria $c): string => $c->etiqueta())->sort()->values()->all())
                    ->badge()
                    ->color('gray')
                    ->limitList(4)
                    ->expandableLimitedList()
                    ->placeholder('Sin categorías'),
                TextColumn::make('metas_count')
                    ->label('Metas propias')
                    ->state(fn (ComisionGrupo $record): string => self::conMetaPropia((int) $record->getAttribute('metas_count')))
                    ->color('gray'),
                IconColumn::make('activo')
                    ->label('Paga')
                    ->boolean(),
            ])
            ->defaultSort('nombre')
            ->filters([
                TernaryFilter::make('activo')
                    ->label('Paga comisión'),
            ])
            ->emptyStateHeading('Sin grupos de comisión')
            ->emptyStateDescription('Cree un grupo (ej. Limpieza), escoja sus categorías y ponga la escala: desde cuánto vende y qué % gana.')
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::FourExtraLarge)
                    ->mutateRecordDataUsing(fn (array $data, ComisionGrupo $record): array => [
                        ...$data,
                        'categorias' => $record->categorias->pluck('id')->all(),
                        'escalones'  => $record->escala()->paraGuardar(),
                    ])
                    ->using(fn (array $data, ComisionGrupo $record): ComisionGrupo => self::guardar($record, $data)),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageComisionGrupos::route('/'),
        ];
    }

    /**
     * Con lo que no se pudo guardar, avisa y deja el formulario abierto.
     *
     * @param array<string, mixed> $data
     */
    public static function guardar(?ComisionGrupo $grupo, array $data): ComisionGrupo
    {
        try {
            return app(GuardarGrupoComision::class)->ejecutar($grupo, $data);
        } catch (ReglaNegocioException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }

    /**
     * "1 vendedor", "3 vendedores" o "—".
     */
    private static function conMetaPropia(int $cuantos): string
    {
        return match ($cuantos) {
            0       => '—',
            1       => '1 vendedor',
            default => $cuantos.' vendedores',
        };
    }

    /**
     * "MERHONSA · DETERGENTES" (y a qué grupo pertenece si es de otro).
     *
     * @return array<int, string>
     */
    private static function opcionesCategorias(?ComisionGrupo $record): array
    {
        return Categoria::query()
            ->with(['proveedor', 'comisionGrupo'])
            ->get()
            ->sortBy(fn (Categoria $c): string => $c->etiqueta())
            ->mapWithKeys(fn (Categoria $c): array => [
                $c->id => $c->etiqueta().($c->comisionGrupo !== null && $c->comision_grupo_id !== $record?->id ? ' (hoy en '.$c->comisionGrupo->nombre.')' : ''),
            ])
            ->all();
    }
}
