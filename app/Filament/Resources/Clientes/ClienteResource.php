<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes;

use App\Filament\Resources\Clientes\Pages\ManageClientes;
use App\Filament\Resources\Clientes\Schemas\ClienteForm;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Filament\Support\Acceso;
use App\Models\Cliente;
use App\Models\Departamento;
use App\Models\Municipio;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
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
     * Administración ve todos; el vendedor, los de sus rutas; bodega, los de su bodega.
     */
    public static function getEloquentQuery(): Builder
    {
        $usuario = Acceso::usuario();
        $query = Cliente::query()->with(['bodega', 'municipio.departamento']);

        return $usuario === null ? $query->whereRaw('1 = 0') : $query->visiblePara($usuario);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Cliente $record): ?string => $record->direccion),
                TextColumn::make('rtn')
                    ->label('RTN')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->placeholder('—'),
                TextColumn::make('municipio.nombre')
                    ->label('Municipio')
                    ->description(fn (Cliente $record): ?string => $record->municipio?->departamento->nombre)
                    ->placeholder('Sin zona')
                    ->searchable(),
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
                SelectFilter::make('departamento')
                    ->label('Departamento')
                    ->options(fn (): array => Departamento::opciones())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas('municipio', fn (Builder $q): Builder => $q->where('departamento_id', $data['value']))),
                SelectFilter::make('municipio_id')
                    ->label('Municipio')
                    ->options(fn (): array => self::municipios())
                    ->searchable(),
                Filter::make('sin_zona')
                    ->label('Sin departamento ni municipio')
                    ->query(fn (Builder $query): Builder => $query->whereNull('municipio_id'))
                    ->toggle()
                    ->visible(fn (): bool => Acceso::veTodo()),
                TernaryFilter::make('activo')
                    ->default(true),
            ])
            ->emptyStateDescription(fn (): ?string => Acceso::usuario()?->municipiosDeRuta() === []
                ? 'No tiene rutas asignadas. Pida a administración que lo agregue a una ruta en Administración → Rutas.'
                : null)
            ->recordActions([
                Action::make('pedidos')
                    ->label('Pedidos')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->color('gray')
                    ->url(fn (Cliente $record): string => PedidoResource::getUrl('index', [
                        'tab'     => 'todos',
                        'filters' => ['cliente_id' => ['value' => $record->id]],
                    ])),
                EditAction::make()
                    ->modalDescription(fn (Cliente $record): ?string => $record->codigo),
            ]);
    }

    /**
     * Municipios para el filtro ("San José · Copán"); al vendedor, solo los de sus rutas.
     *
     * @return array<int, string>
     */
    private static function municipios(): array
    {
        return Municipio::opcionesConDepartamento(Acceso::usuario()?->municipiosDeRuta());
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
