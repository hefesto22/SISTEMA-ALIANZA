{{--
    Metas del vendedor en el periodo: lo ganado, su venta, sus clientes y una
    tarjeta por grupo con la barra hacia la meta, los escalones (en cuál va),
    cuánto le falta para el siguiente y, a este ritmo, cómo cerraría el mes.
    La meta es del mes: en la segunda quincena se suma lo de la primera.
    $d = App\Filament\Pages\MisMetas::paraVendedor().
--}}
@php
    /** @var \App\Domain\Comisiones\PeriodoComision $periodo */
    $periodo = $d['periodo'];
    /** @var \App\Models\ComisionPeriodo|null $cierre */
    $cierre = $d['cierre'];
    /** @var \App\Domain\Comisiones\ResumenVendedor|null $r */
    $r = $d['resumen'];
    $k = $d['kpis'];
    $porciento = fn (float $p): string => \App\Domain\Comisiones\Escala::porciento($p);
    $nombrePeriodo = $periodo->frecuencia->periodo();
    $adelantado = $r === null ? 0.0 : round(array_sum(array_map(fn ($f) => $f->pagadoAntes, $r->filas)), 2);
    // La quincena anterior todavía no se cierra: el adelanto se le paga al cerrarla.
    $porCerrar = ($d['anterior']['estado'] ?? null) === \App\Domain\Comisiones\ComisionesDelPeriodo::ANTERIOR_POR_CERRAR;
@endphp

