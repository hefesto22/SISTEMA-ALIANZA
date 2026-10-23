{{--
    Tarjeta de un pedido en la lista (como Cuentas abiertas).
    Columna: App\Filament\Resources\Pedidos\Tables\PedidosTable (ViewColumn "tarjeta").
--}}
@php
    /** @var \App\Models\Pedido $pedido */
    $pedido = $getRecord();
    $cliente = $pedido->cliente;
    $lineas = (int) ($pedido->items_count ?? $pedido->items()->count());
    $ubicacion = collect([$pedido->numero, $cliente->codigo, $cliente->municipio?->nombre])->filter()->implode(' · ');
    $factura = $pedido->factura;
    $porSustituir = $pedido->esReimpresion() ? $pedido->facturaPorSustituir() : null;
    $quien = \App\Filament\Support\Acceso::esVendedor()
        ? $pedido->bodega->nombre
        : collect([$pedido->vendedor->codigo, $pedido->vendedor->name])->filter()->implode(' · ');
@endphp

@once
    <style>
        /* En oscuro el tema shadcn cambia gray-500/600/700: el texto tenue usa gray-400 ahí. */
        .pt { --pt-tenue: var(--gray-500); --pt-borde: var(--gray-200); display: grid; grid-template-columns: minmax(0, 1fr); gap: 0.5rem; width: 100%; min-width: 0; text-align: start; white-space: normal; }
        .dark .pt { --pt-tenue: var(--gray-400); --pt-borde: rgb(255 255 255 / 0.1); }
        .pt-cliente { font-size: 1rem; font-weight: 700; line-height: 1.3; letter-spacing: 0.01em; overflow-wrap: anywhere; }
        .pt-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem; }
        .pt-pill { font-size: 0.72rem; font-weight: 600; line-height: 1.4; padding: 0.1rem 0.5rem; border-radius: 0.375rem; border: 1px solid; }
        .pt-pill.pendiente { color: var(--warning-700); border-color: color-mix(in oklab, var(--warning-500) 45%, transparent); background: color-mix(in oklab, var(--warning-500) 12%, transparent); }
        .pt-pill.por_imprimir { color: var(--info-700); border-color: color-mix(in oklab, var(--info-500) 45%, transparent); background: color-mix(in oklab, var(--info-500) 12%, transparent); }
        .pt-pill.reimprimir { color: var(--primary-700); border-color: color-mix(in oklab, var(--primary-500) 55%, transparent); background: color-mix(in oklab, var(--primary-500) 14%, transparent); }
        .pt-pill.impreso { color: var(--success-700); border-color: color-mix(in oklab, var(--success-500) 45%, transparent); background: color-mix(in oklab, var(--success-500) 12%, transparent); }
        .pt-pill.anulado { color: var(--pt-tenue); border-color: var(--pt-borde); background: color-mix(in oklab, var(--gray-400) 12%, transparent); }
        .pt-pill.fuera { color: var(--danger-700); border-color: color-mix(in oklab, var(--danger-500) 45%, transparent); background: color-mix(in oklab, var(--danger-500) 10%, transparent); }
        .dark .pt-pill.pendiente { color: var(--warning-400); }
        .dark .pt-pill.por_imprimir { color: var(--info-400); }
        .dark .pt-pill.reimprimir { color: var(--primary-400); }
        .dark .pt-pill.impreso { color: var(--success-400); }
        .dark .pt-pill.fuera { color: var(--danger-400); }
        .pt-tiempo { display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.78rem; color: var(--pt-tenue); }
        .pt-tiempo svg { width: 0.9rem; height: 0.9rem; }
        .pt-codigo { font-size: 0.8rem; line-height: 1.45; color: var(--pt-tenue); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
        .pt-codigo.pt-anulada { color: var(--danger-600); font-weight: 600; }
        .dark .pt-codigo.pt-anulada { color: var(--danger-400); }
        .pt-pie { min-width: 0; display: flex; align-items: flex-end; justify-content: space-between; gap: 0.75rem; margin-top: 0.25rem; padding-top: 0.75rem; border-top: 1px solid var(--pt-borde); }
        .pt-chip { flex: 0 1 auto; min-width: 0; font-size: 0.72rem; font-weight: 600; padding: 0.15rem 0.5rem; border-radius: 0.375rem; color: var(--primary-600); border: 1px solid color-mix(in oklab, var(--primary-500) 40%, transparent); background: color-mix(in oklab, var(--primary-500) 10%, transparent); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pt-total { flex-shrink: 0; text-align: end; }
        .pt-monto { font-size: 1.15rem; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .pt-monto.anulado { text-decoration: line-through; color: var(--pt-tenue); }
        .pt-lineas { font-size: 0.75rem; color: var(--pt-tenue); }

        /* La tarjeta no se estira con los textos largos: el vendedor se corta con "…". */
        .pedidos-tarjetas .fi-ta-content-grid .fi-ta-record,
        .pedidos-tarjetas .fi-ta-content-grid .fi-ta-record-content-ctn,
        .pedidos-tarjetas .fi-ta-content-grid .fi-ta-record-content-ctn > div,
        .pedidos-tarjetas .fi-ta-content-grid .fi-ta-record-content,
        .pedidos-tarjetas .fi-ta-content-grid .fi-ta-col { min-width: 0; max-width: 100%; }

        /* Casilla para marcar (imprimir varias): arriba a la derecha, sin correr la tarjeta. */
        .pedidos-tarjetas .fi-ta-content-grid .fi-ta-record { position: relative; }
        .pedidos-tarjetas .fi-ta-content-grid .fi-ta-record-checkbox { position: absolute; top: 1.1rem; inset-inline-end: 1rem; z-index: 1; margin: 0; }
        .pedidos-tarjetas .fi-ta-content-grid .fi-ta-record:has(.fi-ta-record-checkbox) .pt-cliente { padding-inline-end: 1.75rem; }
        .pedidos-tarjetas .fi-ta-content-header { display: flex; align-items: center; gap: 0.6rem; }
        .pedidos-tarjetas .fi-ta-content-header::after { content: 'Marcar todos los de esta página'; font-size: 0.85rem; color: var(--gray-500); }
        .dark .pedidos-tarjetas .fi-ta-content-header::after { color: var(--gray-400); }

        /* Botones de abajo parejos, como en Cuentas abiertas. */
        .pedidos-tarjetas .fi-ta-content-grid .fi-ta-actions { width: 100%; justify-content: stretch; gap: 0.5rem; }
        .pedidos-tarjetas .fi-ta-content-grid .fi-ta-actions > .fi-btn { flex: 1 1 0; justify-content: center; }
    </style>
@endonce

<div class="pt">
    <div class="pt-cliente">{{ $cliente->nombre }}</div>

    <div class="pt-meta">
        @if ($pedido->esReimpresion())
            <span class="pt-pill reimprimir" title="Se corrigió después de impreso{{ $pedido->corregido_at ? ' · '.$pedido->corregido_at->format('d/m/Y H:i') : '' }}{{ $porSustituir ? '. Sale factura nueva; retire la N° '.$porSustituir->numero.' (anulada).' : '' }}">Por reimprimir</span>
        @elseif ($pedido->estado === \App\Enums\EstadoPedido::Impreso)
            <span class="pt-pill impreso" title="{{ $pedido->impreso_at?->format('d/m/Y H:i') }}">Impreso{{ $pedido->impresiones > 1 ? ' · '.$pedido->impresiones.' veces' : '' }}</span>
        @else
            <span class="pt-pill {{ $pedido->estado->value }}">{{ $pedido->estado->getLabel() }}</span>
        @endif

        @if ($pedido->fuera_de_ruta)
            <span class="pt-pill fuera" title="{{ $pedido->motivo_fuera_ruta }}">Fuera de ruta</span>
        @endif

        <span class="pt-tiempo" title="{{ $pedido->created_at?->format('d/m/Y H:i') }}">
            {{ svg('heroicon-m-clock') }} {{ $pedido->created_at?->diffForHumans() }}
        </span>
    </div>

    <div class="pt-codigo">{{ $ubicacion }}</div>

    @if ($factura)
        <div class="pt-codigo">Factura {{ $factura->numero }}</div>
    @elseif ($porSustituir)
        <div class="pt-codigo pt-anulada">Factura {{ $porSustituir->numero }} anulada: retirarla</div>
    @endif

    <div class="pt-pie">
        <span class="pt-chip" title="{{ $quien }}">{{ $quien }}</span>

        <div class="pt-total">
            <div @class(['pt-monto', 'anulado' => $pedido->estado === \App\Enums\EstadoPedido::Anulado])>{{ lempiras($pedido->total) }}</div>
            <div class="pt-lineas">{{ $lineas }} {{ $lineas === 1 ? 'producto' : 'productos' }}</div>
        </div>
    </div>
</div>
