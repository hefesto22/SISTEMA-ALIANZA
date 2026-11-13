<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Actions;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Precios\ActualizarPrecioProducto;
use App\Domain\Precios\CalculadoraPrecio;
use App\Enums\MotivoCambioPrecio;
use App\Filament\Schemas\Components\PreciosPresentaciones;
use App\Models\CambioPrecio;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * "Actualizar precio": aquí se pone el PRECIO DE VENTA de cada presentación.
 *
 * El costo no se escribe: cada presentación tiene el suyo, el promedio
 * ponderado que van dejando sus compras (o el de la última, según
 * Configuración); solo si está mal se corrige. Cada presentación se vende
 * "Con %" (el precio sale de SU costo con su % y se ajusta solo cuando ese
 * costo cambia: si compran barato, baja y la ganancia se mantiene; la caja
 * puede dejar menos que la unidad) o "Fijo" (lo pone la empresa; las
 * compras no lo mueven).
 *
 * La pantalla es App\Filament\Schemas\Components\PreciosPresentaciones (las
 * cuentas pasan en el navegador mientras se escribe). Aquí se revisa lo que
 * llega y se guarda con ActualizarPrecioProducto (sin ISV, historial, todas
 * las bodegas).
 */
final class ActualizarPrecioAction
{
    public static function make(): Action
    {
        return Action::make('actualizarPrecio')
            ->label('Actualizar precio')
            ->icon(Heroicon::OutlinedCurrencyDollar)
            ->color('warning')
            ->authorize('update')
            ->modalIcon(Heroicon::OutlinedCurrencyDollar)
            ->modalIconColor('warning')
            ->modalHeading(fn (Producto $record): string => $record->descripcion)
            ->modalDescription(fn (Producto $record): string => self::ficha($record))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Guardar precios')
            ->fillForm(fn (Producto $record): array => ['precios' => PreciosPresentaciones::estadoInicial($record)])
            ->schema([
                PreciosPresentaciones::make('precios'),
                TextInput::make('nota')
                    ->hiddenLabel()
                    ->placeholder('Nota para el historial (opcional). Ej: precio de la competencia')
                    ->maxLength(255),
            ])
            ->action(function (array $data, Producto $record, Action $action): void {
                /** @var User|null $usuario */
                $usuario = Auth::user();
                $record->loadMissing(['categoria', 'presentaciones.unidadMedida']);
                $precios = is_array($data['precios'] ?? null) ? $data['precios'] : [];
                $antes = collect(PreciosPresentaciones::presentaciones($record))->mapWithKeys(fn (Presentacion $presentacion): array => [$presentacion->id => $presentacion->precioConIsv()])->all();

                try {
                    $cambios = app(ActualizarPrecioProducto::class)->ejecutar(
                        $record,
                        MotivoCambioPrecio::Manual,
                        $usuario,
                        self::preciosAGuardar($precios, $record),
                        filled($data['nota'] ?? null) ? (string) $data['nota'] : null,
                        self::costosAGuardar($precios, $record),
                    );
                } catch (ReglaNegocioException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    $action->halt();

                    return;
                }

                if ($cambios === []) {
                    Notification::make()
                        ->title('Se guardó sin cambios de precio')
                        ->body('Los precios ya estaban así (si cambió algo entre "Con %" y "Fijo", ya quedó).')
                        ->info()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Precios actualizados en todas las bodegas')
                    ->body(collect($cambios)
                        ->filter(fn (CambioPrecio $cambio): bool => $cambio->presentacion !== null)
                        ->map(fn (CambioPrecio $cambio): string => $cambio->presentacion?->sigla().': '.lempiras($antes[$cambio->presentacion_id] ?? 0).' → '.lempiras(CalculadoraPrecio::conIsv($cambio->precio_nuevo, $record->gravado)))
                        ->implode(' · ').($record->gravado ? ' (con ISV).' : '.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Los costos corregidos (sin ISV, de la presentación completa): solo los
     * que de verdad cambiaron y solo si se abrió "Corregir costos".
     *
     * @param array<mixed> $precios el estado de PreciosPresentaciones
     *
     * @return array<int, float>
     */
    private static function costosAGuardar(array $precios, Producto $producto): array
    {
        if (! ($precios['corregir'] ?? false)) {
            return [];
        }

        $costos = is_array($precios['costos'] ?? null) ? $precios['costos'] : [];
        $corregidos = [];

        foreach ($producto->presentaciones as $presentacion) {
            if (! array_key_exists($presentacion->id, $costos)) {
                continue;
            }

            if (! is_numeric($costos[$presentacion->id]) || (float) $costos[$presentacion->id] < 0) {
                throw new ReglaNegocioException("Escriba el costo correcto de {$presentacion->sigla()}.");
            }

            $nuevo = round((float) $costos[$presentacion->id], 4);

            if (abs($nuevo - $presentacion->costo) >= 0.00005) {
                $corregidos[$presentacion->id] = $nuevo;
            }
        }

        return $corregidos;
    }

    /**
     * Por presentación: las "Con %" con su % (null si es el mismo del
     * producto) y sin precio (lo saca ActualizarPrecioProducto); las fijas
     * con su precio sin ISV. Lo que no cuadra, se avisa.
     *
     * @param array<mixed> $precios el estado de PreciosPresentaciones
     *
     * @return array<int, array{precio: float|null, fijo: bool, margen?: float|null}>
     */
    private static function preciosAGuardar(array $precios, Producto $producto): array
    {
        $guardar = [];
        $delProducto = $producto->margenAplicado();

        foreach (array_values(is_array($precios['filas'] ?? null) ? $precios['filas'] : []) as $fila) {
            if (! is_array($fila) || ! is_numeric($fila['id'] ?? null)) {
                continue;
            }

            $presentacion = $producto->presentaciones->first(fn (Presentacion $p): bool => $p->id === (int) $fila['id']);

            if (! $presentacion instanceof Presentacion) {
                continue;
            }

            if (($fila['modo'] ?? null) !== PreciosPresentaciones::FIJO) {
                if (! is_numeric($fila['margen'] ?? null)) {
                    throw new ReglaNegocioException("Escriba la utilidad de {$presentacion->sigla()}.");
                }

                $margen = round((float) $fila['margen'] / 100, 4);

                if ($margen < 0 || $margen >= 1) {
                    throw new ReglaNegocioException("La utilidad de {$presentacion->sigla()} debe ser de 0 % a 99.99 %.");
                }

                $guardar[$presentacion->id] = [
                    'precio' => null,
                    'fijo'   => false,
                    'margen' => abs($margen - $delProducto) < 0.00005 ? null : $margen,
                ];

                continue;
            }

            if (! is_numeric($fila['precio_base'] ?? null) || (float) $fila['precio_base'] <= 0) {
                throw new ReglaNegocioException("Escriba el precio de {$presentacion->sigla()}.");
            }

            $nuevo = round((float) $fila['precio_base'], 4);

            $guardar[$presentacion->id] = [
                'precio' => abs($nuevo - $presentacion->precio) < 0.00005 ? $presentacion->precio : $nuevo,
                'fijo'   => true,
            ];
        }

        return $guardar;
    }

    /**
     * "MERHONSA · Código 740100167992 · se cuenta en UN · gravado · utilidad del producto 14%".
     */
    private static function ficha(Producto $producto): string
    {
        return collect([
            $producto->proveedor->etiqueta(),
            "Código {$producto->codigo}",
            'se cuenta en '.$producto->presentacionBase()->sigla(),
            $producto->gravado ? 'gravado (paga ISV)' : 'exento',
            'utilidad del producto '.rtrim(rtrim(number_format($producto->margenAplicado() * 100, 2), '0'), '.').'%',
        ])->filter()->implode(' · ');
    }
}