<div class="rp" wire:loading.delay.class="rp-cargando">
    @if ($cierre !== null)
        <div class="cm-estado cerrado"><strong>Pagado</strong><span>{{ $periodo->texto() }}: se cerró el {{ $cierre->cerrado_at->format('d/m/Y') }}. Esto es lo que se le pagó.</span></div>
    @elseif ($d['enCurso'])
        <div class="cm-estado abierto">
            <strong>{{ $d['quedan'] === 0 ? 'Último día' : ($d['quedan'] === 1 ? 'Queda 1 día' : 'Quedan '.$d['quedan'].' días') }}</strong>
            <span>de la {{ $nombrePeriodo }} del {{ $periodo->texto() }}. Cuenta lo facturado, sin ISV; la meta es del mes.</span>
        </div>
    @else
        <div class="cm-estado pendiente"><strong>Por pagar</strong><span>{{ $periodo->texto() }}: así quedó; administración lo cierra para pagarlo.</span></div>
    @endif

    @if ($r === null || $r->filas === [])
        <div class="rp-vacio">Todavía no hay metas de comisión. Administración las configura en Comisiones.</div>
    @else
        <section class="rp-kpis">
            <div class="rp-kpi principal">
                <span>{{ $cierre !== null ? 'Comisión pagada' : 'Comisión ganada' }}</span>
                <strong>{{ lempiras($r->comision()) }}</strong>
                <small>
                    {{ $cierre !== null ? 'del periodo' : 'hasta hoy' }}
                    @if ($adelantado != 0.0)
                        · {{ $porCerrar ? lempiras($adelantado).' se le adelantan al cerrar la quincena anterior' : 'ya se le adelantaron '.lempiras($adelantado).' en la quincena anterior' }}
                    @endif
                </small>
            </div>
            <div class="rp-kpi">
                <span>Venta neta</span>
                <strong>{{ lempiras($r->ventaTotal) }}</strong>
                <small>sin ISV · {{ $r->facturas === 1 ? '1 factura' : $r->facturas.' facturas' }}</small>
            </div>
            @if ($k !== null)
                <div class="rp-kpi">
                    <span>Clientes atendidos</span>
                    <strong>{{ number_format($k['clientes']) }}</strong>
                    <small>
                        @if ($d['cobertura'] !== null)
                            {{ (int) round($d['cobertura'] * 100) }}% de los {{ $d['cartera'] }} de sus rutas
                        @else
                            con factura en el periodo
                        @endif
                    </small>
                </div>
                <div class="rp-kpi">
                    <span>Ticket promedio</span>
                    <strong>{{ $k['ticket'] === null ? '—' : lempiras($k['ticket']) }}</strong>
                    <small>sin ISV, en {{ $k['facturas'] === 1 ? '1 factura' : $k['facturas'].' facturas' }} con fecha del periodo</small>
                </div>
            @endif
        </section>

        <section class="cm-metas">
            @foreach ($r->filas as $fila)
                @php
                    $actual = $fila->escala->escalon($fila->baseMes());
                    $ritmo = $d['ritmo'][$fila->grupoId] ?? null;
                    $alMaximo = $fila->escala->escalones === [] ? null : $fila->escala->escalones[count($fila->escala->escalones) - 1];
                @endphp
                <article class="cm-meta">
                    <h3>
                        <span>{{ $fila->grupo }}@if ($fila->propia)<span class="cm-tag">su meta</span>@endif</span>
                        <span @class(['cm-pct', 'llego' => $fila->llegoALaMeta()])>{{ $porciento($fila->porcentaje) }}</span>
                    </h3>

                    <div class="cm-cifra">
                        <strong>{{ lempiras($fila->baseMes()) }}</strong>
                        <span>{{ $fila->meta !== null ? 'de '.lempiras($fila->meta, 0).' en el mes' : 'vendido en el mes' }} · gana {{ lempiras($fila->comision) }}</span>
                    </div>

                    @if ($fila->sumaElMes())
                        <div class="rp-nota">{{ lempiras($fila->base) }} de esta quincena y {{ lempiras($fila->baseAntes) }} de la anterior. En el mes gana {{ lempiras($fila->comisionMes()) }}; {{ $porCerrar ? lempiras($fila->pagadoAntes).' se le adelantan al cerrar la anterior' : 'ya se le adelantaron '.lempiras($fila->pagadoAntes) }}.</div>
                    @endif

                    @include('filament.comisiones.avance', ['fila' => $fila])

                    <ul class="cm-escalones">
                        @foreach ($fila->escala->escalones as $escalon)
                            <li @class(['alcanzado' => $fila->baseMes() + 0.000001 >= $escalon['desde'], 'actual' => $actual === $escalon])>
                                <span>{{ $escalon['desde'] > 0 ? 'Desde '.lempiras($escalon['desde'], 0) : 'Desde el primer lempira' }}</span>
                                <span>{{ $porciento($escalon['porcentaje']) }}</span>
                            </li>
                        @endforeach
                    </ul>

                    @if (! $fila->llegoALaMeta() && $fila->meta !== null && $alMaximo !== null)
                        <div class="cm-falta">Si llega a la meta del mes gana <strong>{{ lempiras($fila->escala->comision($fila->meta)) }}</strong> en el mes ({{ $porciento($alMaximo['porcentaje']) }} de {{ lempiras($fila->meta, 0) }}).</div>
                    @endif

                    @if ($ritmo !== null)
                        <div class="cm-falta rp-nota">A este ritmo cerraría el mes con {{ lempiras($ritmo['venta'], 0) }} ({{ $porciento($ritmo['porcentaje']) }}) y ganaría {{ lempiras($ritmo['comision']) }} en el mes.</div>
                    @endif

                    @if ($fila->anulado != 0.0)
                        <div class="rp-nota">Se descontaron {{ lempiras($fila->anulado) }} de facturas ya pagadas que se anularon.</div>
                    @endif
                </article>
            @endforeach
        </section>

        @if ($d['productos'] !== [])
            <section class="rp-seccion">
                <h2>Lo que más vende <small>en el periodo</small></h2>
                <div class="rp-tabla">
                    <table>
                        <thead>
                            <tr><th>Producto</th><th class="n">Venta</th><th class="n">Clientes</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($d['productos'] as $p)
                                <tr>
                                    <td class="nombre">{{ $p['nombre'] }}<small><span class="cod">{{ $p['codigo'] }}</span> · {{ $p['proveedor'] }}</small></td>
                                    <td class="n">{{ lempiras($p['venta']) }}</td>
                                    <td class="n">{{ number_format($p['clientes']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif
</div>
