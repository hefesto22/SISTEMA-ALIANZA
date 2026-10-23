<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Actions;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Precios\ActualizarPrecioProducto;
use App\Domain\Precios\CalculadoraPrecio;
use App\Enums\MotivoCambioPrecio;
use App\Models\Producto;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * "Actualizar precio": a la izquierda se mete el costo de la factura del
 * proveedor (por caja o por unidad, con descuento si hubo); a la derecha
 * una tarjeta muestra al instante el precio nuevo, la caja, la utilidad y
 * cuánto cambia contra el de hoy. Aplica a TODAS las bodegas y deja historial.
 *
 * La tarjeta es resources/views/filament/productos/precio-resultado.blade.php.
 */
final class ActualizarPrecioAction
{
    private const CAMBIO_SOSPECHOSO = 0.25;

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
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel('Guardar precio')
            ->fillForm(fn (Producto $record): array => [
                'costo_por' => $record->unidades_por_caja > 1 ? 'caja' : 'unidad',
                'costo'     => $record->unidades_por_caja > 1
                    ? round($record->costo_unidad * $record->unidades_por_caja, 4)
                    : $record->costo_unidad,
                'hay_descuento' => false,
                'cantidad'      => 1,
                'descuento'     => 0,
                'precio_manual' => false,
            ])
            ->schema([
                Grid::make(12)->schema([
                    Group::make([
                        ToggleButtons::make('costo_por')
                            ->label('Costo de la factura (sin ISV)')
                            ->options(['caja' => 'Por caja', 'unidad' => 'Por unidad'])
                            ->icons(['caja' => Heroicon::OutlinedArchiveBox, 'unidad' => Heroicon::OutlinedCube])
                            ->grouped()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (?string $state, ?string $old, Get $get, Set $set, Producto $record): void {
                                self::convertir($state, $old, $get, $set, $record);
                            }),
                        TextInput::make('costo')
                            ->hiddenLabel()
                            ->numeric()
                            ->minValue(0)
                            ->prefix('L.')
                            ->suffix(fn (Get $get): string => $get('costo_por') === 'caja' ? 'por caja' : 'por unidad')
                            ->required()
                            ->autofocus()
                            ->live(debounce: 400),

                        Toggle::make('hay_descuento')
                            ->label('Hubo descuento en esta compra')
                            ->helperText('Ej.: compró 10 cajas y el proveedor le rebajó L. 200 en total. El descuento se reparte en el costo de cada unidad.')
                            ->live(),
                        Grid::make(2)
                            ->visible(fn (Get $get): bool => (bool) $get('hay_descuento'))
                            ->schema([
                                TextInput::make('cantidad')
                                    ->label(fn (Get $get): string => $get('costo_por') === 'caja' ? 'Cajas compradas' : 'Unidades compradas')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->required()
                                    ->live(debounce: 400),
                                TextInput::make('descuento')
                                    ->label('Descuento total')
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix('L.')
                                    ->live(debounce: 400),
                            ]),

                        Toggle::make('precio_manual')
                            ->label('Poner yo el precio de venta')
                            ->helperText('Ej.: el sistema calcula L. 10.77 y usted lo quiere vender a L. 11.00. Se guarda la utilidad que realmente deja.')
                            ->live()
                            ->afterStateUpdated(function (bool $state, Get $get, Set $set, Producto $record): void {
                                $calculo = self::calcular($get, $record);

                                if ($state && blank($get('precio_unidad')) && is_array($calculo)) {
                                    $set('precio_unidad', round($calculo['precio'], 2));
                                }
                            }),
                        TextInput::make('precio_unidad')
                            ->label('Precio por unidad (sin ISV)')
                            ->numeric()
                            ->minValue(0.0001)
                            ->prefix('L.')
                            ->live(debounce: 400)
                            ->required(fn (Get $get): bool => (bool) $get('precio_manual'))
                            ->visible(fn (Get $get): bool => (bool) $get('precio_manual')),

                        TextInput::make('nota')
                            ->label('Nota (opcional)')
                            ->placeholder('Ej: Factura 375448')
                            ->maxLength(255),
                    ])->columnSpan(7),

                    View::make('filament.productos.precio-resultado')
                        ->viewData(fn (Get $get, Producto $record): array => self::resultado($get, $record))
                        ->columnSpan(5),
                ]),
            ])
            ->action(function (array $data, Producto $record): void {
                /** @var User|null $usuario */
                $usuario = Auth::user();
                $manual = (bool) ($data['precio_manual'] ?? false);
                $precioAnterior = $record->precio_unidad;

                if (! ($data['hay_descuento'] ?? false)) {
                    $data['cantidad'] = 1;
                    $data['descuento'] = 0;
                }

                try {
                    $costoUnidad = self::costoUnidad($data, $record);

                    $cambio = app(ActualizarPrecioProducto::class)->ejecutar(
                        $record,
                        $costoUnidad,
                        $manual ? MotivoCambioPrecio::Manual : MotivoCambioPrecio::Compra,
                        $usuario,
                        $manual ? (float) $data['precio_unidad'] : null,
                        filled($data['nota'] ?? null) ? (string) $data['nota'] : null,
                    );
                } catch (ReglaNegocioException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                if ($cambio === null) {
                    Notification::make()->title('El precio ya estaba así; no hubo cambios.')->info()->send();

                    return;
                }

                Notification::make()
                    ->title('Precio actualizado en todas las bodegas')
                    ->body($record->codigo.': '.lempiras($precioAnterior).' → '.lempiras($cambio->precio_nuevo).' por unidad (sin ISV)')
                    ->success()
                    ->send();
            });
    }

    /**
     * Al pasar de "por caja" a "por unidad" (o al revés) se convierte el número
     * que ya estaba escrito, para que el precio no salte: L. 444.5712 la caja
     * de 48 pasa a L. 9.2619 la unidad. Lo mismo con la cantidad comprada.
     */
    private static function convertir(?string $nuevo, ?string $anterior, Get $get, Set $set, Producto $producto): void
    {
        $uxc = max(1, $producto->unidades_por_caja);

        if ($uxc === 1 || $nuevo === $anterior || ! in_array($nuevo, ['caja', 'unidad'], true)) {
            return;
        }

        $aCaja = $nuevo === 'caja';
        $costo = $get('costo');

        if (is_numeric($costo)) {
            $set('costo', round($aCaja ? (float) $costo * $uxc : (float) $costo / $uxc, 4));
        }

        $cantidad = $get('cantidad');

        if (is_numeric($cantidad) && (int) $cantidad > 0) {
            $cantidad = (int) $cantidad;

            if (! $aCaja) {
                $set('cantidad', $cantidad * $uxc);
            } elseif ($cantidad % $uxc === 0) {
                $set('cantidad', intdiv($cantidad, $uxc));
            }
        }
    }

    /**
     * "MERHONSA · Código 740100167992 · caja de 48 · gravado · 14% de utilidad".
     */
    private static function ficha(Producto $producto): string
    {
        return collect([
            $producto->proveedor->etiqueta(),
            "Código {$producto->codigo}",
            $producto->unidades_por_caja > 1 ? "caja de {$producto->unidades_por_caja}" : null,
            $producto->gravado ? 'gravado' : 'exento',
            self::porcentaje($producto->margenAplicado()).' de utilidad',
        ])->filter()->implode(' · ');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function costoUnidad(array $data, Producto $producto): float
    {
        return CalculadoraPrecio::costoUnitario(
            costo: (float) ($data['costo'] ?? 0),
            porCaja: ($data['costo_por'] ?? 'unidad') === 'caja',
            unidadesPorCaja: max(1, $producto->unidades_por_caja),
            cantidad: max(1, (int) ($data['cantidad'] ?? 1)),
            descuento: (float) ($data['descuento'] ?? 0),
        );
    }

    /**
     * Lo que va quedando mientras se escribe, o qué falta / qué está mal.
     *
     * @return array{costo: float, precio: float, margen: float}|string
     */
    private static function calcular(Get $get, Producto $producto): array|string
    {
        if (! is_numeric($get('costo'))) {
            return 'Escriba el costo de la factura para ver el precio nuevo.';
        }

        $conDescuento = (bool) $get('hay_descuento');

        try {
            $costo = self::costoUnidad([
                'costo'     => $get('costo'),
                'costo_por' => $get('costo_por'),
                'cantidad'  => $conDescuento ? $get('cantidad') : 1,
                'descuento' => $conDescuento ? $get('descuento') : 0,
            ], $producto);

            $manual = $get('precio_unidad');

            if ($get('precio_manual') && is_numeric($manual) && (float) $manual > 0) {
                $precio = round((float) $manual, 4);

                return ['costo' => $costo, 'precio' => $precio, 'margen' => CalculadoraPrecio::margenReal($costo, $precio)];
            }

            $margen = $producto->margenAplicado();

            return ['costo' => $costo, 'precio' => CalculadoraPrecio::precioVenta($costo, $margen), 'margen' => $margen];
        } catch (ReglaNegocioException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Datos para la tarjeta de la derecha.
     *
     * @return array<string, mixed>
     */
    private static function resultado(Get $get, Producto $producto): array
    {
        $calculo = self::calcular($get, $producto);

        if (is_string($calculo)) {
            return ['mensaje' => $calculo];
        }

        $uxc = max(1, $producto->unidades_por_caja);
        $precioCaja = $calculo['precio'] * $uxc;
        $diferencia = round($calculo['precio'] - $producto->precio_unidad, 2);

        [$tono, $cambio] = match (true) {
            abs($diferencia) < 0.005 => ['igual', 'Queda igual que hoy'],
            $diferencia > 0          => ['sube', 'Sube '.lempiras($diferencia).self::variacion($diferencia, $producto)],
            default                  => ['baja', 'Baja '.lempiras(abs($diferencia)).self::variacion($diferencia, $producto)],
        };

        // Un cambio de más del 25% casi siempre es un error de dedo o de caja/unidad.
        $exagerado = $producto->precio_unidad > 0 && abs($diferencia) / $producto->precio_unidad > self::CAMBIO_SOSPECHOSO;

        return [
            'mensaje'    => null,
            'manual'     => (bool) $get('precio_manual'),
            'precio'     => lempiras($calculo['precio']),
            'precioIsv'  => $producto->gravado ? lempiras(CalculadoraPrecio::conIsv($calculo['precio'], true)) : null,
            'uxc'        => $uxc > 1 ? $uxc : null,
            'caja'       => lempiras($precioCaja),
            'cajaIsv'    => $producto->gravado ? lempiras(CalculadoraPrecio::conIsv($precioCaja, true)) : null,
            'costo'      => lempiras($calculo['costo'], 4),
            'costoAntes' => lempiras($producto->costo_unidad, 4),
            'margen'     => self::porcentaje($calculo['margen']),
            'hoy'        => lempiras($producto->precio_unidad),
            'tono'       => $exagerado ? 'alerta' : $tono,
            'cambio'     => $cambio,
            'aviso'      => $exagerado ? 'Es un cambio muy grande. Revise el costo y si es por caja o por unidad antes de guardar.' : null,
        ];
    }

    /**
     * " (+4.64%)" o " (−3.1%)" contra el precio de hoy.
     */
    private static function variacion(float $diferencia, Producto $producto): string
    {
        if ($producto->precio_unidad <= 0) {
            return '';
        }

        return ' ('.($diferencia > 0 ? '+' : '−').self::porcentaje(abs($diferencia) / $producto->precio_unidad).')';
    }

    /**
     * 0.14 → "14%", 0.1525 → "15.25%".
     */
    private static function porcentaje(float $fraccion): string
    {
        return rtrim(rtrim(number_format($fraccion * 100, 2), '0'), '.').'%';
    }
}
