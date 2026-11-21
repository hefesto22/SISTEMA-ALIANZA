{{--
    Vista previa de la factura en el modal de Imprimir
    (App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction::conVistaPrevia):
    la hoja tal como va a salir, en el papel de la impresora escogida.
    Datos: $url (ruta pedidos.vista-previa) y $formato (FormatoFactura).
--}}
@php
    $url ??= null;
    $formato ??= \App\Enums\FormatoFactura::Continuo;
@endphp

<style>
    .vpf { border-radius: 0.75rem; border: 1px solid var(--gray-200); background: #e5e7eb; overflow: hidden; }
    .dark .vpf { border-color: var(--gray-700); }
    .vpf iframe { display: block; width: 100%; border: 0; background: #e5e7eb; }
    .vpf.continuo iframe { height: 560px; }
    .vpf.carta iframe { height: min(60vh, 1100px); }
    .vpf-nota { margin-top: 0.5rem; font-size: 0.8125rem; color: var(--gray-500); }
    .dark .vpf-nota { color: var(--gray-400); }
</style>

<div>
    @if ($url)
        <div class="vpf {{ $formato->value }}">
            <iframe src="{{ $url }}" title="Vista previa de la factura" wire:key="vista-previa-{{ md5($url) }}"></iframe>
        </div>
        <p class="vpf-nota">{{ $formato->getDescription() }}</p>
    @endif
</div>
