<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes;

use App\Filament\Resources\Clientes\Pages\ManageClientes;
use App\Filament\Resources\Clientes\Schemas\ClienteForm;
use App\Filament\Support\Acceso;
use App\Models\Cliente;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends \Filament\Resources\Resource<Cliente>
 */
class ClienteResource extends Resource
{
    protected static ?string $model = Cliente::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Cliente';

    protected static ?string $pluralModelLabel = 'Clientes';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Ventas';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components(ClienteForm::components());
    }

    /**
     * Cada quien ve los clientes de su bodega; administración ve todos.
     */
    public static function getEloquentQuery(): Builder
    {
        $usuario = Acceso::usuario();
        $query = Cliente::query()->with(['bodega', 'vendedor']);

        return $usuario === null ? $query->whereRaw('1 = 0') : $query->visiblePara($usuario);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Cliente $record): ?string => $record->direccion),
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('rtn')
                    ->label('RTN')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->placeholder('—'),
                TextColumn::make('vendedor.name')
                    ->label('Vendedor')
                    ->placeholder('—'),
                TextColumn::make('bodega.nombre')
                    ->label('Bodega')
                    ->toggleable(),
                IconColumn::make('activo')
                    ->boolean(),
            ])
            ->defaultSort('nombre')
            ->filters([
                SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre')
                    ->visible(fn (): bool => Acceso::veTodo()),
                SelectFilter::make('vendedor_id')
                    ->label('Vendedor')
                    ->relationship('vendedor', 'name')
                    ->searchable(),
                TernaryFilter::make('activo')
                    ->default(true),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageClientes::route('/'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['nombre', 'codigo', 'rtn'];
    }
}
