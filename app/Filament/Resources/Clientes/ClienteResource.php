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
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
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

    /**
     * En el celular cada cliente es una tarjeta (filament.clientes.tarjeta):
     * nombre, código, municipio, dirección y teléfono, con "Tomar pedido"
     * (abre la preventa con el cliente escogido), "Llamar" y lo demás en
     * "...". Al vendedor, tocar la tarjeta también abre la preventa. En
     * pantallas grandes es la tabla de siempre; la bodega solo la ve
     * administración. El vendedor solo ve los clientes activos.
     */
    public static function table(Table $table): Table
    {
        $esVendedor = Acceso::esVendedor();

        return $table
            ->stackedOnMobile()
            ->extraAttributes(['class' => 'tabla-celular'])
            ->modifyQueryUsing(fn (Builder $query): Builder => $esVendedor ? $query->where('activo', true) : $query)
            ->columns([
                ViewColumn::make('celular')
                    ->label('Cliente')
                    ->view('filament.clientes.tarjeta')
                    ->state(fn (Cliente $record): string => $record->nombre)
                    ->hiddenFrom('md'),
                TextColumn::make('nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Cliente $record): ?string => $record->direccion)
                    ->wrap()
                    ->visibleFrom('md'),
                TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->icon(Heroicon::OutlinedPhone)
                    ->url(fn (Cliente $record): ?string => self::llamar($record))
                    ->color('primary')
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('municipio.nombre')
                    ->label('Municipio')
                    ->formatStateUsing(fn (string $state, Cliente $record): string => $state.', '.$record->municipio?->departamento->nombre)
                    ->placeholder('Sin zona')
                    ->searchable()
                    ->visibleFrom('md'),
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('rtn')
                    ->label('RTN')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable()
                    ->visibleFrom('md'),
                TextColumn::make('bodega.nombre')
                    ->label('Bodega')
                    ->toggleable()
                    ->visible(fn (): bool => Acceso::veTodo())
                    ->visibleFrom('md'),
                IconColumn::make('activo')
                    ->boolean()
                    ->visibleFrom('md'),
            ])
            ->defaultSort('nombre')
            ->searchPlaceholder('Buscar cliente, código o RTN')
            ->recordUrl(fn (Cliente $record): ?string => $esVendedor && $record->activo && PedidoResource::canCreate()
                ? self::preventa($record)
                : null)
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
                    ->default(true)
                    ->visible(! $esVendedor),
            ])
            ->emptyStateDescription(fn (): ?string => Acceso::usuario()?->municipiosDeRuta() === []
                ? 'No tiene rutas asignadas. Pida a administración que lo agregue a una ruta en Administración → Rutas.'
                : null)
            ->recordActions([
                Action::make('preventa')
                    ->label('Tomar pedido')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->button()
                    ->url(fn (Cliente $record): string => self::preventa($record))
                    ->visible(fn (Cliente $record): bool => $record->activo && PedidoResource::canCreate())
                    ->extraAttributes(['class' => 'accion-principal']),
                Action::make('llamar')
                    ->label('Llamar')
                    ->icon(Heroicon::OutlinedPhone)
                    ->button()
                    ->outlined()
                    ->color('gray')
                    ->url(fn (Cliente $record): ?string => self::llamar($record))
                    ->visible(fn (Cliente $record): bool => self::llamar($record) !== null)
                    ->extraAttributes(['class' => 'solo-celular']),
                ActionGroup::make([
                    Action::make('pedidos')
                        ->label('Ver sus pedidos')
                        ->icon(Heroicon::OutlinedClipboardDocumentList)
                        ->url(fn (Cliente $record): string => PedidoResource::getUrl('index', [
                            'tab'     => 'todos',
                            'filters' => ['cliente_id' => ['value' => $record->id]],
                        ])),
                    EditAction::make()
                        ->modalDescription(fn (Cliente $record): ?string => $record->codigo),
                ])
                    ->icon(Heroicon::EllipsisVertical)
                    ->color('gray')
                    ->tooltip('Más'),
            ]);
    }

    /**
     * La preventa con este cliente ya escogido.
     */
    public static function preventa(Cliente $cliente): string
    {
        return PedidoResource::getUrl('create', ['cliente' => $cliente->id]);
    }

    /**
     * "tel:99887766" para llamar con un toque; null sin teléfono.
     */
    private static function llamar(Cliente $cliente): ?string
    {
        $numero = preg_replace('/\D+/', '', (string) $cliente->telefono);

        return $numero === null || $numero === '' ? null : 'tel:'.$numero;
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
