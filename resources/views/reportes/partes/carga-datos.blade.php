{{--
    Arriba de las hojas de una carga (carga, cobro y liquidación) y de la
    carga en pantalla: quién sale, el camión, cuántas facturas y con cuánto
    sale. Trae los estilos que comparten las tres hojas.
    Datos: $d = App\Domain\Cargas\HojaDeCarga::datos(); $pantalla (en el panel, sin firmas).
--}}
@php
    /** @var array<string, mixed> $d */
    $pantalla ??= false;
    $c = $d['cuenta'] ?? null;
    $entregadas = count(array_filter($d['facturas'], fn (array $f): bool => $f['estado'] === \App\Enums\EntregaFactura::Entregada));
@endphp

<style>
    .rp { --rp-bien: #15803d; --rp-sobra: #b45309; }
    .fi-main .rp { --rp-bien: var(--success-600); --rp-sobra: var(--warning-600); }
    .dark .fi-main .rp { --rp-bien: var(--success-400); --rp-sobra: var(--warning-400); }
    .rp .bien { color: var(--rp-bien); }
    .rp .sobra { color: var(--rp-sobra); }
    .rp tr.tenue td { color: var(--rp-tenue); }
    .rp tr.tenue td.monto { text-decoration: line-through; }
    .rp td.nombre small.aviso { color: var(--rp-mal); }
    .rp td.blanco, .rp th.blanco { min-width: 6.5rem; border-left: 1px solid var(--rp-borde); }
    .rp td.marca { width: 2.5rem; text-align: center; }
    .rp .caja { display: inline-block; width: 0.95rem; height: 0.95rem; border: 1.5px solid var(--rp-texto); border-radius: 0.2rem; vertical-align: middle; }
    .rp .cg-renglones td { padding-top: 0.75rem; padding-bottom: 0.75rem; }
    .rp .cg-firmas { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 2rem 3rem; margin-top: 2.5rem; }
    .rp .cg-firma { padding-top: 0.4rem; border-top: 1px solid var(--rp-texto); font-size: 0.8rem; color: var(--rp-tenue); }
    .rp .cg-firma b { display: block; color: var(--rp-texto); font-weight: 600; }
    .rp .cg-cuadre { display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 0.75rem; }
    .rp .cg-cuenta { padding: 0.85rem 1rem; font-size: 0.875rem; border: 1px solid var(--rp-borde); border-radius: 0.75rem; background: var(--rp-tarjeta); }
    .rp .cg-cuenta ul { margin: 0; padding: 0; list-style: none; display: grid; gap: 0.35rem; }
    .rp .cg-cuenta li { display: flex; justify-content: space-between; gap: 1rem; }
    .rp .cg-cuenta li span { color: var(--rp-tenue); }
    .rp .cg-cuenta li b { font-weight: 600; white-space: nowrap; }
    .rp .cg-cuenta li.cg-renglones { padding-block: 0.45rem; }
    .rp .cg-cuenta li.suma { margin-top: 0.25rem; padding-top: 0.45rem; border-top: 1px solid var(--rp-borde); }
    .rp .cg-cuenta li.suma span, .rp .cg-cuenta li.suma b { color: var(--rp-texto); font-weight: 700; }
    .rp .cg-cuenta h3 { margin: 0 0 0.45rem; font-size: 0.72rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--rp-tenue); }
    .rp .cg-nota { padding: 0.7rem 0.9rem; font-size: 0.875rem; border: 1px solid var(--rp-borde); border-radius: 0.6rem; background: var(--rp-suave); }
    .rp .cg-nota b { display: block; margin-bottom: 0.15rem; font-size: 0.72rem; letter-spacing: 0.04em; text-transform: uppercase; color: var(--rp-tenue); }
    /* Las hojas de la carga son listas: van en vertical. */
    @page { size: A4 portrait; margin: 10mm; }
    @media print {
        .rp .cg-firmas { break-inside: avoid; margin-top: 22px; gap: 8px 48px; }
        .rp .cg-firma { font-size: 10px; }
        .rp .cg-cuenta { padding: 8px 12px; font-size: 11px; }
        .rp .cg-renglones td { padding-top: 9px; padding-bottom: 9px; }
    }
</style>

<section class="rp-kpis">
    <div class="rp-kpi">
        <span>{{ $d['liquidada'] ? 'Salió' : 'Sale' }}</span>
        <strong>{{ $d['sale'] }}</strong>
        <small>{{ $d['camion'] !== null ? 'Camión '.$d['camion'] : 'Sin camión anotado' }}</small>
    </div>
    <div class="rp-kpi">
        <span>Facturas</span>
        <strong>{{ number_format($d['cuantas']) }}</strong>
        <small>
            @if ($d['liquidada'])
                {{ $entregadas === 1 ? '1 entregada' : number_format($entregadas).' entregadas' }}
            @else
                {{ $d['bodega'] }}
            @endif
        </small>
    </div>
    @if ($c === null)
        <div class="rp-kpi principal">
            <span>Sale con</span>
            <strong>{{ lempiras($d['total']) }}</strong>
            <small>lo que debe regresar en dinero</small>
        </div>
    @else
        <div class="rp-kpi principal">
            <span>Debía traer</span>
            <strong>{{ lempiras($c['debe_traer']) }}</strong>
            <small>salió con {{ lempiras($c['salio']) }}</small>
        </div>
        <div class="rp-kpi">
            <span>Trajo</span>
            <strong>{{ lempiras($c['trajo']) }}</strong>
            <small>efectivo, cheques y transferencias</small>
        </div>
        <div class="rp-kpi">
            <span>Diferencia</span>
            <strong @class(['bien' => $c['color'] === 'success', 'sobra' => $c['color'] === 'warning', 'neg' => $c['color'] === 'danger'])>{{ $c['texto'] }}</strong>
            <small>liquidada el {{ $d['liquidada_el'] }}</small>
        </div>
    @endif
</section>
