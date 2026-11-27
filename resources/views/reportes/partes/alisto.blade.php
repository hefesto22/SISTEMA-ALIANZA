{{--
    Hoja de alisto: lo que se saca de la bodega para unos pedidos pendientes
    (los de un día o los marcados), para apartarlo antes de validar. Por
    bodega, un renglón por producto con lo que se cobra de cada presentación,
    a qué pedido va cada parte, una casilla "Apartado" y un espacio "Faltó"
    para anotar lo que no se encontró en físico (al validar se pone en
    "¿Faltó algo?" y a los últimos pedidos les sale menos). Los regalos de
    las promociones van aparte, en su propia tabla: a quién va cada uno, de
    qué promoción y la casilla "Apartado" (salen solos con lo que se cobra).
    No lleva lo que dice el sistema: se revisa en físico. Abajo, los pedidos
    y las firmas.
    Hoja: App\Http\Controllers\HojaDeAlistoController.
    Datos: $d['bodegas'] = App\Domain\Pedidos\HojaDeAlisto::datos().
--}}
@php
    /** @var list<array<string, mixed>> $bodegas */
    $bodegas = $d['bodegas'];
    $plural = fn (int $n, string $uno, string $varios): string => $n === 1 ? "1 {$uno}" : number_format($n)." {$varios}";
@endphp

<style>
    .rp .al-prod td { vertical-align: top; }
    .rp .al-sale { width: 6rem; text-align: right; white-space: nowrap; }
    .rp th.al-sale { text-align: right; }
    .rp td.al-sale span { display: block; }
    .rp td.al-sale b { font-size: 1rem; font-weight: 700; }
    .rp td.al-sale small { margin-inline-start: 0.25rem; font-size: 0.7rem; font-weight: 600; color: var(--rp-tenue); }
    .rp .al-para { margin-top: 0.2rem; font-size: 0.72rem; line-height: 1.45; color: var(--rp-tenue); }
    .rp td.al-marca { width: 4.5rem; text-align: center; vertical-align: middle; }
    .rp td.al-falto, .rp th.al-falto { width: 6.5rem; border-left: 1px solid var(--rp-borde); }
    .rp .al-etiqueta { display: inline-block; margin-inline-end: 0.35rem; padding: 0.05rem 0.4rem; border: 1px solid var(--rp-texto); border-radius: 0.25rem; font-size: 0.65rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; vertical-align: 0.1em; }
    .rp .al-caja { display: inline-block; width: 1rem; height: 1rem; border: 1.5px solid var(--rp-texto); border-radius: 0.2rem; vertical-align: middle; }
    .rp .al-firmas { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 2rem 3rem; margin-top: 2.5rem; }
    .rp .al-firma { padding-top: 0.4rem; border-top: 1px solid var(--rp-texto); font-size: 0.8rem; color: var(--rp-tenue); }
    .rp .al-firma b { display: block; color: var(--rp-texto); font-weight: 600; }
    /* Es una lista: va en vertical. */
    @page { size: A4 portrait; margin: 10mm; }
    @media print {
        .rp .al-prod td { padding-top: 7px; padding-bottom: 7px; }
        .rp .al-prod tr { break-inside: avoid; }
        .rp td.al-sale b { font-size: 13px; }
        .rp .al-para { font-size: 9px; }
        .rp .al-etiqueta { font-size: 8px; }
        .rp .al-firmas { break-inside: avoid; margin-top: 22px; gap: 8px 48px; }
        .rp .al-firma { font-size: 10px; }
    }
</style>

<div class="rp">
    @forelse ($bodegas as $b)
        @php
            $productos = $b['productos'];
            $regalos = $b['regalos'];
            $deBodega = count($bodegas) > 1 ? $b['bodega'].' · ' : '';
        @endphp

        <section class="rp-seccion">
            <h2>
                {{ $deBodega }}Lo que se saca de la bodega
                <small>{{ $plural(count($productos), 'producto', 'productos') }} para {{ $plural(count($b['pedidos']), 'pedido', 'pedidos') }}{{ $regalos === [] ? '' : ', sin los regalos (van abajo)' }} · marque «Apartado» al separarlo y anote en «Faltó» lo que no encontró</small>
            </h2>
            @if ($productos === [])
                <div class="rp-vacio">Esos pedidos solo llevan regalos.</div>
            @else
                <div class="rp-tabla">
                    <table class="al-prod">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Producto · para quién</th>
                                <th class="al-sale">Sale</th>
                                <th class="al-marca">Apartado</th>
                                <th class="al-falto">Faltó</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($productos as $p)
                                <tr>
                                    <td class="cod">{{ $p['codigo'] }}</td>
                                    <td class="nombre">
                                        {{ $p['descripcion'] }}
                                        <div class="al-para">{{ implode(' · ', $p['para']) }}</div>
                                    </td>
                                    <td class="al-sale">
                                        @foreach ($p['sale'] as $s)
                                            <span><b>{{ number_format($s['cantidad']) }}</b><small>{{ $s['sigla'] }}</small></span>
                                        @endforeach
                                    </td>
                                    <td class="al-marca"><span class="al-caja"></span></td>
                                    <td class="al-falto"></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @if ($regalos !== [])
            <section class="rp-seccion">
                <h2>
                    {{ $deBodega }}Regalos de promoción
                    <small>{{ $plural(count($regalos), 'producto', 'productos') }} · no se cobran: sepárelos aparte, cada uno con su pedido, y marque «Apartado»</small>
                </h2>
                <div class="rp-tabla">
                    <table class="al-prod">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Regalo · para quién (promoción)</th>
                                <th class="al-sale">Sale</th>
                                <th class="al-marca">Apartado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($regalos as $p)
                                <tr>
                                    <td class="cod">{{ $p['codigo'] }}</td>
                                    <td class="nombre">
                                        <span class="al-etiqueta">Regalo</span>{{ $p['descripcion'] }}
                                        <div class="al-para">{{ implode(' · ', $p['para']) }}</div>
                                    </td>
                                    <td class="al-sale">
                                        @foreach ($p['sale'] as $s)
                                            <span><b>{{ number_format($s['cantidad']) }}</b><small>{{ $s['sigla'] }}</small></span>
                                        @endforeach
                                    </td>
                                    <td class="al-marca"><span class="al-caja"></span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <section class="rp-seccion">
            <h2>Pedidos <small>{{ $plural(count($b['pedidos']), 'pedido', 'pedidos') }} por L {{ number_format($b['total'], 2) }}, del más viejo al más nuevo (así se reparte lo que falte)</small></h2>
            <div class="rp-tabla">
                <table>
                    <thead>
                        <tr>
                            <th>Pedido</th>
                            <th>Cliente</th>
                            <th>Fecha</th>
                            <th class="n">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($b['pedidos'] as $pedido)
                            <tr>
                                <td class="dia">{{ $pedido['numero'] }}</td>
                                <td class="nombre">{{ $pedido['cliente'] }}</td>
                                <td class="dia">{{ $pedido['fecha'] }}</td>
                                <td class="n">{{ number_format($pedido['total'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @empty
        <div class="rp-vacio">Esos pedidos ya no están pendientes: no hay nada que alistar.</div>
    @endforelse

    @if ($bodegas !== [])
        <div class="al-firmas">
            <div class="al-firma"><b>Alistó</b>Nombre y firma</div>
            <div class="al-firma"><b>Revisó</b>Nombre y firma</div>
        </div>
    @endif
</div>
