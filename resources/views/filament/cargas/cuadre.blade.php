{{--
    El cuadre en vivo al liquidar una carga (App\Filament\Resources\Cargas\Pages\Liquidacion):
    arriba la diferencia en grande; debajo, de dónde sale lo que debía traer y
    lo que trajo.
    Datos: $c = App\Domain\Cargas\CuentaCarga::de(); $devoluciones (las de crédito, en texto).
--}}
@php
    /** @var array<string, int|float> $c */
    $devoluciones ??= [];
    $diferencia = (float) $c['diferencia'];
    $cuadra = abs($diferencia) < 0.005;
    $tono = $cuadra ? 'bien' : ($diferencia < 0 ? 'falta' : 'sobra');
@endphp

<style>
    .cq { --cq-tenue: var(--gray-500); --cq-borde: var(--gray-200); --cq-bien: var(--success-600); --cq-falta: var(--danger-600); --cq-sobra: var(--warning-600); display: grid; gap: 0.9rem; font-variant-numeric: tabular-nums; }
    .dark .cq { --cq-tenue: var(--gray-400); --cq-borde: rgb(255 255 255 / 0.1); --cq-bien: var(--success-400); --cq-falta: var(--danger-400); --cq-sobra: var(--warning-400); }
    .cq-grande { display: grid; gap: 0.15rem; padding: 0.9rem 1rem; border-radius: 0.75rem; border: 1px solid color-mix(in oklab, var(--cq-color) 40%, transparent); background: color-mix(in oklab, var(--cq-color) 10%, transparent); }
    .cq-grande.bien { --cq-color: var(--cq-bien); }
    .cq-grande.falta { --cq-color: var(--cq-falta); }
    .cq-grande.sobra { --cq-color: var(--cq-sobra); }
    .cq-grande span { font-size: 0.72rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--cq-tenue); }
    .cq-grande strong { font-size: 1.6rem; line-height: 1.15; font-weight: 700; color: var(--cq-color); }
    .cq-grande small { font-size: 0.8rem; color: var(--cq-tenue); }
    .cq-lista { margin: 0; padding: 0; list-style: none; display: grid; gap: 0.3rem; font-size: 0.875rem; }
    .cq-lista li { display: flex; justify-content: space-between; gap: 0.75rem; }
    .cq-lista li span { color: var(--cq-tenue); }
    .cq-lista li b { font-weight: 600; white-space: nowrap; }
    .cq-lista li.suma { margin-top: 0.2rem; padding-top: 0.4rem; border-top: 1px solid var(--cq-borde); }
    .cq-lista li.suma span, .cq-lista li.suma b { color: inherit; font-weight: 700; }
    .cq-titulo { margin: 0 0 0.3rem; font-size: 0.72rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--cq-tenue); }
    .cq-nota { margin: 0; font-size: 0.8rem; line-height: 1.45; color: var(--cq-tenue); }
</style>

<div class="cq" aria-live="polite">
    <div @class(['cq-grande', $tono])>
        <span>Diferencia</span>
        <strong>{{ \App\Domain\Cargas\CuentaCarga::texto($diferencia) }}</strong>
        <small>
            Debía traer {{ lempiras((float) $c['debe_traer']) }} · trajo {{ lempiras((float) $c['trajo']) }}
        </small>
    </div>

    <div>
        <p class="cq-titulo">Lo que debía traer</p>
        <ul class="cq-lista">
            <li><span>Salió con</span><b>{{ lempiras((float) $c['salio']) }}</b></li>
            <li><span>No se entregó</span><b>{{ (float) $c['no_entregado'] > 0 ? '− '.lempiras((float) $c['no_entregado']) : lempiras(0) }}</b></li>
            <li><span>Devoluciones con crédito</span><b>{{ (float) $c['devuelto'] > 0 ? '− '.lempiras((float) $c['devuelto']) : lempiras(0) }}</b></li>
            <li class="suma"><span>Debía traer</span><b>{{ lempiras((float) $c['debe_traer']) }}</b></li>
        </ul>
    </div>

    <div>
        <p class="cq-titulo">Lo que trajo</p>
        <ul class="cq-lista">
            <li><span>Efectivo</span><b>{{ lempiras((float) $c['efectivo']) }}</b></li>
            <li><span>Cheques</span><b>{{ lempiras((float) $c['cheques']) }}</b></li>
            <li><span>Transferencias</span><b>{{ lempiras((float) $c['transferencias']) }}</b></li>
            <li class="suma"><span>Trajo</span><b>{{ lempiras((float) $c['trajo']) }}</b></li>
        </ul>
    </div>

    @if ($devoluciones !== [])
        <p class="cq-nota">Devoluciones que se descuentan: {{ implode('; ', $devoluciones) }}.</p>
    @endif

    @if ((float) $c['anuladas'] > 0)
        <p class="cq-nota">Las facturas anuladas al corregir el pedido no se cobran: va la que las sustituye.</p>
    @endif
</div>
