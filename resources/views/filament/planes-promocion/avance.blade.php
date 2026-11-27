{{--
    Ver un plan del proveedor (PlanPromocionResource::infolist): cuánto se ha
    regalado de lo autorizado, en unidades y al costo, y línea por línea.
      - Entregado: en pedidos ya impresos (facturados).
      - Comprometido: en pedidos sin imprimir (se libera si se anula o se corrige).
      - Queda: lo que todavía se puede regalar.
    "Al costo" compara el % que da la mecánica con el de la hoja; si regala
    bastante más, se marca para revisar la hoja.
    Datos: App\Domain\Promociones\AvancePlan::de().
--}}
@php
    /** @var \App\Models\PlanPromocion $plan */
    /** @var array{lineas: list<array<string, mixed>>, totales: array<string, mixed>} $avance */
    $t = $avance['totales'];
    $lineas = $avance['lineas'];
    $pct = fn (float $valor): string => rtrim(rtrim(number_format($valor, 1), '0'), '.').' %';
    $ancho = fn (int $parte, int $tope): float => $tope > 0 ? round(min(100, $parte / $tope * 100), 2) : 0.0;
@endphp

<style>
    .ap { --ap-tenue: var(--gray-500); --ap-borde: var(--gray-200); --ap-fondo: #fff; --ap-suave: var(--gray-50); --ap-pista: color-mix(in oklab, var(--gray-500) 20%, transparent); display: grid; gap: 1rem; min-width: 0; font-size: 0.875rem; }
    .dark .ap { --ap-tenue: var(--gray-400); --ap-borde: rgb(255 255 255 / 0.1); --ap-fondo: var(--gray-900); --ap-suave: rgb(255 255 255 / 0.03); }
    .ap-kpis { display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); }
    .ap-kpi { display: grid; gap: 0.2rem; padding: 0.9rem 1rem; border-radius: 0.75rem; border: 1px solid var(--ap-borde); background: var(--ap-fondo); }
    .ap-kpi span { font-size: 0.75rem; font-weight: 600; color: var(--ap-tenue); }
    .ap-kpi strong { font-size: 1.5rem; font-weight: 700; line-height: 1.2; font-variant-numeric: tabular-nums; }
    .ap-kpi small { font-size: 0.78rem; color: var(--ap-tenue); font-variant-numeric: tabular-nums; }
    .ap-general { display: grid; gap: 0.5rem; padding: 0.9rem 1rem; border-radius: 0.75rem; border: 1px solid var(--ap-borde); background: var(--ap-fondo); }
    .ap-general-arriba { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 0.5rem 1rem; }
    .ap-general-arriba b { font-variant-numeric: tabular-nums; }
    .ap-leyenda { display: flex; flex-wrap: wrap; gap: 0.25rem 1rem; font-size: 0.78rem; color: var(--ap-tenue); }
    .ap-leyenda i { display: inline-block; width: 0.7rem; height: 0.7rem; margin-inline-end: 0.35rem; border-radius: 0.2rem; vertical-align: -0.05rem; }
    .ap-barra { display: flex; height: 0.55rem; border-radius: 999px; overflow: hidden; background: var(--ap-pista); }
    .ap-barra.ap-chica { height: 0.4rem; }
    .ap-entregado { background: var(--primary-600); }
    .ap-comprometido { background: color-mix(in oklab, var(--primary-500) 45%, transparent); }
    .ap-hoja { display: inline-flex; align-items: center; gap: 0.4rem; font-weight: 600; color: var(--primary-600); }
    .dark .ap-hoja { color: var(--primary-400); }
    .ap-hoja svg { width: 1rem; height: 1rem; }
    .ap-tabla-caja { border-radius: 0.75rem; border: 1px solid var(--ap-borde); background: var(--ap-fondo); overflow-x: auto; }
    .ap-tabla { width: 100%; border-collapse: collapse; }
    .ap-tabla th { padding: 0.55rem 0.75rem; text-align: start; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; color: var(--ap-tenue); background: var(--ap-suave); border-bottom: 1px solid var(--ap-borde); white-space: nowrap; }
    .ap-tabla td { padding: 0.65rem 0.75rem; vertical-align: top; border-bottom: 1px solid var(--ap-borde); }
    .ap-tabla tr:last-child td { border-bottom: 0; }
    .ap-tabla .ap-num { text-align: end; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .ap-codigo { font-size: 0.72rem; font-weight: 700; padding: 0.05rem 0.4rem; border-radius: 0.375rem; color: var(--primary-600); outline: 1px solid color-mix(in oklab, var(--primary-500) 40%, transparent); white-space: nowrap; }
    .dark .ap-codigo { color: var(--primary-400); }
    .ap-mecanica { display: block; margin-top: 0.35rem; font-size: 1rem; font-weight: 800; font-variant-numeric: tabular-nums; }
    .ap-productos { display: grid; gap: 0.1rem; min-width: 14rem; }
    .ap-productos span { line-height: 1.35; }
    .ap-tenue { color: var(--ap-tenue); font-size: 0.78rem; }
    .ap-pill { display: inline-flex; align-items: center; gap: 0.25rem; margin-top: 0.3rem; padding: 0.05rem 0.45rem; border-radius: 999px; font-size: 0.7rem; font-weight: 700; white-space: nowrap; }
    .ap-pill svg { width: 0.85rem; height: 0.85rem; }
    .ap-pill-agotada { color: var(--danger-700); background: color-mix(in oklab, var(--danger-500) 14%, transparent); }
    .dark .ap-pill-agotada { color: var(--danger-400); }
    .ap-pill-inactiva { color: var(--ap-tenue); background: var(--ap-pista); }
    .ap-pill-revisar { color: var(--warning-700); background: color-mix(in oklab, var(--warning-500) 14%, transparent); }
    .dark .ap-pill-revisar { color: var(--warning-400); }
    .ap-avance { display: grid; gap: 0.3rem; min-width: 9rem; }
    .ap-nota { margin: 0; font-size: 0.78rem; color: var(--ap-tenue); }
</style>

<div class="ap">
    <div class="ap-kpis">
        <div class="ap-kpi">
            <span>Regalo autorizado</span>
            <strong>{{ number_format($t['tope']) }} UN</strong>
            <small>{{ lempiras($t['autorizado']) }} al costo</small>
        </div>
        <div class="ap-kpi">
            <span>Entregado</span>
            <strong>{{ number_format($t['entregadas']) }} UN</strong>
            <small>{{ lempiras($t['invertido']) }} al costo · en facturas</small>
        </div>
        <div class="ap-kpi">
            <span>Comprometido</span>
            <strong>{{ number_format($t['comprometidas']) }} UN</strong>
            <small>{{ lempiras($t['comprometido']) }} · pedidos sin imprimir</small>
        </div>
        <div class="ap-kpi">
            <span>Queda por regalar</span>
            <strong>{{ number_format($t['quedan']) }} UN</strong>
            <small>{{ lempiras(max(0, $t['autorizado'] - $t['invertido'] - $t['comprometido'])) }} al costo</small>
        </div>
    </div>

    <div class="ap-general">
        <div class="ap-general-arriba">
            <span>Se ha usado <b>{{ $pct($t['usado']) }}</b> de lo autorizado</span>
            @if ($hoja)
                <a class="ap-hoja" href="{{ $hoja }}" target="_blank" rel="noopener">{{ svg('heroicon-m-document-text') }} Ver la hoja del proveedor</a>
            @endif
        </div>
        <div class="ap-barra" role="img" aria-label="{{ number_format($t['entregadas']) }} entregadas y {{ number_format($t['comprometidas']) }} comprometidas de {{ number_format($t['tope']) }} autorizadas">
            <span class="ap-entregado" style="width: {{ $ancho($t['entregadas'], $t['tope']) }}%"></span>
            <span class="ap-comprometido" style="width: {{ min(100 - $ancho($t['entregadas'], $t['tope']), $ancho($t['comprometidas'], $t['tope'])) }}%"></span>
        </div>
        <div class="ap-leyenda">
            <span><i class="ap-entregado"></i>Entregado</span>
            <span><i class="ap-comprometido"></i>Comprometido</span>
            <span><i style="background: var(--ap-pista)"></i>Queda</span>
        </div>
        @if (filled($plan->notas))
            <p class="ap-nota">{{ $plan->notas }}</p>
        @endif
    </div>

    <div class="ap-tabla-caja">
        <table class="ap-tabla">
            <thead>
                <tr>
                    <th>Línea</th>
                    <th>El cliente compra</th>
                    <th>Se regala</th>
                    <th class="ap-num">Tope</th>
                    <th class="ap-num">Entregado</th>
                    <th class="ap-num">Comprometido</th>
                    <th class="ap-num">Queda</th>
                    <th>Avance</th>
                    <th class="ap-num">Al costo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lineas as $l)
                    <tr>
                        <td>
                            <span class="ap-codigo">{{ $l['codigo'] }}</span>
                            <span class="ap-mecanica">{{ $l['mecanica'] }}</span>
                        </td>
                        <td>
                            <div class="ap-productos">
                                @foreach ($l['productos'] as $producto)
                                    <span>{{ $producto }}</span>
                                @endforeach
                                @if (count($l['productos']) > 1)
                                    <span class="ap-tenue">Cada uno cuenta aparte ({{ explode('+', $l['mecanica'])[0] }} unidades del mismo producto); no se mezclan.</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            {{ $l['regalo'] }}
                            @if (! $l['activa'])
                                <div><span class="ap-pill ap-pill-inactiva">Inactiva</span></div>
                            @elseif ($l['tope'] > 0 && $l['quedan'] === 0)
                                <div><span class="ap-pill ap-pill-agotada">{{ svg('heroicon-m-no-symbol') }} Agotada</span></div>
                            @elseif ($l['tope'] === 0)
                                <div><span class="ap-pill ap-pill-inactiva">Sin unidades autorizadas</span></div>
                            @endif
                        </td>
                        <td class="ap-num">{{ number_format($l['tope']) }} {{ $l['sigla'] }}</td>
                        <td class="ap-num">{{ number_format($l['entregadas']) }}</td>
                        <td class="ap-num">{{ number_format($l['comprometidas']) }}</td>
                        <td class="ap-num"><b>{{ number_format($l['quedan']) }}</b></td>
                        <td>
                            <div class="ap-avance">
                                <div class="ap-barra ap-chica">
                                    <span class="ap-entregado" style="width: {{ $ancho($l['entregadas'], $l['tope']) }}%"></span>
                                    <span class="ap-comprometido" style="width: {{ min(100 - $ancho($l['entregadas'], $l['tope']), $ancho($l['comprometidas'], $l['tope'])) }}%"></span>
                                </div>
                                <span class="ap-tenue">{{ $pct($l['usado']) }}</span>
                            </div>
                        </td>
                        <td class="ap-num">
                            <div>{{ $l['porcentaje'] !== null ? \App\Models\Promocion::porcentajeTexto($l['porcentaje']) : '—' }}@if ($l['porcentaje_autorizado'] !== null)<span class="ap-tenue"> · hoja {{ \App\Models\Promocion::porcentajeTexto($l['porcentaje_autorizado']) }}</span>@endif</div>
                            <div class="ap-tenue">{{ lempiras($l['invertido']) }} de {{ lempiras($l['autorizado']) }}</div>
                            @if ($l['excede'])
                                <div><span class="ap-pill ap-pill-revisar">{{ svg('heroicon-m-exclamation-triangle') }} Regala más que la hoja</span></div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="ap-nota">Entregado: en pedidos ya impresos. Comprometido: en pedidos sin imprimir; si se anulan o se corrigen, regresa. Los lempiras son al costo de hoy del regalo: lo que el proveedor le reconoce. Cuando una línea se agota, la preventa ya no da ese regalo.</p>
</div>
