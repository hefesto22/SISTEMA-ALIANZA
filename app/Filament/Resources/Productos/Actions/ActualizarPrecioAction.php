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
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
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
 * "Actualizar precio": se escriben el COSTO y el PRECIO DE VENTA tal como se
 * manejan en la calle (con ISV si el producto es gravado), por caja o por
 * unidad. El sistema saca todo lo demás: la utilidad (después del impuesto),
 * la ganancia, el precio de la caja y de la unidad, y lo que se guarda sin ISV.
 * Aplica a TODAS las bodegas y deja historial.
 *
 * Por dentro se lleva cada monto POR UNIDAD Y SIN ISV (costo_base, precio_base)
 * con 6 decimales; lo que se ve en pantalla sale de ahí según el modo. Así, al
 * cambiar entre caja y unidad nada se descuadra: caja de 48 a L. 594.49 ⇄
 * unidad a L. 12.39, ida y vuelta, sin perder centavos.
 */
final class ActualizarPrecioAction
{
    private const CAMBIO_SOSPECHOSO = 0.25;

    /** Montos un poco más grandes y con números alineados. */
    private const ESTILO_MONTO = 'font-size: 1.05rem; font-weight: 600; font-variant-numeric: tabular-nums;';

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
            ->fillForm(function (Producto $record): array {
                $factor = max(1, $record->unidades_por_caja);

                return [
                    'costo_por'    => $factor > 1 ? 'caja' : 'unidad',
                    'costo_base'   => $record->costo_unidad,
                    'precio_base'  => $record->precio_unidad,
                    'costo'        => CalculadoraPrecio::aPantalla($record->costo_unidad, $record->gravado, $factor),
                    'precio_venta' => CalculadoraPrecio::aPantalla($record->precio_unidad, $record->gravado, $factor),
                ];
            })
            ->schema([
                Grid::make(12)->schema([
                    Group::make([
                        Hidden::make('costo_base'),
                        Hidden::make('precio_base'),
                        ToggleButtons::make('costo_por')
                            ->label('Los montos son por')
                            ->options(fn (Producto $record): array => [
                                'caja'   => "Caja de {$record->unidades_por_caja}",
                                'unidad' => 'Unidad',
                            ])
                            ->icons(['caja' => Heroicon::OutlinedArchiveBox, 'unidad' => Heroicon::OutlinedCube])
                            ->grouped()
                            ->required()
                            ->live()
                            ->visible(fn (Producto $record): bool => $record->unidades_por_caja > 1)
                            ->afterStateUpdated(function (?string $state, ?string $old, Get $get, Set $set, Producto $record): void {
                                self::convertir($state, $old, $get, $set, $record);
                            }),
                        TextInput::make('costo')
                            ->label(fn (Get $get, Producto $record): string => 'Costo '.self::por($get).($record->gravado ? ' · con ISV' : ''))
                            ->numeric()
                            ->minValue(0)
                            ->prefix('L.')
                            ->required()
                            ->autofocus()
                            ->extraInputAttributes(['style' => self::ESTILO_MONTO])
                            ->live(debounce: 400)
                            ->afterStateUpdated(function (Get $get, Set $set, Producto $record): void {
                                self::alCambiarCosto($get, $set, $record);
                            }),
                        TextInput::make('precio_venta')
                            ->label(fn (Get $get, Producto $record): string => 'Precio de venta '.self::por($get).($record->gravado ? ' · con ISV' : ''))
                            ->numeric()
                            ->minValue(0.01)
                            ->prefix('L.')
                            ->required()
                            ->extraInputAttributes(['style' => self::ESTILO_MONTO])
                            ->live(debounce: 400)
                            ->afterStateUpdated(function (Get $get, Set $set, Producto $record): void {
                                $venta = $get('precio_venta');

                                if (is_numeric($venta)) {
                                    $set('precio_base', self::base((float) $venta, $record, self::factor($get('costo_por'), $record)));
                                }
                            })
                            ->helperText(fn (Producto $record): string => 'Se calcula solo con el '.self::porcentaje($record->margenAplicado()).' de utilidad al cambiar el costo. Puede cambiarlo a mano.')
                            ->hintAction(fn (Producto $record): Action => self::usarSugerido($record)),
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
                $ventaAnterior = $record->precioUnidadConIsv();

                try {
                    $valores = self::aGuardar($data, $record);

                    $cambio = app(ActualizarPrecioProducto::class)->ejecutar(
                        $record,
                        $valores['costo'],
                        $valores['costo'] !== $record->costo_unidad ? MotivoCambioPrecio::Compra : MotivoCambioPrecio::Manual,
                        $usuario,
                        $valores['precio'],
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
     * Lo que se guarda: los montos por unidad y sin ISV, a 4 decimales.
     * Si quedaron igual que lo guardado se deja el guardado tal cual.
     *
     * @param array<string, mixed> $data costo, precio_venta (en pantalla) y costo_base, precio_base
     *
     * @return array{costo: float, precio: float}
     */
    private static function aGuardar(array $data, Producto $producto): array
    {
        if (! is_numeric($data['costo'] ?? null) || ! is_numeric($data['costo_base'] ?? null) || (float) $data['costo_base'] < 0) {
            throw new ReglaNegocioException('Escriba el costo.');
        }

        if (! is_numeric($data['precio_venta'] ?? null) || ! is_numeric($data['precio_base'] ?? null) || (float) $data['precio_base'] <= 0) {
            throw new ReglaNegocioException('Escriba el precio de venta.');
        }

        return [
            'costo'  => self::oGuardado(round((float) $data['costo_base'], 4), $producto->costo_unidad),
            'precio' => self::oGuardado(round((float) $data['precio_base'], 4), $producto->precio_unidad),
        ];
    }

    private static function oGuardado(float $nuevo, float $guardado): float
    {
        return abs($nuevo - $guardado) < 0.00005 ? $guardado : $nuevo;
    }

    /**
     * Monto en pantalla (con ISV si es gravado, por caja o por unidad) → por unidad sin ISV, 6 decimales.
     */
    private static function base(float $monto, Producto $producto, int $factor): float
    {
        $isv = $producto->gravado ? 1 + CalculadoraPrecio::tasaIsv() : 1.0;

        return round($monto / $isv / max(1, $factor), 6);
    }

    /**
     * Se escribió un costo: se guarda su base y el precio de venta se recalcula
     * con el % de utilidad (después se puede cambiar a mano).
     */
    private static function alCambiarCosto(Get $get, Set $set, Producto $producto): void
    {
        $costo = $get('costo');

        if (! is_numeric($costo)) {
            return;
        }

        $factor = self::factor($get('costo_por'), $producto);
        $costoBase = self::base((float) $costo, $producto, $factor);
        $set('costo_base', $costoBase);

        $sugerido = self::precioSugerido($costoBase, $producto);

        if ($sugerido !== null) {
            $set('precio_base', $sugerido);
            $set('precio_venta', CalculadoraPrecio::aPantalla($sugerido, $producto->gravado, $factor));
        }
    }

    /**
     * Al pasar de caja a unidad (o al revés) los dos montos se vuelven a mostrar
     * desde su base por unidad: L. 594.49 la caja de 48 ⇄ L. 12.39 la unidad.
     */
    private static function convertir(?string $nuevo, ?string $anterior, Get $get, Set $set, Producto $producto): void
    {
        if ($producto->unidades_por_caja <= 1 || $nuevo === $anterior) {
            return;
        }

        $factor = self::factor($nuevo, $producto);

        foreach (['costo_base' => 'costo', 'precio_base' => 'precio_venta'] as $base => $campo) {
            if (is_numeric($get($base))) {
                $set($campo, CalculadoraPrecio::aPantalla((float) $get($base), $producto->gravado, $factor));
            }
        }
    }

    private static function factor(mixed $costoPor, Producto $producto): int
    {
        return $costoPor === 'caja' ? max(1, $producto->unidades_por_caja) : 1;
    }

    private static function por(Get $get): string
    {
        return $get('costo_por') === 'caja' ? 'por caja' : 'por unidad';
    }

    /**
     * "MERHONSA · Código 740100167992 · caja de 48 · gravado · utilidad actual 14%".
     */
    private static function ficha(Producto $producto): string
    {
        return collect([
            $producto->proveedor->etiqueta(),
            "Código {$producto->codigo}",
            $producto->unidades_por_caja > 1 ? "caja de {$producto->unidades_por_caja}" : null,
            $producto->gravado ? 'gravado (paga ISV)' : 'exento',
            'utilidad actual '.self::porcentaje(CalculadoraPrecio::margenReal($producto->costo_unidad, $producto->precio_unidad)),
        ])->filter()->implode(' · ');
    }

    /**
     * Precio por unidad sin ISV que daría el % de utilidad de la categoría con ese costo.
     */
    private static function precioSugerido(float $costoBase, Producto $producto): ?float
    {
        if ($costoBase <= 0) {
            return null;
        }

        try {
            return CalculadoraPrecio::precioVenta($costoBase, $producto->margenAplicado());
        } catch (ReglaNegocioException) {
            return null;
        }
    }

    /**
     * El sugerido como se vería en pantalla (caja o unidad, con ISV si es gravado).
     */
    private static function sugeridoEnPantalla(Get $get, Producto $producto): ?float
    {
        $sugerido = is_numeric($get('costo_base')) ? self::precioSugerido((float) $get('costo_base'), $producto) : null;

        return $sugerido === null ? null : CalculadoraPrecio::aPantalla($sugerido, $producto->gravado, self::factor($get('costo_por'), $producto));
    }

    private static function difiereDelSugerido(Get $get, Producto $producto): bool
    {
        $sugerido = self::sugeridoEnPantalla($get, $producto);

        return $sugerido !== null && (! is_numeric($get('precio_venta')) || abs((float) $get('precio_venta') - $sugerido) >= 0.005);
    }

    /**
     * Enlace arriba del precio cuando se cambió a mano: "Usar 14%: L. 594.49" lo regresa al sugerido.
     */
    private static function usarSugerido(Producto $producto): Action
    {
        return Action::make('usarSugerido')
            ->label(fn (Get $get): string => 'Usar '.self::porcentaje($producto->margenAplicado()).': '.lempiras(self::sugeridoEnPantalla($get, $producto) ?? 0))
            ->icon(Heroicon::OutlinedSparkles)
            ->visible(fn (Get $get): bool => self::difiereDelSugerido($get, $producto))
            ->action(function (Get $get, Set $set) use ($producto): void {
                $sugerido = is_numeric($get('costo_base')) ? self::precioSugerido((float) $get('costo_base'), $producto) : null;

                if ($sugerido !== null) {
                    $set('precio_base', $sugerido);
                    $set('precio_venta', CalculadoraPrecio::aPantalla($sugerido, $producto->gravado, self::factor($get('costo_por'), $producto)));
                }
            });
    }

    /**
     * Datos para la tarjeta de la derecha; todo por unidad y con ISV si es gravado.
     *
     * @return array<string, mixed>
     */
    private static function resultado(Get $get, Producto $producto): array
    {
        try {
            $valores = self::aGuardar([
                'costo'        => $get('costo'),
                'costo_base'   => $get('costo_base'),
                'precio_venta' => $get('precio_venta'),
                'precio_base'  => $get('precio_base'),
            ], $producto);
        } catch (ReglaNegocioException $e) {
            return ['mensaje' => $e->getMessage().' La cuenta sale aquí.'];
        }

        $gravado = $producto->gravado;
        $uxc = max(1, $producto->unidades_por_caja);
        $venta = CalculadoraPrecio::conIsv($valores['precio'], $gravado);
        $costoPagado = CalculadoraPrecio::conIsv($valores['costo'], $gravado);
        $ganancia = $venta - $costoPagado;
        $margen = CalculadoraPrecio::margenReal($valores['costo'], $valores['precio']);

        $hoy = $producto->precioUnidadConIsv();
        $diferencia = round($venta - $hoy, 2);

        [$tono, $cambio] = match (true) {
            abs($diferencia) < 0.005 => ['igual', 'Queda igual que hoy'],
            $diferencia > 0          => ['sube', 'Sube '.lempiras($diferencia).self::variacion($diferencia, $hoy)],
            default                  => ['baja', 'Baja '.lempiras(abs($diferencia)).self::variacion($diferencia, $hoy)],
        };

        // Un cambio de más del 25% (en precio o en costo) casi siempre es un error de dedo o de caja/unidad.
        $costoHoy = CalculadoraPrecio::conIsv($producto->costo_unidad, $gravado);
        $precioExagerado = $hoy > 0 && abs($diferencia) / $hoy > self::CAMBIO_SOSPECHOSO;
        $costoExagerado = $costoHoy > 0 && abs($costoPagado - $costoHoy) / $costoHoy > self::CAMBIO_SOSPECHOSO;

        $avisos = array_filter([
            $ganancia < 0 ? 'Está vendiendo por debajo del costo.' : null,
            $precioExagerado || $costoExagerado
                ? 'Es un cambio muy grande contra hoy (costo '.lempiras($costoHoy, 2).' por unidad). Revise los montos y si son por caja o por unidad.'
                : null,
        ]);

        return [
            'mensaje'      => null,
            'gravado'      => $gravado,
            'venta'        => lempiras($venta),
            'sinIsv'       => lempiras($valores['precio']),
            'uxc'          => $uxc > 1 ? $uxc : null,
            'caja'         => lempiras(CalculadoraPrecio::aPantalla($valores['precio'], $gravado, $uxc)),
            'cajaSin'      => lempiras($valores['precio'] * $uxc),
            'costo'        => lempiras($costoPagado, 4),
            'costoNeto'    => lempiras($valores['costo'], 4),
            'margen'       => self::porcentaje($margen),
            'ganancia'     => lempiras($ganancia),
            'gananciaCaja' => $uxc > 1 ? lempiras($ganancia * $uxc) : null,
            'hoy'          => lempiras($hoy),
            'tono'         => $precioExagerado ? 'alerta' : $tono,
            'cambio'       => $cambio,
            'aviso'        => $avisos === [] ? null : implode(' ', $avisos),
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
