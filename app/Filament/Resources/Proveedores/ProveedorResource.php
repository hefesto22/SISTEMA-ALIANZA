<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proveedores;

use App\Domain\Reportes\Periodo;
use App\Filament\Pages\ReporteCompras;
use App\Filament\Pages\ReporteVentas;
use App\Filament\Resources\Proveedores\Pages\CreateProveedor;
use App\Filament\Resources\Proveedores\Pages\EditProveedor;
use App\Filament\Resources\Proveedores\Pages\ListProveedores;
use App\Filament\Resources\Proveedores\RelationManagers\CategoriasRelationManager;
use App\Models\Proveedor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\GridDirection;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Livewire\Component;

class ProveedorResource extends Resource
{
    protected static ?string $model = Proveedor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Proveedor';

    protected static ?string $pluralModelLabel = 'Proveedores';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Precios';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Datos del proveedor')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('nombre')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('nombre_corto')
                            ->label('Nombre corto')
                            ->helperText('Se usa en las pestañas de precios. Ej: MERHONSA.')
                            ->maxLength(40),
                        TextInput::make('codigo')
                            ->label('Código')
                            ->helperText('El código que usan en el sistema actual (ej: 500008).')
                            ->maxLength(20)
                            ->unique(ignoreRecord: true),
                        TextInput::make('rtn')
                            ->label('RTN / RUC')
                            ->maxLength(20),
                        TextInput::make('razon_social')
                            ->label('Razón social')
                            ->maxLength(255),
                        TextInput::make('telefono')
                            ->label('Teléfono')
                            ->maxLength(60),
                        TextInput::make('direccion')
                            ->label('Dirección')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Toggle::make('activo')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')
                    ->searchable(['nombre', 'nombre_corto', 'razon_social'])
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Proveedor $record): ?string => $record->razon_social !== $record->nombre ? $record->razon_social : null),
                TextColumn::make('nombre_corto')
                    ->label('Corto')
                    ->badge()
                    ->color('primary')
                    ->placeholder('—'),
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('categorias_count')
                    ->label('Categorías')
                    ->counts('categorias')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('productos_count')
                    ->label('Productos')
                    ->counts('productos')
                    ->badge(),
                TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('activo')
                    ->boolean(),
            ])
            ->defaultSort('nombre')
            ->filters([
                TernaryFilter::make('activo'),
            ])
            ->recordActions([
                // Sus compras o sus ventas de una semana, quincena, mes o de las
                // fechas que se escojan: el reporte se abre ya con este proveedor.
                Action::make('reportes')
                    ->label('Reportes')
                    ->icon(Heroicon::OutlinedDocumentChartBar)
                    ->color('gray')
                    ->modalIcon(Heroicon::OutlinedDocumentChartBar)
                    ->modalHeading(fn (Proveedor $record): string => 'Reportes de '.$record->etiqueta())
                    ->modalDescription('Escoja el reporte y las fechas. Sale solo lo de sus productos y ahí mismo se baja en Excel o se imprime.')
                    ->modalWidth(Width::Large)
                    ->modalSubmitActionLabel('Ver reporte')
                    ->fillForm(fn (): array => [
                        'reporte' => array_key_first(self::reportes()),
                        'periodo' => 'mes',
                        'desde'   => now()->startOfMonth()->toDateString(),
                        'hasta'   => now()->toDateString(),
                    ])
                    ->schema([
                        ToggleButtons::make('reporte')
                            ->label('¿Qué reporte?')
                            ->options(fn (): array => self::reportes())
                            ->icons([
                                'compras' => Heroicon::OutlinedInboxArrowDown,
                                'ventas'  => Heroicon::OutlinedPresentationChartLine,
                            ])
                            ->grouped()
                            ->fullWidth()
                            ->live()
                            ->required()
                            ->helperText(fn (Get $get): string => $get('reporte') === 'ventas'
                                ? 'Lo que se vendió de sus productos: venta, ganancia, vendedores, productos y clientes.'
                                : 'Lo que se le compró: total con y sin ISV, sus facturas, productos y bodegas.'),
                        ToggleButtons::make('periodo')
                            ->label('¿De qué fechas?')
                            ->options(Periodo::SEMANA_QUINCENA_MES)
                            ->icons(['otro' => Heroicon::OutlinedCalendarDays])
                            ->columns(2)
                            ->gridDirection(GridDirection::Row)
                            ->fullWidth()
                            ->live()
                            ->required(),
                        Grid::make(2)
                            ->schema([
                                DatePicker::make('desde')
                                    ->label('Desde')
                                    ->maxDate(now())
                                    ->live()
                                    ->required(),
                                DatePicker::make('hasta')
                                    ->label('Hasta')
                                    ->minDate(fn (Get $get): ?string => Periodo::dia($get('desde')))
                                    ->maxDate(now())
                                    ->live()
                                    ->required(),
                            ])
                            ->visible(fn (Get $get): bool => $get('periodo') === 'otro'),
                        // Lo que va a salir, en palabras: "Del lunes 16 al jueves 19 de noviembre de 2026 · 4 días".
                        Callout::make(fn (Get $get): string => self::periodoDe($get)->textoLargo())
                            ->description(fn (Get $get): string => self::periodoDe($get)->dias().' '.(self::periodoDe($get)->dias() === 1 ? 'día' : 'días')
                                .(in_array($get('periodo'), ['quincena', 'quincena_pasada'], true) ? ' · '.Periodo::ayudaQuincenas() : ''))
                            ->icon(Heroicon::OutlinedCalendarDays)
                            ->info(),
                    ])
                    ->action(function (array $data, Proveedor $record, Component $livewire): void {
                        $periodo = is_string($data['periodo'] ?? null) ? $data['periodo'] : 'mes';
                        $desde = is_string($data['desde'] ?? null) ? $data['desde'] : null;
                        $hasta = is_string($data['hasta'] ?? null) ? $data['hasta'] : null;

                        $livewire->redirect(($data['reporte'] ?? null) === 'ventas'
                            ? ReporteVentas::delProveedor($record, $periodo, $desde, $hasta)
                            : ReporteCompras::delProveedor($record, $periodo, $desde, $hasta));
                    })
                    ->visible(fn (): bool => self::reportes() !== []),
                EditAction::make(),
            ]);
    }

    /**
     * Los reportes de un proveedor que puede ver el usuario.
     *
     * @return array<string, string>
     */
    private static function reportes(): array
    {
        return array_filter([
            'compras' => ReporteCompras::canAccess() ? 'Compras' : null,
            'ventas'  => ReporteVentas::canAccess() ? 'Ventas' : null,
        ]);
    }

    /**
     * Las fechas que se escogieron en el modal de reportes.
     */
    private static function periodoDe(Get $get): Periodo
    {
        $periodo = $get('periodo');

        return Periodo::de(is_string($periodo) ? $periodo : 'mes', $get('desde'), $get('hasta'));
    }

    public static function getRelations(): array
    {
        return [
            CategoriasRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListProveedores::route('/'),
            'create' => CreateProveedor::route('/create'),
            'edit'   => EditProveedor::route('/{record}/edit'),
        ];
    }
}
