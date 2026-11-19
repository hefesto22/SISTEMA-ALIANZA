<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Domain\Devoluciones\RegistrarDevolucion;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\EstadoPedido;
use App\Enums\TipoDevolucion;
use App\Filament\Support\Acceso;
use App\Models\Configuracion;
use App\Models\Devolucion;
use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * "Devolución" en un pedido ya entregado (impreso): de cada producto,
 * cuántas regresan buenas (vuelven a la bodega) y cuántas dañadas (quedan
 * marcadas), y si se le cambia por lo mismo o se le da crédito (con nota de
 * crédito si está encendida en Configuración). Lo hace RegistrarDevolucion.
 */
final class DevolucionAction
{
    public static function make(): Action
    {
        return Action::make('devolucion')
            ->label('Devolución')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Pedido $record): bool => $record->estado === EstadoPedido::Impreso && (Acceso::usuario()?->can('create', Devolucion::class) ?? false))
            ->modalHeading(fn (Pedido $record): string => "Devolución · {$record->numero} · {$record->cliente->nombre}")
            ->modalDescription('Lo bueno regresa a la bodega; lo dañado no, queda marcado para el reporte de producto dañado. Las unidades no importan de qué caja salieron: se cuentan en la presentación de cada línea.')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Registrar devolución')
            ->fillForm(fn (Pedido $record): array => ['tipo' => TipoDevolucion::Cambio->value, 'lineas' => self::lineas($record)])
            ->schema([
                ToggleButtons::make('tipo')
                    ->label('Qué se le da al cliente')
                    ->options(TipoDevolucion::class)
                    ->icons([
                        TipoDevolucion::Cambio->value  => Heroicon::OutlinedArrowsRightLeft,
                        TipoDevolucion::Credito->value => Heroicon::OutlinedBanknotes,
                    ])
                    ->inline()
                    ->required()
                    ->helperText(fn (): string => Configuracion::actual()->notas_credito
                        ? 'Con crédito se emite la nota de crédito (CAI tipo 07) con los datos de la factura.'
                        : 'Con crédito queda como crédito interno: la nota de crédito se hace a mano (se enciende en Configuración).'),
                Repeater::make('lineas')
                    ->label('Lo que regresa')
                    ->table([
                        TableColumn::make('Producto'),
                        TableColumn::make('Puede devolver')->width('140px'),
                        TableColumn::make('Buenas')->width('100px'),
                        TableColumn::make('Dañadas')->width('100px'),
                    ])
                    ->schema([
                        Hidden::make('id'),
                        TextInput::make('producto')->hiddenLabel()->disabled()->dehydrated(false),
                        TextInput::make('puede')->hiddenLabel()->disabled()->dehydrated(false),
                        TextInput::make('buenas')->hiddenLabel()->numeric()->integer()->minValue(0)->default(0),
                        TextInput::make('danadas')->hiddenLabel()->numeric()->integer()->minValue(0)->default(0),
                    ])
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false),
                TextInput::make('motivo')
                    ->label('Motivo')
                    ->placeholder('Ej. venían golpeados')
                    ->maxLength(200),
            ])
            ->action(function (array $data, Pedido $record): void {
                $usuario = Acceso::usuario();

                if ($usuario === null) {
                    return;
                }

                $lineas = [];

                foreach ((array) ($data['lineas'] ?? []) as $fila) {
                    if (is_array($fila) && is_numeric($fila['id'] ?? null)) {
                        $lineas[(int) $fila['id']] = [
                            'buenas'  => is_numeric($fila['buenas'] ?? null) ? (int) $fila['buenas'] : 0,
                            'danadas' => is_numeric($fila['danadas'] ?? null) ? (int) $fila['danadas'] : 0,
                        ];
                    }
                }

                $tipo = TipoDevolucion::tryFrom(is_string($data['tipo'] ?? null) ? $data['tipo'] : '') ?? TipoDevolucion::Cambio;

                try {
                    $devolucion = app(RegistrarDevolucion::class)->ejecutar($record, $tipo, $lineas, $usuario, is_string($data['motivo'] ?? null) ? $data['motivo'] : null);
                } catch (ReglaNegocioException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title("Devolución {$devolucion->numero} registrada")
                    ->body(match (true) {
                        $devolucion->conNota()                        => "Nota de crédito N° {$devolucion->nota_numero} por ".lempiras($devolucion->total).'.',
                        $devolucion->tipo === TipoDevolucion::Credito => 'Crédito interno por '.lempiras($devolucion->total).'.',
                        default                                       => 'Se le repone lo mismo; sale de la bodega.',
                    })
                    ->success()
                    ->actions([
                        Action::make('imprimirDevolucion')
                            ->label($devolucion->conNota() ? 'Imprimir nota de crédito' : 'Imprimir constancia')
                            ->url(route('devoluciones.imprimir', $devolucion), shouldOpenInNewTab: true),
                    ])
                    ->persistent()
                    ->send();
            });
    }

    /**
     * Las líneas que salieron y todavía se pueden devolver.
     *
     * @return list<array{id: int, producto: string, puede: string, buenas: int, danadas: int}>
     */
    public static function lineas(Pedido $pedido): array
    {
        $devueltas = RegistrarDevolucion::devueltas($pedido);
        $filas = [];

        foreach ($pedido->items()->with(['producto', 'presentacion.unidadMedida'])->orderBy('id')->get() as $item) {
            $puede = $item->cantidadFacturable() - ($devueltas[$item->id] ?? 0);

            if ($puede <= 0) {
                continue;
            }

            $filas[] = [
                'id'       => $item->id,
                'producto' => "{$item->presentacion->sigla()} · {$item->producto->descripcion}".($item->bonificacion ? ' (regalo)' : ''),
                'puede'    => $item->presentacion->enPalabras($puede),
                'buenas'   => 0,
                'danadas'  => 0,
            ];
        }

        return $filas;
    }
}
