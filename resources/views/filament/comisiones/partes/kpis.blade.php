{{--
    Tablero de KPIs: resumen contra el periodo anterior, venta de cada día,
    ranking de vendedores (con su cobertura de clientes), grupos de
    comisión, productos más vendidos y zonas.
    $d = App\Filament\Pages\KpisVentas::datos().
--}}
@php
    $r = $d['resumen'];
    $pct = fn (?float $valor, int $decimales = 0): string => $valor === null ? '—' : number_format($valor * 100, $decimales).'%';
    $variacion = function (?float $valor) use ($pct): string {
        if ($valor === null) {
            return '<b class="cm-var">sin dato anterior</b>';
        }

        $clase = $valor > 0.0005 ? 'sube' : ($valor < -0.0005 ? 'baja' : '');
        $signo = $valor > 0 ? '+' : '';

        return '<b class="cm-var '.$clase.'">'.$signo.$pct($valor, 1).'</b>';
    };
    $maxDia = max(array_map(fn (array $dia): float => $dia['venta'], $d['dias']) ?: [0.0]);
@endphp

<div class="rp" wire:loading.delay.class="rp-cargando">
    <div class="rp-encabezado" style="margin: 0">{{ $d['periodo'] }} · {{ $d['alcance'] }} · sobre lo facturado, sin ISV</div>

    @if ($r['facturas'] === 0)
        <div class="rp-vacio">No hay facturas vigentes del {{ $d['periodo'] }} en {{ $d['alcance'] === 'Todas las bodegas' ? 'ninguna bodega' : $d['alcance'] }}.</div>
    @else
        <section class="rp-kpis">
            <div class="rp-kpi principal">
                <span>Venta neta</span>
                <strong>{{ lempiras($r['venta']) }}</strong>
                <small>{!! $variacion($r['variacion']) !!} contra el periodo anterior ({{ lempiras($r['anterior'], 0) }})</small>
            </div>
            <div class="rp-kpi">
                <span>Ticket promedio</span>
                <strong>{{ $r['ticket'] === null ? '—' : lempiras($r['ticket']) }}</strong>
                <small>por factura</small>
            </div>
            <div class="rp-kpi">
                <span>Facturas</span>
                <strong>{{ number_format($r['facturas']) }}</strong>
                <small>vigentes</small>
            </div>
            <div class="rp-kpi">
                <span>Clientes atendidos</span>
                <strong>{{ number_format($r['clientes']) }}</strong>
                <small>{{ $r['vendedores'] === 1 ? '1 vendedor' : $r['vendedores'].' vendedores' }}</small>
            </div>
        </section>

        @if (count($d['dias']) > 1)
            <section class="rp-seccion">
                <h2>Venta por día</h2>
                <div class="cm-dias" role="img" aria-label="Venta de cada día del periodo">
                    @foreach ($d['dias'] as $dia)
                        <i @class(['cero' => $dia['venta'] <= 0]) style="height: {{ $maxDia > 0 ? max(2, round($dia['venta'] / $maxDia * 100, 1)) : 2 }}%" title="{{ $dia['dia']->format('d/m') }}: {{ lempiras($dia['venta']) }}"></i>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($d['vendedores'] !== [])
            <section class="rp-seccion">
                <h2>Vendedores <small>cobertura = clientes atendidos ÷ clientes activos de sus rutas</small></h2>
                <div class="rp-tabla">
                    <table>
                        <thead>
                            <tr>
                                <th>Vendedor</th>
                                <th class="n">Venta</th>
                                <th>Participación</th>
                                <th class="n">Vs. anterior</th>
                                <th class="n">Facturas</th>
                                <th class="n">Ticket</th>
                                <th class="n">Clientes</th>
                                <th class="n">Cobertura</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['vendedores'] as $v)
                                <tr>
                                    <td class="nombre">{{ $v['nombre'] }}@if ($v['codigo'])<small class="cod">{{ $v['codigo'] }}</small>@endif</td>
                                    <td class="n"><strong>{{ lempiras($v['venta']) }}</strong></td>
                                    <td><div class="cm-parte" title="{{ $pct($v['participacion'], 1) }}"><i style="width: {{ round(($v['participacion'] ?? 0) * 100, 1) }}%"></i></div></td>
                                    <td class="n">{!! $variacion($v['variacion']) !!}</td>
                                    <td class="n">{{ number_format($v['facturas']) }}</td>
                                    <td class="n">{{ $v['ticket'] === null ? '—' : lempiras($v['ticket']) }}</td>
                                    <td class="n">{{ number_format($v['clientes']) }}</td>
                                    <td class="n">{{ $v['cobertura'] === null ? '—' : $pct($v['cobertura']).' de '.$v['cartera'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <div class="cm-columnas">
            <section class="rp-seccion">
                <h2>Por grupo de comisión</h2>
                <div class="rp-tabla">
                    <table>
                        <thead><tr><th>Grupo</th><th class="n">Venta</th><th>Participación</th></tr></thead>
                        <tbody>
                            @foreach ($d['grupos'] as $g)
                                <tr>
                                    <td class="nombre">{{ $g['nombre'] }}<small>{{ $g['clientes'] === 1 ? '1 cliente' : number_format($g['clientes']).' clientes' }}</small></td>
                                    <td class="n">{{ lempiras($g['venta']) }}</td>
                                    <td><div class="cm-parte" title="{{ $pct($g['participacion'], 1) }}"><i style="width: {{ round(($g['participacion'] ?? 0) * 100, 1) }}%"></i></div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rp-seccion">
                <h2>Por zona <small>municipio del cliente</small></h2>
                <div class="rp-tabla">
                    <table>
                        <thead><tr><th>Municipio</th><th class="n">Venta</th><th class="n">Clientes</th><th>Participación</th></tr></thead>
                        <tbody>
                            @foreach ($d['zonas'] as $z)
                                <tr>
                                    <td class="nombre">{{ $z['municipio'] }}<small>{{ $z['departamento'] }}</small></td>
                                    <td class="n">{{ lempiras($z['venta']) }}</td>
                                    <td class="n">{{ number_format($z['clientes']) }}</td>
                                    <td><div class="cm-parte" title="{{ $pct($z['participacion'], 1) }}"><i style="width: {{ round(($z['participacion'] ?? 0) * 100, 1) }}%"></i></div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <section class="rp-seccion">
            <h2>Productos más vendidos <small>los primeros {{ count($d['productos']) }}</small></h2>
            <div class="rp-tabla">
                <table>
                    <thead><tr><th>Producto</th><th class="n">Venta</th><th>Participación</th><th class="n">Unidades</th><th class="n">Clientes</th></tr></thead>
                    <tbody>
                        @foreach ($d['productos'] as $p)
                            <tr>
                                <td class="nombre">{{ $p['nombre'] }}<small><span class="cod">{{ $p['codigo'] }}</span> · {{ $p['proveedor'] }}</small></td>
                                <td class="n">{{ lempiras($p['venta']) }}</td>
                                <td><div class="cm-parte" title="{{ $pct($p['participacion'], 1) }}"><i style="width: {{ round(($p['participacion'] ?? 0) * 100, 1) }}%"></i></div></td>
                                <td class="n">{{ number_format($p['unidades']) }}</td>
                                <td class="n">{{ number_format($p['clientes']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
