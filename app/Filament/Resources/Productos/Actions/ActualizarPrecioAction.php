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
 * "Actualizar precio": a la izquierda se mete el costo neto de la factura
 * (por caja o por unidad); a la derecha una tarjeta muestra al instante el
 * precio de venta, la caja y cuánto cambia contra el de hoy. Aplica a TODAS
 * las bodegas y deja historial.
 *
 * La utilidad es DESPUÉS del impuesto: si el producto es gravado, el costo
 * neto se paga con 15% de ISV, y el precio de venta (con ISV) deja el % de
 * utilidad sobre ese costo con ISV:
 *
 *   9.2619 neto → 10.6512 con ISV → ÷ (1 − 14%) → L. 12.39 precio de venta
 *
 * En la base se sigue guardando el precio sin ISV (10.7697); el pedido le
 * suma el ISV y da lo mismo: L. 12.39.
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
                'precio_manual' => false,
            ])
            ->schema([
                Grid::make(12)->schema([
                    Group::make([
                        ToggleButtons::make('costo_por')
                            ->label('Costo neto de la factura (sin ISV)')
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

                        Toggle::make('precio_manual')
                            ->label('Poner yo el precio de venta')
                            ->helperText('Para dejar un precio cerrado, por ejemplo L. 12.50 en vez de L. 12.39.')
                            ->live()
                            ->afterStateUpdated(function (bool $state, Get $get, Set $set, Producto $record): void {
                                $calculo = self::calcular($get, $record);

                                if ($state && blank($get('precio_venta')) && is_array($calculo)) {
                                    $set('precio_venta', round($calculo['venta'], 2));
                                }
                            }),
                        TextInput::make('precio_venta')
                            ->label(fn (Producto $record): string => $record->gravado ? 'Precio de venta por unidad (con ISV)' : 'Precio de venta por unidad')
                            ->numeric()
                            ->minValue(0.01)
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
                $ventaAnterior = $record->precioUnidadConIsv();

                try {
                    $costoUnidad = self::costoUnidad($data, $record);

                    $cambio = app(ActualizarPrecioProducto::class)->ejecutar(
                        $record,
                        $costoUnidad,
                        $manual ? MotivoCambioPrecio::Manual : MotivoCambioPrecio::Compra,
                        $usuario,
                        $manual ? self::sinIsv((float) $data['precio_venta'], $record) : null,
                        filled($data['nota'] ?? null) ? (string) $data['nota'] : null,
                    );
                } catch (ReglaNegocioException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                if ($cambio === null) {
                    Notification::make()
                        ->title('No se guardó nada')
                        ->body('El costo y el precio de venta ya estaban así.')
                        ->info()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Precio actualizado en todas las bodegas')
                    ->body($record->codigo.': '.lempiras($ventaAnterior).' → '.lempiras(CalculadoraPrecio::conIsv($cambio->precio_nuevo, $record->gravado)).' por unidad'.($record->gravado ? ' (con ISV)' : ''))
                    ->success()
                    ->send();
            });
    }

    /**
     * Al pasar de "por caja" a "por unidad" (o al revés) se convierte el número
     * que ya estaba escrito, para que el precio no salte: L. 444.5712 la caja
     * de 48 pasa a L. 9.2619 la unidad.
     */
    private static function convertir(?string $nuevo, ?string $anterior, Get $get, Set $set, Producto $producto): void
    {
        $uxc = max(1, $producto->unidades_por_caja);

        if ($uxc === 1 || $nuevo === $anterior || ! in_array($nuevo, ['caja', 'unidad'], true)) {
            return;
        }

        $costo = $get('costo');

        if (is_numeric($costo)) {
            $set('costo', round($nuevo === 'caja' ? (float) $costo * $uxc : (float) $costo / $uxc, 4));
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
            $producto->gravado ? 'gravado (paga ISV)' : 'exento',
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
        );
    }

    /**
     * Lo que va quedando mientras se escribe, o qué falta / qué está mal.
     *
     *  costo        neto por unidad (lo que dice la factura sin ISV)
     *  costoPagado  lo que de verdad se paga por unidad (con ISV si es gravado)
     *  precio       precio sin ISV, que es lo que se guarda
     *  venta        precio de venta al cliente (con ISV si es gravado)
     *  margen       utilidad de venta sobre costoPagado (después del impuesto)
     *
     * @return array{costo: float, costoPagado: float, precio: float, venta: float, margen: float}|string
     */
    private static function calcular(Get $get, Producto $producto): array|string
    {
        if (! is_numeric($get('costo'))) {
            return 'Escriba el costo neto para ver el precio de venta.';
        }

        try {
            $costo = self::costoUnidad([
                'costo'     => $get('costo'),
                'costo_por' => $get('costo_por'),
            ], $producto);

            $costoPagado = CalculadoraPrecio::conIsv($costo, $producto->gravado);
            $manual = $get('precio_venta');

            if ($get('precio_manual') && is_numeric($manual) && (float) $manual > 0) {
                $venta = round((float) $manual, 2);

                return [
                    'costo'       => $costo,
                    'costoPagado' => $costoPagado,
                    'precio'      => self::sinIsv($venta, $producto),
                    'venta'       => $venta,
                    'margen'      => CalculadoraPrecio::margenReal($costoPagado, $venta),
                ];
            }

            $margen = $producto->margenAplicado();
            $precio = CalculadoraPrecio::precioVenta($costo, $margen);

            return [
                'costo'       => $costo,
                'costoPagado' => $costoPagado,
                'precio'      => $precio,
                'venta'       => CalculadoraPrecio::conIsv($precio, $producto->gravado),
                'margen'      => $margen,
            ];
        } catch (ReglaNegocioException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Precio de venta (con ISV) → precio sin ISV, que es lo que se guarda.
     */
    private static function sinIsv(float $venta, Producto $producto): float
    {
        return $producto->gravado
            ? round($venta / (1 + CalculadoraPrecio::tasaIsv()), 4)
            : round($venta, 4);
    }

    /**
     * Datos para la tarjeta de la derecha. Todo en precio de venta (con ISV si es gravado).
     *
     * @return array<string, mixed>
     */
    private static function resultado(Get $get, Producto $producto): array
    {
        $calculo = self::calcular($get, $producto);

        if (is_string($calculo)) {
            return ['mensaje' => $calculo];
        }

        $gravado = $producto->gravado;
        $uxc = max(1, $producto->unidades_por_caja);
        $hoy = $producto->precioUnidadConIsv();
        $diferencia = round($calculo['venta'] - $hoy, 2);

        [$tono, $cambio] = match (true) {
            abs($diferencia) < 0.005 => ['igual', 'Queda igual que hoy'],
            $diferencia > 0          => ['sube', 'Sube '.lempiras($diferencia).self::variacion($diferencia, $hoy)],
            default                  => ['baja', 'Baja '.lempiras(abs($diferencia)).self::variacion($diferencia, $hoy)],
        };

        // Un cambio de más del 25% casi siempre es un error de dedo o de caja/unidad.
        $exagerado = $hoy > 0 && abs($diferencia) / $hoy > self::CAMBIO_SOSPECHOSO;

        return [
            'mensaje'   => null,
            'manual'    => (bool) $get('precio_manual'),
            'gravado'   => $gravado,
            'venta'     => lempiras($calculo['venta']),
            'sinIsv'    => lempiras($calculo['precio']),
            'uxc'       => $uxc > 1 ? $uxc : null,
            'caja'      => lempiras(CalculadoraPrecio::conIsv($calculo['precio'] * $uxc, $gravado)),
            'cajaSin'   => lempiras($calculo['precio'] * $uxc),
            'costo'     => lempiras($calculo['costoPagado'], 4),
            'costoNeto' => lempiras($calculo['costo'], 4),
            'margen'    => self::porcentaje($calculo['margen']),
            'ganancia'  => lempiras($calculo['venta'] - $calculo['costoPagado']),
            'hoy'       => lempiras($hoy),
            'tono'      => $exagerado ? 'alerta' : $tono,
            'cambio'    => $cambio,
            'aviso'     => $exagerado ? 'Es un cambio muy grande. Revise el costo y si es por caja o por unidad antes de guardar.' : null,
        ];
    }

    /**
     * " (+4.64%)" o " (−3.1%)" contra el precio de hoy.
     */
    private static function variacion(float $diferencia, float $hoy): string
    {
        if ($hoy <= 0) {
            return '';
        }

        return ' ('.($diferencia > 0 ? '+' : '−').self::porcentaje(abs($diferencia) / $hoy).')';
    }

    /**
     * 0.14 → "14%", 0.1525 → "15.25%".
     */
    private static function porcentaje(float $fraccion): string
    {
        return rtrim(rtrim(number_format($fraccion * 100, 2), '0'), '.').'%';
    }
}
