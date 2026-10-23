<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Schemas;

use App\Models\Categoria;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

/**
 * Datos del producto. El costo/precio NO se editan aquí (salvo el inicial
 * al crearlo): se cambian con "Actualizar precio" para que quede historial.
 */
class ProductoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Producto')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('proveedor_id')
                            ->label('Proveedor')
                            ->relationship('proveedor', 'nombre')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('categoria_id', null)),
                        Select::make('categoria_id')
                            ->label('Categoría')
                            ->options(fn (Get $get): array => Categoria::query()
                                ->where('proveedor_id', $get('proveedor_id'))
                                ->orderBy('nombre')
                                ->get()
                                ->mapWithKeys(fn (Categoria $c): array => [
                                    $c->id => $c->nombre.' ('.rtrim(rtrim(number_format($c->margen * 100, 2), '0'), '.').'%)',
                                ])
                                ->all())
                            ->helperText('El % de utilidad viene de la categoría.')
                            ->required()
                            ->disabled(fn (Get $get): bool => blank($get('proveedor_id'))),
                        TextInput::make('codigo')
                            ->label('Código')
                            ->required()
                            ->maxLength(50)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('proveedor_id', $get('proveedor_id')),
                            ),
                        TextInput::make('unidades_por_caja')
                            ->label('Unidades por caja (UxC)')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                        TextInput::make('descripcion')
                            ->label('Descripción')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->dehydrateStateUsing(fn (?string $state): string => mb_strtoupper(trim((string) $state))),
                        Toggle::make('gravado')
                            ->label('Paga ISV (G)')
                            ->helperText('Apagado = exento (E).')
                            ->default(true)
                            ->live(),
                        TextInput::make('margen')
                            ->label('% de utilidad propio')
                            ->helperText('Déjelo vacío para usar el de la categoría.')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(99.99)
                            ->step(0.01)
                            ->suffix('%')
                            ->formatStateUsing(fn (mixed $state): ?float => is_numeric($state) ? round((float) $state * 100, 2) : null)
                            ->dehydrateStateUsing(fn (mixed $state): ?float => is_numeric($state) ? round((float) $state / 100, 4) : null),
                        Toggle::make('activo')
                            ->label('Activo (se puede vender)')
                            ->default(true),
                    ]),

                Section::make('Costo y precio de venta')
                    ->description('Tal como se manejan: con ISV si el producto paga ISV. Después se cambian con "Actualizar precio".')
                    ->columns(3)
                    ->columnSpanFull()
                    ->visibleOn('create')
                    ->schema([
                        ToggleButtons::make('costo_por')
                            ->label('Los montos son por')
                            ->options(['caja' => 'Caja', 'unidad' => 'Unidad'])
                            ->default('caja')
                            ->grouped()
                            ->live()
                            ->dehydrated(false),
                        TextInput::make('costo_inicial')
                            ->label(fn (Get $get): string => $get('gravado') ? 'Costo con ISV' : 'Costo')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('L.')
                            ->suffix(fn (Get $get): string => $get('costo_por') === 'caja' ? 'por caja' : 'por unidad')
                            ->required()
                            ->dehydrated(false),
                        TextInput::make('precio_inicial')
                            ->label(fn (Get $get): string => $get('gravado') ? 'Precio de venta con ISV' : 'Precio de venta')
                            ->numeric()
                            ->minValue(0.01)
                            ->prefix('L.')
                            ->suffix(fn (Get $get): string => $get('costo_por') === 'caja' ? 'por caja' : 'por unidad')
                            ->required()
                            ->dehydrated(false),
                    ]),
            ]);
    }
}
