<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Actions;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Inventario\AjustarExistencia;
use App\Filament\Support\Acceso;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Producto;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * Conteo físico de un producto en una bodega: se escribe lo que hay (cajas
 * y sueltas) y la existencia queda en eso. Sirve para cargar el inventario
 * inicial y para dañados o faltantes. Queda en el kárdex; el costo no cambia.
 *
 * El encargado ajusta su bodega; administración escoge la bodega.
 */
final class AjustarExistenciaAction
{
    public static function make(): Action
    {
        return Action::make('ajustarExistencia')
            ->label('Ajustar existencia')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->color('gray')
            ->visible(fn (): bool => Acceso::puede('AjustarExistencia:Producto'))
            ->modalHeading(fn (Producto $record): string => "Existencia · {$record->descripcion}")
            ->modalDescription('Escriba lo que contó en la bodega. Queda en el kárdex como ajuste por conteo; el costo no cambia.')
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel('Guardar conteo')
            ->fillForm(fn (Producto $record): array => self::conteo($record, Acceso::veTodo() ? null : Acceso::usuario()?->bodega_id))
            ->schema(fn (Producto $record): array => [
                Select::make('bodega_id')
                    ->label('Bodega')
                    ->options(fn (): array => Bodega::query()->activas()->orderBy('nombre')->pluck('nombre', 'id')->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set) use ($record): void {
                        $conteo = self::conteo($record, is_numeric($state) ? (int) $state : null);
                        $set('cajas', $conteo['cajas']);
                        $set('unidades', $conteo['unidades']);
                    })
                    ->visible(Acceso::veTodo()),
                TextEntry::make('hay')
                    ->label('Hay ahora')
                    ->state(function (Get $get) use ($record): string {
                        $bodegaId = self::bodegaDe(Acceso::veTodo() ? $get('bodega_id') : Acceso::usuario()?->bodega_id);

                        return $bodegaId === null
                            ? 'Escoja la bodega'
                            : Existencia::enCajas(Existencia::en($bodegaId, $record->id), $record->unidades_por_caja);
                    }),
                Grid::make(['default' => 2])
                    ->schema([
                        TextInput::make('cajas')
                            ->label("Cajas de {$record->unidades_por_caja}")
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->visible($record->unidades_por_caja > 1),
                        TextInput::make('unidades')
                            ->label($record->unidades_por_caja > 1 ? 'Unidades sueltas' : 'Unidades')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->required(),
                    ]),
                TextInput::make('nota')
                    ->label('Motivo')
                    ->placeholder('Ej. Conteo inicial, producto dañado')
                    ->maxLength(255),
            ])
            ->action(function (array $data, Producto $record): void {
                $usuario = Acceso::usuario();

                if ($usuario === null) {
                    return;
                }

                $bodegaId = self::bodegaDe($usuario->veTodasLasBodegas() ? ($data['bodega_id'] ?? null) : $usuario->bodega_id);

                if ($bodegaId === null) {
                    Notification::make()->title('Su usuario no tiene bodega asignada')->danger()->send();

                    return;
                }

                $contado = self::entero($data['cajas'] ?? 0) * max(1, $record->unidades_por_caja) + self::entero($data['unidades'] ?? 0);
                $antes = Existencia::en($bodegaId, $record->id);

                try {
                    $movimiento = app(AjustarExistencia::class)->ejecutar($bodegaId, $record, $contado, $usuario, is_string($data['nota'] ?? null) ? $data['nota'] : null);
                } catch (ReglaNegocioException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                $porCaja = $record->unidades_por_caja;

                Notification::make()
                    ->title($movimiento === null ? 'No cambió: ya había eso' : 'Existencia ajustada')
                    ->body($movimiento === null
                        ? Existencia::enCajas($antes, $porCaja)
                        : Existencia::enCajas($antes, $porCaja).' → '.Existencia::enCajas($contado, $porCaja))
                    ->success()
                    ->send();
            });
    }

    /**
     * Lo que hay hoy en la bodega, en cajas y sueltas, para llenar el conteo.
     *
     * @return array{bodega_id: int|null, cajas: int, unidades: int, nota: null}
     */
    private static function conteo(Producto $producto, ?int $bodegaId): array
    {
        $hay = $bodegaId === null ? 0 : max(0, Existencia::en($bodegaId, $producto->id));
        $porCaja = max(1, $producto->unidades_por_caja);

        return [
            'bodega_id' => $bodegaId,
            'cajas'     => $porCaja > 1 ? intdiv($hay, $porCaja) : 0,
            'unidades'  => $porCaja > 1 ? $hay % $porCaja : $hay,
            'nota'      => null,
        ];
    }

    private static function bodegaDe(mixed $valor): ?int
    {
        return is_numeric($valor) && (int) $valor > 0 ? (int) $valor : null;
    }

    private static function entero(mixed $valor): int
    {
        return is_numeric($valor) ? max(0, (int) $valor) : 0;
    }
}
