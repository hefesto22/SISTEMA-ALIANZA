{{--
    Hoja de carga: lo que se sube al camión, un renglón por producto con
    cuántas cajas (CJ) y cuántas unidades (UN), como en la factura (con los
    regalos de las promociones y lo de los cambios sumados), los cambios que
    lleva (a quién se le da cada uno) y las facturas. En papel, una casilla
    para ir marcando lo que se subió, la firma de cada cliente que recibe un
    cambio y las firmas de bodega y de quien sale. En pantalla (la carga en
    ruta) va sin casillas ni firmas.
    Hoja: App\Http\Controllers\ImprimirCargaController (?hoja=carga).
    Datos: $d = App\Domain\Cargas\HojaDeCarga::datos(); $pantalla.
--}}
@php
    /** @var array<string, mixed> $d */
    $pantalla ??= false;
    $productos = $d['productos'];
    $cajas = array_sum(array_map(fn (array $p): int => array_sum(array_column($p['cajas'], 'cantidad')), $productos));
    $unidades = array_sum(array_column($productos, 'unidades'));
    $plural = fn (int $n, string $uno, string $varios): string => $n === 1 ? "1 {$uno}" : number_format($n)." {$varios}";
    $cambios = $d['cambios'];
@endphp

<style>
    .rp .cg-prod td { vertical-align: top; }
    .rp .cg-cant { width: 5.5rem; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .rp th.cg-cant { text-align: right; }
    .rp td.cg-cant b { font-size: 1rem; font-weight: 700; }
    .rp td.cg-cant small { margin-inline-start: 0.2rem; font-size: 0.7rem; font-weight: 600; color: var(--rp-tenue); }
    .rp td.cg-cant span { display: block; }
    .rp .cg-prod td.marca { vertical-align: middle; }
    .rp .cg-prod tfoot td { font-weight: 700; }
    .rp .cg-cambios td { vertical-align: top; }
    .rp .cg-cambios ul { margin: 0; padding: 0; list-style: none; }
    .rp .cg-cambios li + li { margin-top: 0.15rem; }
    .rp .cg-cambios td.dia small { display: block; }
    .rp .cg-cambios td.recibio, .rp .cg-cambios th.recibio { width: 11rem; }
    @media print {
        .rp .cg-prod td { padding-top: 7px; padding-bottom: 7px; }
        .rp td.cg-cant b { font-size: 13px; }
        .rp .cg-prod tr { break-inside: avoid; }
        .rp .cg-cambios td { padding-top: 10px; padding-bottom: 10px; }
        .rp .cg-cambios tr { break-inside: avoid; }
    }
</style>

<div class="rp">
    @include('reportes.partes.carga-datos', ['d' => $d, 'pantalla' => $pantalla])

    <section class="rp-seccion">
        <h2>Lo que se sube al camión <small>{{ $plural(count($productos), 'producto', 'productos') }} · {{ $plural($cajas, 'caja', 'cajas') }} y {{ $plural($unidades, 'unidad', 'unidades') }}, sumado de las facturas{{ $cambios === [] ? '' : ' y los cambios' }} que lleva</small></h2>
        @if ($productos === [])
            <div class="rp-vacio">La carga no lleva facturas vigentes ni cambios.</div>
        @else
            <div class="rp-tabla">
                <table class="cg-prod">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Producto</th>
                            <th class="cg-cant">CJ</th>
                            <th class="cg-cant">UN</th>
                            @unless ($pantalla)
                                <th class="marca">Listo</th>
                            @endunless
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($productos as $p)
                            @php
                                // La sigla junto a la cantidad solo si no se sobreentiende (FD6, cajas de dos tamaños, BT).
                                $conSigla = count($p['cajas']) > 1 || ($p['cajas'] !== [] && ! str_starts_with($p['cajas'][0]['sigla'], 'CJ'));
                            @endphp
                            <tr>
                                <td class="cod">{{ $p['codigo'] }}</td>
                                <td class="nombre">
                                    {{ $p['descripcion'] }}
                                    @if ($p['regalo'] !== null)
                                        <small>Incluye de regalo: {{ $p['regalo'] }}</small>
                                    @endif
                                    @if ($p['cambio'] !== null)
                                        <small>Incluye de cambios: {{ $p['cambio'] }}</small>
                                    @endif
                                </td>
                                <td class="cg-cant">
                                    @foreach ($p['cajas'] as $caja)
                                        <span><b>{{ number_format($caja['cantidad']) }}</b>@if ($conSigla)<small>{{ $caja['sigla'] }}</small>@endif</span>
                                    @endforeach
                                </td>
                                <td class="cg-cant">
                                    @if ($p['unidades'] > 0)
                                        <span><b>{{ number_format($p['unidades']) }}</b>@if ($p['suelta'] !== 'UN')<small>{{ $p['suelta'] }}</small>@endif</span>
                                    @endif
                                </td>
                                @unless ($pantalla)
                                    <td class="marca"><span class="caja"></span></td>
                                @endunless
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2">Total</td>
                            <td class="cg-cant"><b>{{ number_format($cajas) }}</b></td>
                            <td class="cg-cant"><b>{{ number_format($unidades) }}</b></td>
                            @unless ($pantalla)
                                <td></td>
                            @endunless
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </section>

    @if ($cambios !== [])
        <section class="rp-seccion">
            <h2>Cambios que lleva <small>{{ $plural(count($cambios), 'cambio', 'cambios') }} por cambio: a quién se le da cada uno (ya van sumados arriba)</small></h2>
            <div class="rp-tabla">
                <table class="cg-cambios">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Qué se le lleva</th>
                            <th>Devolución</th>
                            @unless ($pantalla)
                                <th class="recibio">Recibió (firma)</th>
                            @endunless
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cambios as $c)
                            <tr>
                                <td class="nombre">
                                    {{ $c['cliente'] }}
                                    <small>{{ collect([$c['municipio'], $c['ruta']])->filter()->implode(' · ') }}</small>
                                    @if ($c['nota'] !== null)
                                        <small class="aviso">{{ $c['nota'] }}</small>
                                    @endif
                                </td>
                                <td>
                                    <ul>
                                        @foreach ($c['lleva'] as $lleva)
                                            <li>{{ $lleva }}</li>
                                        @endforeach
                                    </ul>
                                </td>
                                <td class="dia">{{ $c['devolucion'] }}@if ($c['factura'] !== null)<small class="cod">Factura {{ $c['factura'] }}</small>@endif</td>
                                @unless ($pantalla)
                                    <td class="recibio"></td>
                                @endunless
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="rp-seccion">
        <h2>Facturas que lleva <small>en el orden en que se cargaron</small></h2>
        <div class="rp-tabla">
            <table>
                <thead>
                    <tr>
                        <th>Factura</th>
                        <th>Cliente</th>
                        <th>Ruta · vendió</th>
                        <th class="n">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($d['facturas'] as $f)
                        <tr @class(['tenue' => ! $f['vigente']])>
                            <td class="dia">{{ $f['corto'] }}</td>
                            <td class="nombre">
                                {{ $f['cliente'] }}
                                <small>{{ $f['municipio'] !== null ? $f['municipio'].' · ' : '' }}Pedido {{ $f['pedido'] }}</small>
                                @if ($f['nota'] !== null)
                                    <small class="aviso">{{ $f['nota'] }}</small>
                                @endif
                            </td>
                            <td>@if ($f['ruta'] !== null)<b>{{ $f['ruta'] }}</b> · @endif{{ $f['vendio'] }}</td>
                            <td class="n monto">{{ number_format($f['total'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3">Sale con ({{ $d['cuantas'] === 1 ? '1 factura' : $d['cuantas'].' facturas' }})</td>
                        <td class="n">{{ number_format($d['total'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>

    @unless ($pantalla)
        <div class="cg-firmas">
            <div class="cg-firma"><b>Entregó la carga</b>Bodega · nombre y firma</div>
            <div class="cg-firma"><b>Recibió la carga</b>{{ $d['sale'] }} · firma</div>
        </div>
    @endunless
</div>
