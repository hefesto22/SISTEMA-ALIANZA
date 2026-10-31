<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Pedidos\ValidarDespacho;
use App\Enums\PrecioAlDespachar;
use App\Filament\Support\Acceso;
use App\Models\Configuracion;
use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * Bodega revisa el pedido línea por línea: lo que no hay se marca "No hay",
 * lo que no alcanzó se baja y, si el cliente pidió más, se sube. Al confirmar, el
 * pedido se recalcula (precio según Configuración), sale de la existencia
 * de la bodega y queda Por imprimir.
 *
 * Las líneas quitadas no se borran del pedido: quedan con "sale 0" para que
 * se sepa qué faltó (en la impresión salen tachadas).
 */
final class ValidarDespachoAction
{
    public static function make(): Action
    {
        return Action::make('validarDespacho')
            ->label('Validar')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('success')
            ->authorize('validar')
            ->modalHeading(fn (Pedido $record): string => "Validar · {$record->numero} · {$record->cliente->nombre}")
            ->modalDescription(fn (): string => self::descripcion())
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Validar')
            ->fillForm(fn (Pedido $record): array => LineasPedido::datos($record, segunExistencia: true))
            ->schema(LineasPedido::schema(paraValidar: true))
            ->action(function (array $data, Pedido $record): void {
                $usuario = Acceso::usuario();

                if ($usuario === null) {
                    return;
                }

                try {
                    $pedido = app(ValidarDespacho::class)->ejecutar($record, LineasPedido::cantidades($data, $record), $usuario);
                } catch (ReglaNegocioException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title("Pedido {$pedido->numero} validado: queda por imprimir")
                    ->body('Total para el cliente: '.lempiras($pedido->total).'. '.LineasPedido::incompletas($pedido))
                    ->success()
                    ->actions([ImprimirPedidoAction::enNotificacion($pedido)])
                    ->send();

                $record->refresh();
            });
    }

    private static function descripcion(): string
    {
        $configuracion = Configuracion::actual();

        $precio = $configuracion->precio_al_despachar === PrecioAlDespachar::Pedido
            ? 'Se cobra el precio del pedido (el de hoy si ese quedaría por debajo del costo)'
            : 'Se cobran los precios de hoy';

        $existencia = $configuracion->controlar_existencias
            ? ' "Sale" ya viene con lo que alcanza en la bodega.'
            : '';

        return "Cambie lo que sale con − y +: \"No hay\" si no hay, menos si no alcanzó, más si el cliente pidió más.{$existencia} {$precio}; lo que sale se descuenta de la bodega y el pedido pasa a Por imprimir.";
    }
}
