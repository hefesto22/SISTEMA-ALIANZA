<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Facturacion\ImprimirFacturas;
use App\Enums\EstadoPedido;
use App\Enums\ModoImpresion;
use App\Filament\Support\Acceso;
use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Imprimir facturas ahí mismo (diálogo de imprimir del navegador, sin otra
 * pestaña). Las acciones corren en el servidor (ImprimirFacturas: emite la
 * factura y cuenta la impresión, con candados) y después mandan el evento
 * "imprimir-hoja" con el enlace firmado de la hoja; lo atiende
 * resources/views/filament/imprimir-hoja.blade.php.
 *
 *  - make():          "Imprimir" / "Reimprimir" de uno Por imprimir (original).
 *  - copia():         "Imprimir copia" de uno impreso.
 *  - noSalio():       la hoja no salió: la misma otra vez, sin contar copia.
 *  - vistaPrevia():   ver la hoja sin imprimir nada (cualquiera que vea el
 *                     pedido; si tiene factura sale COPIA · SIN VALOR FISCAL).
 *  - seleccionados(): los marcados en la lista.
 *  - enNotificacion():botón Imprimir del aviso al validar o corregir.
 *
 * Las páginas donde se imprime escuchan "imprimir-pedidos" (desdeAviso) para
 * los botones de los avisos.
 */
final class ImprimirPedidoAction
{
    /** Evento del navegador con { url } de la hoja. */
    public const EVENTO = 'imprimir-hoja';

    /** Evento para pedir una impresión desde un aviso: { ids, modo }. */
    public const PEDIR = 'imprimir-pedidos';

    public static function make(): Action
    {
        return Action::make('imprimir')
            ->label(fn (Pedido $record): string => $record->esReimpresion() ? 'Reimprimir' : 'Imprimir')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->authorize('imprimir')
            ->visible(fn (Pedido $record): bool => $record->estado === EstadoPedido::PorImprimir)
            ->action(function (Pedido $record, Component $livewire): void {
                self::ejecutar($livewire, [$record->id], ModoImpresion::Originales);
            });
    }

    public static function copia(): Action
    {
        return Action::make('copia')
            ->label('Imprimir copia')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->authorize('imprimir')
            ->visible(fn (Pedido $record): bool => $record->estado === EstadoPedido::Impreso)
            ->action(function (Pedido $record, Component $livewire): void {
                self::ejecutar($livewire, [$record->id], ModoImpresion::Copia);
            });
    }

    public static function noSalio(): Action
    {
        return Action::make('noSalio')
            ->label('No salió: reimprimir')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->authorize('imprimir')
            ->visible(fn (Pedido $record): bool => $record->estado === EstadoPedido::Impreso)
            ->requiresConfirmation()
            ->modalHeading('¿No salió la factura?')
            ->modalDescription('Se vuelve a imprimir igual que la vez anterior (si era el original, sale como original). Queda registrado quién lo hizo y cuándo.')
            ->modalSubmitActionLabel('Reimprimir')
            ->action(function (Pedido $record, Component $livewire): void {
                self::ejecutar($livewire, [$record->id], ModoImpresion::NoSalio);
            });
    }

    public static function vistaPrevia(): Action
    {
        return Action::make('hoja')
            ->label('Vista previa')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('gray')
            ->authorize('view')
            ->dispatch(self::EVENTO, fn (Pedido $record): array => ['url' => route('pedidos.imprimir', $record)]);
    }

    /**
     * Botón "Imprimir" del aviso al validar o corregir.
     */
    public static function enNotificacion(Pedido $pedido): Action
    {
        return Action::make('imprimir')
            ->label($pedido->esReimpresion() ? 'Reimprimir' : 'Imprimir')
            ->dispatch(self::PEDIR, ['ids' => [$pedido->id], 'modo' => ModoImpresion::Originales->value])
            ->close();
    }

    /**
     * Los pedidos marcados en la lista: los Por imprimir salen original, los
     * impresos como copia.
     */
    public static function seleccionados(): BulkAction
    {
        return BulkAction::make('imprimirSeleccionados')
            ->label('Imprimir seleccionados')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            // En Pendiente y Anulado no hay nada que imprimir: ni se marcan.
            ->visible(fn (Component $livewire): bool => Acceso::puede('Validar:Pedido')
                && ! in_array(data_get($livewire, 'activeTab'), [EstadoPedido::Pendiente->value, EstadoPedido::Anulado->value], true))
            ->action(function (Collection $records, Component $livewire): void {
                self::ejecutar($livewire, self::idsDe($records), ModoImpresion::Seleccionados);
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Imprime (en el servidor) y manda la hoja al navegador. Avisa lo que se
     * saltó y deja a mano "No salió" por si la impresora falla.
     *
     * @param list<int> $ids
     */
    public static function ejecutar(Component $livewire, array $ids, ModoImpresion $modo): void
    {
        $usuario = Acceso::usuario();

        if ($usuario === null) {
            return;
        }

        try {
            $resultado = app(ImprimirFacturas::class)->ejecutar($ids, $usuario, $modo);
        } catch (ReglaNegocioException $e) {
            Notification::make()->title('No se imprimió')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        if ($resultado->saltados !== []) {
            Notification::make()
                ->title($resultado->hayQueImprimir()
                    ? 'Se saltaron '.count($resultado->saltados).' pedido(s)'
                    : 'No hay nada que imprimir')
                ->body(implode(' · ', array_slice($resultado->saltados, 0, 8)).(count($resultado->saltados) > 8 ? ' · …' : ''))
                ->warning()
                ->persistent()
                ->send();
        }

        if (! $resultado->hayQueImprimir()) {
            return;
        }

        $livewire->dispatch(self::EVENTO, url: $resultado->url());

        $cuantas = count($resultado->facturas);

        Notification::make()
            ->title($cuantas === 1
                ? 'Factura '.($resultado->facturas[0] ?? '').' enviada a imprimir'
                : "{$cuantas} facturas enviadas a imprimir")
            ->body('Si la impresora falló o se canceló, use "No salió": sale igual, sin contarla como copia.')
            ->success()
            ->actions($modo === ModoImpresion::NoSalio ? [] : [
                Action::make('noSalio')
                    ->label('No salió')
                    ->color('gray')
                    ->dispatch(self::PEDIR, ['ids' => $resultado->pedidos, 'modo' => ModoImpresion::NoSalio->value])
                    ->close(),
            ])
            ->send();
    }

    /**
     * Para las páginas donde se imprime: atiende los botones de los avisos
     * ("Imprimir" al validar o corregir, "No salió"), que no pueden llamar
     * una acción de la página directamente. Quién puede imprimir qué lo
     * decide ImprimirFacturas, no el evento.
     *
     * @param array<int|string, mixed> $ids
     */
    public static function desdeAviso(Component $livewire, array $ids, string $modo): void
    {
        $lista = array_values(array_filter(array_map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids)));

        self::ejecutar($livewire, $lista, ModoImpresion::tryFrom($modo) ?? ModoImpresion::Originales);
    }

    /**
     * @param Collection<array-key, mixed> $records
     *
     * @return list<int>
     */
    private static function idsDe(Collection $records): array
    {
        $ids = [];

        foreach ($records as $pedido) {
            if ($pedido instanceof Pedido) {
                $ids[] = $pedido->id;
            }
        }

        return $ids;
    }
}
