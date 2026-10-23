<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bodegas;

use App\Filament\Resources\Bodegas\Pages\ManageBodegas;
use App\Filament\Schemas\Components\UbicacionFields;
use App\Models\Bodega;
use App\Support\Texto;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BodegaResource extends Resource
{
    protected static ?string $model = Bodega::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Bodega';

    protected static ?string $pluralModelLabel = 'Bodegas';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Administración';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('codigo')
                    ->label('Código')
                    ->helperText('Prefijo de los pedidos de esta bodega. Ej: COP, OCO.')
                    ->required()
                    ->maxLength(10)
                    ->mutateStateForValidationUsing(fn (?string $state): ?string => filled($state) ? Texto::mayusculas($state) : $state)
                    ->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (?string $state): string => mb_strtoupper(trim((string) $state))),
                TextInput::make('nombre')
                    ->required()
                    ->maxLength(255),
                ...UbicacionFields::make(required: false),
                TextInput::make('direccion')
                    ->label('Dirección')
                    ->maxLength(255),
                TextInput::make('telefono')
                    ->label('Teléfono')
                    ->tel()
                    ->maxLength(30),
                Toggle::make('activa')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('municipio.nombre')
                    ->label('Municipio')
                    ->description(fn (Bodega $record): ?string => $record->municipio?->departamento->nombre)
                    ->placeholder('—'),
                TextColumn::make('rutas_count')
                    ->label('Rutas')
                    ->counts('rutas'),
                TextColumn::make('usuarios_count')
                    ->label('Usuarios')
                    ->counts('usuarios'),
                TextColumn::make('direccion')
                    ->label('Dirección')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->placeholder('—'),
                IconColumn::make('activa')
                    ->boolean(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('municipio.departamento'))
            ->defaultSort('codigo')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBodegas::route('/'),
        ];
    }
}
