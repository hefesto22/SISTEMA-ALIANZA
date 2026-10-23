<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proveedores\RelationManagers;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Precios\CambiarMargenCategoria;
use App\Models\Categoria;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Categorías del proveedor con su % de utilidad.
 *
 * Cambiar el margen recalcula el precio de todos los productos de la
 * categoría (los que no tienen margen propio) y lo deja en el historial.
 */
class CategoriasRelationManager extends RelationManager
{
    protected static string $relationship = 'categorias';

    protected static ?string $title = 'Categorías y % de utilidad';

    protected static ?string $modelLabel = 'categoría';

    protected static ?string $pluralModelLabel = 'categorías';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nombre')
                    ->required()
                    ->maxLength(255),
                TextInput::make('margen')
                    ->label('% de utilidad')
                    ->helperText('Margen sobre la venta: precio = costo ÷ (1 − %). Ej: 16')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(99.99)
                    ->step(0.01)
                    ->suffix('%')
                    ->required()
                    ->formatStateUsing(fn (mixed $state): ?float => is_numeric($state) ? round((float) $state * 100, 2) : null)
                    ->dehydrateStateUsing(fn (mixed $state): float => round((float) $state / 100, 4)),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('nombre')
            ->columns([
                TextColumn::make('nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('margen')
                    ->label('% de utilidad')
                    ->formatStateUsing(fn (float $state): string => rtrim(rtrim(number_format($state * 100, 2), '0'), '.').' %')
                    ->badge()
                    ->color('warning')
                    ->sortable(),
                TextColumn::make('productos_count')
                    ->label('Productos')
                    ->counts('productos'),
            ])
            ->defaultSort('nombre')
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalDescription('Si cambia el % de utilidad, se recalcula el precio de todos los productos de esta categoría y queda en el historial.')
                    ->using(function (Model $record, array $data): Model {
                        /** @var Categoria $record */
                        $record->nombre = (string) $data['nombre'];
                        $record->save();

                        $margenNuevo = (float) $data['margen'];

                        if (abs($margenNuevo - $record->margen) < 0.00005) {
                            return $record;
                        }

                        /** @var User|null $usuario */
                        $usuario = Auth::user();

                        try {
                            $cambiados = app(CambiarMargenCategoria::class)->ejecutar($record, $margenNuevo, $usuario);
                        } catch (ReglaNegocioException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return $record;
                        }

                        Notification::make()
                            ->title('Margen actualizado')
                            ->body("Se recalculó el precio de {$cambiados} producto(s).")
                            ->success()
                            ->send();

                        return $record;
                    }),
                DeleteAction::make()
                    ->visible(fn (Categoria $record): bool => ! $record->productos()->exists()),
            ]);
    }
}
