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
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * "Actualizar precio": se mete el costo de la factura del proveedor (por caja
 * o por unidad, con descuento si hubo), se ve al instante el precio que sale
 * con el % de utilidad y se guarda. Aplica a TODAS las bodegas y deja historial.
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
            ->modalHeading(fn (Producto $record): string => "Actualizar precio · {$record->descripcion}")
            ->modalDescription('El precio nuevo aplica en todas las bodegas y queda en el historial.')
            ->modalSubmitActionLabel('Guardar precio')
            ->fillForm(fn (Producto $record): array => [
                'costo_por' => $record->unidades_por_caja > 1 ? 'caja' : 'unidad',
                'costo'     => $record->unidades_por_caja > 1
                    ? round($record->costo_unidad * $record->unidades_por_caja, 4)
                    : $record->costo_unidad,
                'cantidad'      => 1,
                'descuento'     => 0,
                'precio_manual' => false,
            ])
            ->schema([
                Grid::make(2)->schema([
                    ToggleButtons::make('costo_por')
                        ->label('El costo de la factura es')
                        ->options(['caja' => 'Por caja', 'unidad' => 'Por unidad'])
                        ->inline()
                        ->required()
                        ->live(),
                    TextInput::make('costo')
                        ->label(fn (Get $get): string => $get('costo_por') === 'caja' ? 'Costo por caja (sin ISV)' : 'Costo por unidad (sin ISV)')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('L.')
                        ->required()
                        ->live(debounce: 400),
                    TextInput::make('cantidad')
                        ->label('Cantidad comprada')
                        ->helperText('Solo importa si hay descuento.')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->required()
                        ->live(debounce: 400),
                    TextInput::make('descuento')
                        ->label('Descuento total de la línea')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('L.')
                        ->live(debounce: 400),
                ]),
                TextEntry::make('calculo')
                    ->hiddenLabel()
                    ->state(fn (Get $get, Producto $record): HtmlString => self::resumen($get, $record)),
                Toggle::make('precio_manual')
                    ->label('Ajustar el precio de venta a mano (redondear)')
                    ->live(),
                Grid::make(2)
                    ->visible(fn (Get $get): bool => (bool) $get('precio_manual'))
                    ->schema([
                        TextInput::make('precio_unidad')
                            ->label('Precio de venta por unidad (sin ISV)')
                            ->numeric()
                            ->minValue(0.0001)
                            ->prefix('L.')
                            ->required(fn (Get $get): bool => (bool) $get('precio_manual')),
                    ]),
                TextInput::make('nota')
                    ->label('Nota (opcional)')
                    ->placeholder('Ej: Factura 375448')
                    ->maxLength(255),
            ])
            ->action(function (array $data, Producto $record): void {
                /** @var User|null $usuario */
                $usuario = Auth::user();
                $manual = (bool) ($data['precio_manual'] ?? false);
                $precioAnterior = $record->precio_unidad;

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
     * Vista previa del cálculo mientras se escribe el costo.
     */
    private static function resumen(Get $get, Producto $producto): HtmlString
    {
        if (! is_numeric($get('costo'))) {
            return new HtmlString('<span class="text-gray-500">Escriba el costo para ver el precio.</span>');
        }

        try {
            $costo = self::costoUnidad([
                'costo'     => $get('costo'),
                'costo_por' => $get('costo_por'),
                'cantidad'  => $get('cantidad'),
                'descuento' => $get('descuento'),
            ], $producto);
            $margen = $producto->margenAplicado();
            $precio = CalculadoraPrecio::precioVenta($costo, $margen);
        } catch (ReglaNegocioException $e) {
            return new HtmlString('<span class="text-danger-600">'.e($e->getMessage()).'</span>');
        }

        $uxc = max(1, $producto->unidades_por_caja);
        $porcentaje = rtrim(rtrim(number_format($margen * 100, 2), '0'), '.');
        $isv = $producto->gravado ? 'con ISV' : 'exento';

        $filas = [
            ['Costo por unidad', lempiras($costo, 4).' (antes '.lempiras($producto->costo_unidad, 4).')'],
            ["Precio por unidad ({$porcentaje}% utilidad)", '<b>'.lempiras($precio).'</b> · '.$isv.': '.lempiras(CalculadoraPrecio::conIsv($precio, $producto->gravado))],
            ["Precio por caja de {$uxc}", '<b>'.lempiras($precio * $uxc).'</b> · '.$isv.': '.lempiras(CalculadoraPrecio::conIsv($precio * $uxc, $producto->gravado))],
            ['Precio actual por unidad', lempiras($producto->precio_unidad)],
        ];

        $html = '<table class="w-full text-sm">';

        foreach ($filas as [$etiqueta, $valor]) {
            $html .= '<tr><td class="py-1 pe-4 text-gray-500">'.e($etiqueta).'</td><td class="py-1">'.$valor.'</td></tr>';
        }

        return new HtmlString($html.'</table>');
    }
}
