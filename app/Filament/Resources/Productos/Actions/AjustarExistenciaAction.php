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
 * Conteo físico de un producto en una bodega: se escribe lo que hay (en su
 * presentación más grande —cajas, sacos…— y sueltas) y la existencia queda
 * en eso (en unidades base). Sirve para cargar el inventario
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
                            : $record->existenciaEnTexto(Existencia::en($bodegaId, $record->id));
                    }),
                Grid::make(['default' => 2])
                    ->schema([
                        TextInput::make('cajas')
                            ->label(mb_ucfirst($record->presentacionMayor()?->palabra(2) ?? 'cajas'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->visible(self::porCaja($record) > 1),
                        TextInput::make('unidades')
                            ->label(mb_ucfirst($record->presentacionBase()->palabra(2)).(self::porCaja($record) > 1 ? ' sueltas' : ''))
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

                $contado = self::entero($data['cajas'] ?? 0) * self::porCaja($record) + self::entero($data['unidades'] ?? 0);
                $antes = Existencia::en($bodegaId, $record->id);

                try {
                    $movimiento = app(AjustarExistencia::class)->ejecutar($bodegaId, $record, $contado, $usuario, is_string($data['nota'] ?? null) ? $data['nota'] : null);
                } catch (ReglaNegocioException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title($movimiento === null ? 'No cambió: ya había eso' : 'Existencia ajustada')
                    ->body($movimiento === null
                        ? $record->existenciaEnTexto($antes)
                        : $record->existenciaEnTexto($antes).' → '.$record->existenciaEnTexto($contado))
                    ->success()
                    ->send();
            });
    }

    /**
     * Cuántas unidades base trae la presentación más grande (1 si solo tiene la base).
     */
    private static function porCaja(Producto $producto): int
    {
        return max(1, $producto->presentacionMayor()?->contenido ?? 1);
    }

    /**
     * Lo que hay hoy en la bodega, en su presentación más grande y sueltas, para llenar el conteo.
     *
     * @return array{bodega_id: int|null, cajas: int, unidades: int, nota: null}
     */
    private static function conteo(Producto $producto, ?int $bodegaId): array
    {
        $hay = $bodegaId === null ? 0 : max(0, Existencia::en($bodegaId, $producto->id));
        $porCaja = self::porCaja($producto);

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
