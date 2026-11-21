<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Facturacion\ImprimirFacturas;
use App\Enums\EstadoPedido;
use App\Enums\FormatoFactura;
use App\Enums\ModoImpresion;
use App\Filament\Support\Acceso;
use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Width;
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
 *    Las dos abren primero un modal con la vista previa de la hoja y la
 *    impresora: Epson LX-350 (media carta) o impresora normal (carta)
 *    (conVistaPrevia(); también la copia de la lista de Facturas).
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
        return self::conVistaPrevia(
            Action::make('imprimir')
                ->label(fn (Pedido $record): string => $record->esReimpresion() ? 'Reimprimir' : 'Imprimir')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->authorize('imprimir')
                ->visible(fn (Pedido $record): bool => $record->estado === EstadoPedido::PorImprimir)
                ->modalHeading(fn (Pedido $record): string => ($record->esReimpresion() ? 'Reimprimir' : 'Imprimir')." la factura del pedido {$record->numero}")
                ->fillForm(fn (Pedido $record): array => self::datosDelModal($record))
                ->action(function (array $data, Pedido $record, Component $livewire): void {
                    self::ejecutar($livewire, [$record->id], ModoImpresion::Originales, self::papel($data['papel'] ?? null));
                }),
            ModoImpresion::Originales,
        );
    }

    public static function copia(): Action
    {
        return self::conVistaPrevia(
            Action::make('copia')
                ->label('Imprimir copia')
                ->icon(Heroicon::OutlinedDocumentDuplicate)
                ->color('gray')
                ->authorize('imprimir')
                ->visible(fn (Pedido $record): bool => $record->estado === EstadoPedido::Impreso)
                ->modalHeading(fn (Pedido $record): string => 'Imprimir copia de la factura '.($record->factura->numero ?? $record->numero))
                ->fillForm(fn (Pedido $record): array => self::datosDelModal($record))
                ->action(function (array $data, Pedido $record, Component $livewire): void {
                    self::ejecutar($livewire, [$record->id], ModoImpresion::Copia, self::papel($data['papel'] ?? null));
                }),
            ModoImpresion::Copia,
        );
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
            ->fillForm(fn (Pedido $record): array => ['papel' => FormatoFactura::para(null, $record->bodega)->value])
            ->schema([self::campoPapel()])
            ->action(function (array $data, Pedido $record, Component $livewire): void {
                self::ejecutar($livewire, [$record->id], ModoImpresion::NoSalio, self::papel($data['papel'] ?? null));
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
            ->modalHeading('Imprimir los pedidos marcados')
            ->modalDescription('Los Por imprimir salen como original y los ya impresos como copia, una factura por página.')
            ->modalSubmitActionLabel('Imprimir')
            ->modalWidth(Width::Large)
            ->fillForm(fn (): array => ['papel' => FormatoFactura::delUsuario(Acceso::usuario())->value])
            ->schema([self::campoPapel()])
            ->action(function (array $data, Collection $records, Component $livewire): void {
                self::ejecutar($livewire, self::idsDe($records), ModoImpresion::Seleccionados, self::papel($data['papel'] ?? null));
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * El modal antes de imprimir una: la hoja como va a salir y la impresora
     * (Epson LX-350 en media carta o una normal en carta); al cambiar de
     * impresora, la vista previa cambia de papel. Quien lo usa pone el
     * título, fillForm (datosDelModal) y la acción (con papel()).
     */
    public static function conVistaPrevia(Action $action, ModoImpresion $modo): Action
    {
        return $action
            ->modalIcon(Heroicon::OutlinedPrinter)
            ->modalDescription('Así va a salir. Escoja la impresora: la vista previa cambia al papel de cada una.')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Imprimir')
            ->schema([
                Hidden::make('pedido'),
                self::campoPapel()->live(),
                View::make('filament.vista-previa-factura')
                    ->viewData(fn (Get $get): array => self::hojaPrevia($get('pedido'), $get('papel'), $modo)),
            ]);
    }

    /**
     * Con qué abre el modal: el pedido (para la vista previa) y el papel de su bodega.
     *
     * @return array{pedido: int, papel: string}
     */
    public static function datosDelModal(Pedido $pedido): array
    {
        return ['pedido' => $pedido->id, 'papel' => FormatoFactura::para(null, $pedido->bodega)->value];
    }

    /**
     * La impresora escogida en el modal (si no llegó, media carta).
     */
    public static function papel(mixed $valor): FormatoFactura
    {
        if ($valor instanceof FormatoFactura) {
            return $valor;
        }

        return (is_string($valor) ? FormatoFactura::tryFrom($valor) : null) ?? FormatoFactura::Continuo;
    }

    /**
     * Los dos botones de impresora: Epson LX-350 (media carta) o normal (carta).
     */
    public static function campoPapel(): ToggleButtons
    {
        $opciones = [];

        foreach (FormatoFactura::cases() as $formato) {
            $opciones[$formato->value] = $formato->impresora();
        }

        return ToggleButtons::make('papel')
            ->label('Impresora')
            ->options($opciones)
            ->icons([
                FormatoFactura::Continuo->value => Heroicon::OutlinedPrinter,
                FormatoFactura::Carta->value    => Heroicon::OutlinedDocumentText,
            ])
            ->grouped()
            ->fullWidth()
            ->required();
    }

    /**
     * Imprime (en el servidor) y manda la hoja al navegador. Avisa lo que se
     * saltó y deja a mano "No salió" por si la impresora falla.
     *
     * @param list<int> $ids
     */
    public static function ejecutar(Component $livewire, array $ids, ModoImpresion $modo, ?FormatoFactura $papel = null): void
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

        $livewire->dispatch(self::EVENTO, url: $resultado->url($papel));

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
                    ->dispatch(self::PEDIR, ['ids' => $resultado->pedidos, 'modo' => ModoImpresion::NoSalio->value, 'papel' => $papel?->value])
                    ->close(),
            ])
            ->send();
    }

    /**
     * Para las páginas donde se imprime: atiende los botones de los avisos
     * ("Imprimir" al validar o corregir, "No salió"), que no pueden llamar
     * una acción de la página directamente. Quién puede imprimir qué lo
     * decide ImprimirFacturas, no el evento. "No salió" sale en el mismo
     * papel que la vez anterior; sin papel, el de la bodega.
     *
     * @param array<int|string, mixed> $ids
     */
    public static function desdeAviso(Component $livewire, array $ids, string $modo, ?string $papel = null): void
    {
        $lista = array_values(array_filter(array_map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids)));

        self::ejecutar($livewire, $lista, ModoImpresion::tryFrom($modo) ?? ModoImpresion::Originales, $papel === null ? null : FormatoFactura::tryFrom($papel));
    }

    /**
     * Para la vista del modal: el enlace a la hoja en el papel escogido.
     *
     * @return array{url: string|null, formato: FormatoFactura}
     */
    private static function hojaPrevia(mixed $pedido, mixed $papel, ModoImpresion $modo): array
    {
        $formato = self::papel($papel);

        if (! is_numeric($pedido)) {
            return ['url' => null, 'formato' => $formato];
        }

        return [
            'url'     => route('pedidos.vista-previa', ['pedido' => (int) $pedido, 'modo' => $modo === ModoImpresion::Copia ? 'copia' : 'original', 'hoja' => $formato->value]),
            'formato' => $formato,
        ];
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
