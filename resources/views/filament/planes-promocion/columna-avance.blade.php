{{--
    Columna "Regalo autorizado" de la lista de planes (PlanPromocionResource):
    cuánto se ha regalado (entregado + comprometido) de lo autorizado, la
    barra y lo entregado al costo. Datos: AvancePlan::de()['totales'].
--}}
@php
    /** @var array{tope: int, entregadas: int, comprometidas: int, quedan: int, usado: float, autorizado: float, invertido: float, comprometido: float} $t */
    $t = $getState();
    $usadas = $t['entregadas'] + $t['comprometidas'];
    $entregado = $t['tope'] > 0 ? min(100, $t['entregadas'] / $t['tope'] * 100) : 0;
    $comprometido = $t['tope'] > 0 ? min(100 - $entregado, $t['comprometidas'] / $t['tope'] * 100) : 0;
@endphp

<div style="display: grid; gap: 0.3rem; min-width: 12rem; padding-block: 0.25rem; font-size: 0.8rem;">
    <div style="display: flex; justify-content: space-between; gap: 0.75rem; font-variant-numeric: tabular-nums;">
        <span><b>{{ number_format($usadas) }}</b> de {{ number_format($t['tope']) }} UN</span>
        <span style="color: var(--gray-500);">{{ rtrim(rtrim(number_format($t['usado'], 1), '0'), '.') }} %</span>
    </div>
    <div style="display: flex; height: 0.4rem; border-radius: 999px; overflow: hidden; background: color-mix(in oklab, var(--gray-500) 22%, transparent);" role="img" aria-label="{{ number_format($usadas) }} de {{ number_format($t['tope']) }} unidades de regalo">
        <span style="width: {{ $entregado }}%; background: var(--primary-600);"></span>
        <span style="width: {{ $comprometido }}%; background: color-mix(in oklab, var(--primary-500) 45%, transparent);"></span>
    </div>
    <div style="color: var(--gray-500); font-variant-numeric: tabular-nums;">{{ lempiras($t['invertido']) }} de {{ lempiras($t['autorizado']) }} al costo</div>
</div>
