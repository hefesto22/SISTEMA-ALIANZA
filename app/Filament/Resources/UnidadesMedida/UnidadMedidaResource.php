<?php

declare(strict_types=1);

namespace App\Filament\Resources\UnidadesMedida;

use App\Filament\Resources\UnidadesMedida\Pages\ManageUnidadesMedida;
use App\Models\Presentacion;
use App\Models\UnidadMedida;
use App\Support\Texto;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Unidades de medida con que se arman las presentaciones de los productos:
 * UN (unidad), CJ (caja), FD (fardo), SC (saco), BT (bote)… El sistema trae
 * las más comunes; aquí se agregan las que falten. No se borran (pueden
 * estar en productos): se desactivan y ya no salen para escoger.
 */
class UnidadMedidaResource extends Resource
{
    protected static ?string $model = UnidadMedida::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Unidad de medida';

    protected static ?string $pluralModelLabel = 'Unidades de medida';

    protected static ?string $slug = 'unidades-medida';

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return 'Administración';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                TextInput::make('codigo')
                    ->label('Código')
                    ->helperText('Corto, como sale en pedidos y facturas: CJ, FD, SC, BT…')
                    ->required()
                    ->maxLength(6)
                    ->alphaNum()
                    ->mutateStateForValidationUsing(fn (?string $state): ?string => filled($state) ? Texto::mayusculas($state) : $state)
                    ->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (?string $state): string => mb_strtoupper(trim((string) $state))),
                TextInput::make('nombre')
                    ->label('Nombre (una)')
                    ->placeholder('Ej. FARDO')
                    ->required()
                    ->maxLength(40),
                TextInput::make('plural')
                    ->label('Nombre (varias)')
                    ->placeholder('Ej. FARDOS')
                    ->required()
                    ->maxLength(40),
                Toggle::make('activo')
                    ->label('Activa (sale para escoger)')
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
                    ->weight('bold'),
                TextColumn::make('plural')
                    ->label('Varias'),
                TextColumn::make('presentaciones')
                    ->label('Presentaciones')
                    ->state(fn (UnidadMedida $record): int => Presentacion::query()->where('unidad_medida_id', $record->id)->count()),
                IconColumn::make('activo')
                    ->label('Activa')
                    ->boolean(),
            ])
            ->defaultSort('codigo')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUnidadesMedida::route('/'),
        ];
    }
}
