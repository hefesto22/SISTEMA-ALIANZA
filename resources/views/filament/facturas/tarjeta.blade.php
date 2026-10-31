{{--
    Tarjeta de una factura en la lista (como las de Pedidos).
    Columna: App\Filament\Resources\Facturas\FacturaResource (ViewColumn "tarjeta").

    Arriba el número y si vale o está anulada; el cliente con su RTN; cuándo
    se emitió y quién; el pedido (y la bodega, para administración). Lo que
    pasó con ella va en un recuadro: a cuál sustituye, o, si se anuló,
    cuándo, quién, por qué y cuál la sustituyó (o que falta reimprimir).
    Abajo el vendedor y el total con su ISV.
--}}
@php
    /** @var \App\Models\Factura $factura */
    $factura = $getRecord();
    $anulada = $factura->estaAnulada();
    $vendedor = $factura->pedido->vendedor;
    $quien = collect([$vendedor?->codigo, $vendedor?->name])->filter()->implode(' · ');
    $donde = collect([
        'Pedido '.$factura->pedido->numero,
        \App\Filament\Support\Acceso::veTodo() ? $factura->bodega->nombre : null,
    ])->filter()->implode(' · ');
    $copias = max(0, $factura->impresiones - 1);
@endphp

@once
    <style>
        .ft { --ft-tenue: var(--gray-500); --ft-borde: var(--gray-200); display: grid; grid-template-columns: minmax(0, 1fr); gap: 0.45rem; width: 100%; min-width: 0; text-align: start; white-space: normal; }
        .dark .ft { --ft-tenue: var(--gray-400); --ft-borde: rgb(255 255 255 / 0.1); }

        .ft-arriba { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
        .ft-numero { min-width: 0; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace; font-size: 0.85rem; font-weight: 700; letter-spacing: 0.01em; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
        .ft-pill { flex-shrink: 0; font-size: 0.72rem; font-weight: 600; line-height: 1.4; padding: 0.1rem 0.5rem; border-radius: 0.375rem; border: 1px solid; }
        .ft-pill.vigente { color: var(--success-700); border-color: color-mix(in oklab, var(--success-500) 45%, transparent); background: color-mix(in oklab, var(--success-500) 12%, transparent); }
        .ft-pill.anulada { color: var(--danger-700); border-color: color-mix(in oklab, var(--danger-500) 45%, transparent); background: color-mix(in oklab, var(--danger-500) 10%, transparent); }
        .dark .ft-pill.vigente { color: var(--success-400); }
        .dark .ft-pill.anulada { color: var(--danger-400); }

        .ft-cliente { margin-top: 0.15rem; font-size: 1rem; font-weight: 700; line-height: 1.3; letter-spacing: 0.01em; overflow-wrap: anywhere; }
        .ft-tenue { font-size: 0.8rem; line-height: 1.45; color: var(--ft-tenue); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
        .ft-cuando { display: flex; align-items: center; gap: 0.3rem; }
        .ft-cuando svg { flex-shrink: 0; width: 0.9rem; height: 0.9rem; }

        /* Lo que pasó con la factura. */
        .ft-nota { display: grid; gap: 0.15rem; margin-top: 0.15rem; padding: 0.5rem 0.65rem; border-radius: 0.5rem; font-size: 0.78rem; line-height: 1.4; overflow-wrap: anywhere; color: var(--gray-700); background: color-mix(in oklab, var(--gray-400) 10%, transparent); }
        .dark .ft-nota { color: var(--gray-300); }
        .ft-nota.anulada { color: var(--danger-800); background: color-mix(in oklab, var(--danger-500) 10%, transparent); }
        .dark .ft-nota.anulada { color: var(--danger-300); }
        .ft-nota strong { font-weight: 700; }
        .ft-nota .ft-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace; font-weight: 600; }
        .ft-nota .ft-falta { font-weight: 700; color: var(--danger-600); }
        .dark .ft-nota .ft-falta { color: var(--danger-400); }

        .ft-pie { min-width: 0; display: flex; align-items: flex-end; justify-content: space-between; gap: 0.75rem; margin-top: 0.3rem; padding-top: 0.75rem; border-top: 1px solid var(--ft-borde); }
        .ft-chip { flex: 0 1 auto; min-width: 0; font-size: 0.72rem; font-weight: 600; padding: 0.15rem 0.5rem; border-radius: 0.375rem; color: var(--primary-600); border: 1px solid color-mix(in oklab, var(--primary-500) 40%, transparent); background: color-mix(in oklab, var(--primary-500) 10%, transparent); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .dark .ft-chip { color: var(--primary-400); }
        .ft-total { flex-shrink: 0; text-align: end; }
        .ft-monto { font-size: 1.15rem; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .ft-monto.anulado { text-decoration: line-through; color: var(--ft-tenue); }
        .ft-isv { font-size: 0.75rem; color: var(--ft-tenue); font-variant-numeric: tabular-nums; white-space: nowrap; }

        /* La tarjeta no se estira con los textos largos. */
        .facturas-tarjetas .fi-ta-content-grid .fi-ta-record,
        .facturas-tarjetas .fi-ta-content-grid .fi-ta-record-content-ctn,
        .facturas-tarjetas .fi-ta-content-grid .fi-ta-record-content-ctn > div,
        .facturas-tarjetas .fi-ta-content-grid .fi-ta-record-content,
        .facturas-tarjetas .fi-ta-content-grid .fi-ta-col { min-width: 0; max-width: 100%; }

        /* Botones de abajo parejos. */
        .facturas-tarjetas .fi-ta-content-grid .fi-ta-actions { width: 100%; justify-content: stretch; gap: 0.5rem; }
        .facturas-tarjetas .fi-ta-content-grid .fi-ta-actions > .fi-btn { flex: 1 1 0; justify-content: center; }
    </style>
@endonce

<div class="ft">
    <div class="ft-arriba">
        <span class="ft-numero">{{ $factura->numero }}</span>
        <span class="ft-pill {{ $factura->estado->value }}">{{ $factura->estado->getLabel() }}</span>
    </div>

    <div class="ft-cliente">{{ $factura->cliente_nombre }}</div>
    <div class="ft-tenue">{{ filled($factura->cliente_rtn) ? 'RTN '.$factura->cliente_rtn : 'Consumidor final' }}</div>

    <div class="ft-tenue ft-cuando">
        {{ svg('heroicon-m-clock') }}
        <span>{{ $factura->fecha_emision->format('d/m/Y H:i') }}{{ $factura->emisor ? ' · '.$factura->emisor->name : '' }}</span>
    </div>
    <div class="ft-tenue">{{ $donde }}</div>

    @if ($anulada)
        <div class="ft-nota anulada">
            <span><strong>Anulada</strong> {{ $factura->anulada_at?->format('d/m/Y H:i') }}{{ $factura->anulador ? ' · '.$factura->anulador->name : '' }}</span>
            @if (filled($factura->motivo_anulacion))
                <span>Motivo: {{ $factura->motivo_anulacion }}</span>
            @endif
            @if ($factura->reemplazo)
                <span>Sustituida por <span class="ft-mono">{{ $factura->reemplazo->numero }}</span></span>
            @else
                <span class="ft-falta">Falta reimprimir el pedido</span>
            @endif
        </div>
    @elseif ($factura->reemplazaA || $copias > 0 || $factura->reintentos > 0)
        <div class="ft-nota">
            @if ($factura->reemplazaA)
                <span>Sustituye a <span class="ft-mono">{{ $factura->reemplazaA->numero }}</span> (anulada)</span>
            @endif
            @if ($copias > 0)
                <span>{{ $copias === 1 ? '1 copia impresa' : $copias.' copias impresas' }}</span>
            @endif
            @if ($factura->reintentos > 0)
                <span>Reimpresa {{ $factura->reintentos === 1 ? '1 vez' : $factura->reintentos.' veces' }} porque no salió</span>
            @endif
        </div>
    @endif

    <div class="ft-pie">
        <span class="ft-chip" title="{{ $quien }}">{{ $quien }}</span>

        <div class="ft-total">
            <div @class(['ft-monto', 'anulado' => $anulada])>{{ lempiras($factura->total) }}</div>
            <div class="ft-isv">{{ $factura->isv > 0 ? 'ISV '.lempiras($factura->isv) : 'Exenta' }}</div>
        </div>
    </div>
</div>
