<?php

declare(strict_types=1);

namespace App\Filament\Resources\Rutas;

use App\Enums\Rol;
use App\Filament\Resources\Rutas\Pages\ManageRutas;
use App\Filament\Support\Acceso;
use App\Models\Departamento;
use App\Models\Municipio;
use App\Models\Ruta;
use App\Models\User;
use App\Support\Texto;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Unique;

/**
 * Rutas de cada bodega: municipios que cubre y vendedores que la trabajan.
 * Cada vendedor solo ve y crea clientes de los municipios de sus rutas.
 *
 * @extends \Filament\Resources\Resource<Ruta>
 */
class RutaResource extends Resource
{
    protected static ?string $model = Ruta::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Ruta';

    protected static ?string $pluralModelLabel = 'Rutas';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Administración';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre', fn (Builder $query): Builder => $query->where('activa', true))
                    ->preload()
                    ->required()
                    ->live(),
                TextInput::make('nombre')
                    ->label('Nombre de la ruta')
                    ->placeholder('Ej. Ruta 1 - Santa Rosa centro')
                    ->required()
                    ->maxLength(120)
                    // Se guarda en mayúsculas: se compara igual para no chocar con "RUTA 1" vs "ruta 1".
                    ->mutateStateForValidationUsing(fn (?string $state): ?string => filled($state) ? Texto::mayusculas($state) : $state)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('bodega_id', $get('bodega_id'))),
                Select::make('agregar_departamento')
                    ->label('Agregar un departamento completo')
                    ->options(fn (): array => Departamento::opciones())
                    ->placeholder('Escoja un departamento')
                    ->searchable()
                    ->dehydrated(false)
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                        if (blank($state)) {
                            return;
                        }

                        $set('municipios', self::conDepartamento($get('municipios'), (int) $state));
                        $set('agregar_departamento', null);
                    })
                    ->helperText('Agrega todos sus municipios a la lista; después puede quitar los que no van.')
                    ->columnSpanFull(),
                Select::make('municipios')
                    ->label('Municipios que cubre')
                    ->relationship(
                        'municipios',
                        'nombre',
                        fn (Builder $query): Builder => $query->with('departamento')->orderBy('departamento_id')->orderBy('nombre'),
                    )
                    ->getOptionLabelFromRecordUsing(fn (Municipio $record): string => $record->etiqueta())
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->required()
                    ->placeholder('Buscar municipio…')
                    ->columnSpanFull(),
                Select::make('vendedores')
                    ->label('Vendedores')
                    ->relationship(
                        'vendedores',
                        'name',
                        // Vendedores, más los que ya tenga la ruta aunque después les cambiaran el rol.
                        fn (Builder $query, ?Ruta $record): Builder => $query
                            ->where(function (Builder $q) use ($record): void {
                                $q->whereHas('roles', fn (Builder $r): Builder => $r->where('name', Rol::Vendedor->value));

                                if ($record !== null) {
                                    $q->orWhereIn('users.id', DB::table('ruta_vendedor')->where('ruta_id', $record->id)->select('user_id'));
                                }
                            })
                            ->orderBy('name'),
                    )
                    ->getOptionLabelFromRecordUsing(fn (User $record): string => filled($record->codigo) ? "{$record->codigo} · {$record->name}" : $record->name)
                    ->multiple()
                    ->searchable(['name', 'codigo'])
                    ->preload()
                    ->helperText('Para que un vendedor cubra a otro (vacaciones, suplencia), agréguelo aquí mientras tanto.')
                    ->columnSpanFull(),
                Toggle::make('activa')
                    ->default(true)
                    ->helperText('Si se desactiva, sus vendedores dejan de ver los clientes de estos municipios.'),
            ]);
    }

    /**
     * Administración ve todas; bodega, las suyas.
     */
    public static function getEloquentQuery(): Builder
    {
        $usuario = Acceso::usuario();
        $query = Ruta::query()->with(['bodega', 'municipios.departamento', 'vendedores']);

        if ($usuario === null) {
            return $query->whereRaw('1 = 0');
        }

        return $usuario->veTodasLasBodegas() ? $query : $query->where('bodega_id', $usuario->bodega_id);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')
                    ->label('Ruta')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Ruta $record): string => $record->bodega->nombre),
                TextColumn::make('municipios')
                    ->label('Municipios')
                    ->state(fn (Ruta $record): array => $record->municipios->map(fn (Municipio $m): string => $m->etiqueta())->all())
                    ->badge()
                    ->color('gray')
                    ->limitList(4)
                    ->expandableLimitedList(),
                TextColumn::make('vendedores.name')
                    ->label('Vendedores')
                    ->badge()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->placeholder('Sin vendedor'),
                TextColumn::make('clientes')
                    ->label('Clientes')
                    ->state(fn (Ruta $record): int => $record->totalClientes())
                    ->numeric(),
                IconColumn::make('activa')
                    ->boolean(),
            ])
            ->defaultSort('nombre')
            ->filters([
                SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre')
                    ->visible(fn (): bool => Acceso::veTodo()),
                SelectFilter::make('vendedor')
                    ->label('Vendedor')
                    ->relationship('vendedores', 'name')
                    ->searchable(),
                TernaryFilter::make('activa')
                    ->default(true),
            ])
            ->emptyStateDescription('Cree una ruta por cada zona que se trabaja: escoja la bodega, los municipios y los vendedores.')
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::FourExtraLarge),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRutas::route('/'),
        ];
    }

    /**
     * Los municipios que ya tenía más todos los del departamento, sin repetir.
     *
     * @return list<string>
     */
    private static function conDepartamento(mixed $municipios, int $departamentoId): array
    {
        $actuales = is_array($municipios) ? array_map(strval(...), $municipios) : [];

        $nuevos = Municipio::query()
            ->where('departamento_id', $departamentoId)
            ->orderBy('nombre')
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return array_values(array_unique([...$actuales, ...$nuevos]));
    }
}
