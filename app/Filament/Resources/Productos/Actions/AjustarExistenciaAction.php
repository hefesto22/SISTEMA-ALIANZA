<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Actions;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Inventario\AjustarExistencia;
use App\Filament\Support\Acceso;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Presentacion;
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
use Illuminate\Support\Facades\DB;

/**
 * Conteo físico de un producto en una bodega: se escribe lo que hay de
 * cada presentación (cuántas CJ48, cuántas CJ32, cuántas UN sueltas) y la
 * existencia de cada una queda en eso. Sirve para cargar el inventario
 * inicial y para dañados o faltantes. Queda en el kárdex; los costos no
 * cambian.
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
            ->modalDescription('Escriba lo que contó en la bodega de cada presentación (las cajas cerradas y las unidades sueltas aparte). Queda en el kárdex como ajuste por conteo; el costo no cambia.')
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Guardar conteo')
            ->fillForm(fn (Producto $record): array => self::conteo($record, Acceso::veTodo() ? null : Acceso::usuario()?->bodega_id))
            ->schema(fn (Producto $record): array => [
                Select::make('bodega_id')
                    ->label('Bodega')
                    ->options(fn (): array => Bodega::query()->activas()->orderBy('nombre')->pluck('nombre', 'id')->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set) use ($record): void {
                        $set('conteo', self::conteo($record, is_numeric($state) ? (int) $state : null)['conteo']);
                    })
                    ->visible(Acceso::veTodo()),
                TextEntry::make('hay')
                    ->label('Hay ahora')
                    ->state(function (Get $get) use ($record): string {
                        $bodegaId = self::bodegaDe(Acceso::veTodo() ? $get('bodega_id') : Acceso::usuario()?->bodega_id);

                        return $bodegaId === null
                            ? 'Escoja la bodega'
                            : $record->existenciaPorPresentacion(Existencia::porPresentacion($bodegaId, $record->id));
                    }),
                Grid::make(['default' => 2])
                    ->schema(array_map(
                        fn (Presentacion $presentacion): TextInput => TextInput::make("conteo.{$presentacion->id}")
                            ->label(self::etiqueta($presentacion, $record->presentaciones->count() > 1))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->required(),
                        self::presentaciones($record),
                    )),
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

                $contado = is_array($data['conteo'] ?? null) ? $data['conteo'] : [];
                $nota = is_string($data['nota'] ?? null) ? $data['nota'] : null;
                $antes = Existencia::porPresentacion($bodegaId, $record->id);
                $cambios = 0;

                try {
                    DB::transaction(function () use ($record, $contado, $bodegaId, $usuario, $nota, &$cambios): void {
                        foreach (self::presentaciones($record) as $presentacion) {
                            if (! array_key_exists($presentacion->id, $contado)) {
                                continue;
                            }

                            if (app(AjustarExistencia::class)->ejecutar($bodegaId, $presentacion, self::entero($contado[$presentacion->id]), $usuario, $nota) !== null) {
                                $cambios++;
                            }
                        }
                    });
                } catch (ReglaNegocioException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                $despues = $record->existenciaPorPresentacion(Existencia::porPresentacion($bodegaId, $record->id));

                Notification::make()
                    ->title($cambios === 0 ? 'No cambió: ya había eso' : 'Existencia ajustada')
                    ->body($cambios === 0 ? $despues : $record->existenciaPorPresentacion($antes).' → '.$despues)
                    ->success()
                    ->send();
            });
    }

    /**
     * Las que se cuentan: las que se venden y las que todavía tienen algo en
     * alguna bodega (para poder dejarlas en 0), la más grande primero.
     *
     * @return list<Presentacion>
     */
    private static function presentaciones(Producto $producto): array
    {
        $producto->loadMissing('presentaciones.unidadMedida');
        $conExistencia = Existencia::query()->where('producto_id', $producto->id)->where('cantidad', '!=', 0)->pluck('presentacion_id')->all();

        return array_values($producto->presentaciones
            ->filter(fn (Presentacion $presentacion): bool => $presentacion->activo || in_array($presentacion->id, $conExistencia, true))
            ->sortByDesc('contenido')
            ->all());
    }

    /**
     * "Cajas de 48 (CJ48)", "Unidades sueltas (UN)".
     */
    private static function etiqueta(Presentacion $presentacion, bool $varias): string
    {
        return mb_ucfirst($presentacion->palabra(2)).($presentacion->es_base && $varias ? ' sueltas' : '')." ({$presentacion->sigla()})";
    }

    /**
     * Lo que hay hoy de cada presentación en la bodega, para llenar el conteo.
     *
     * @return array{bodega_id: int|null, conteo: array<int, int>, nota: null}
     */
    private static function conteo(Producto $producto, ?int $bodegaId): array
    {
        $hay = $bodegaId === null ? [] : Existencia::porPresentacion($bodegaId, $producto->id);
        $conteo = [];

        foreach (self::presentaciones($producto) as $presentacion) {
            $conteo[$presentacion->id] = max(0, $hay[$presentacion->id] ?? 0);
        }

        return [
            'bodega_id' => $bodegaId,
            'conteo'    => $conteo,
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
