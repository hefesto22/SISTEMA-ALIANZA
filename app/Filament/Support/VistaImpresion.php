<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

/**
 * Botón "PDF / Imprimir" de los reportes: abre la hoja en un modal, como
 * vista de impresión, sin abrir otra pestaña. Abajo, "Imprimir o guardar
 * PDF" abre ahí mismo la ventana de imprimir del navegador (que también
 * guarda el PDF).
 *
 * La hoja es la misma de imprimir (App\Http\Controllers\ImprimirReporteController)
 * con embebido=1: sin la barra de arriba y sin abrir sola la ventana de imprimir.
 */
final class VistaImpresion
{
    public const MARCO = 'vista-impresion';

    /**
     * @param Closure(): string $url enlace de la hoja, ya con embebido=1
     */
    public static function accion(string $titulo, Closure $url): Action
    {
        return Action::make('pdf')
            ->label('PDF / Imprimir')
            ->icon(Heroicon::OutlinedPrinter)
            ->modalHeading($titulo)
            ->modalDescription('Vista de impresión. Para el PDF, en la ventana de imprimir escoja «Guardar como PDF».')
            ->modalIcon(Heroicon::OutlinedPrinter)
            ->modalWidth(Width::SevenExtraLarge)
            ->modalContent(fn (): View => view('filament.reportes.vista-impresion', [
                'url'   => $url(),
                'marco' => self::MARCO,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            ->extraModalFooterActions([
                Action::make('imprimirVista')
                    ->label('Imprimir o guardar PDF')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->alpineClickHandler("document.getElementById('".self::MARCO."')?.contentWindow?.print()"),
            ]);
    }
}
