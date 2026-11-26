{{--
    Liquidación de una carga: el cuadre que se guardó al liquidar (lo que
    debía traer y lo que trajo), qué pasó con cada factura, los cheques, las
    transferencias, las devoluciones y la nota. En papel, las firmas de quien
    entregó el dinero y de quien lo recibió; en pantalla (la carga liquidada), sin firmas.
    Hoja: App\Http\Controllers\ImprimirCargaController (?hoja=liquidacion).
    Datos: $d = App\Domain\Cargas\HojaDeCarga::datos() de una carga liquidada; $pantalla.
--}}
@php
    /** @var array<string, mixed> $d */
    $pantalla ??= false;
    $c = $d['cuenta'];
    $creditos = array_values(array_filter($d['devoluciones'], fn (array $dev): bool => $dev['credito']));
    $pagos = ['Cheques' => $d['cheques'], 'Transferencias' => $d['transferencias']];
@endphp

<div class="rp">
    @include('reportes.partes.carga-datos', ['d' => $d, 'pantalla' => $pantalla])

    @if ($c !== null)
        <section class="cg-cuadre">
            <div class="cg-cuenta">
                <h3>Lo que debía traer</h3>
                <ul>
                    <li><span>Salió con</span><b>{{ lempiras($c['salio']) }}</b></li>
                    <li><span>Menos lo que no se entregó</span><b>{{ $c['no_entregado'] > 0 ? '− '.lempiras($c['no_entregado']) : lempiras(0) }}</b></li>
                    <li><span>Menos las devoluciones con crédito</span><b>{{ $c['devuelto'] > 0 ? '− '.lempiras($c['devuelto']) : lempiras(0) }}</b></li>
                    <li class="suma"><span>Debía traer</span><b>{{ lempiras($c['debe_traer']) }}</b></li>
                </ul>
            </div>
            <div class="cg-cuenta">
                <h3>Lo que trajo</h3>
                <ul>
                    <li><span>Efectivo</span><b>{{ lempiras($c['efectivo']) }}</b></li>
                    <li><span>Cheques{{ count($d['cheques']) > 0 ? ' ('.count($d['cheques']).')' : '' }}</span><b>{{ lempiras($c['cheques']) }}</b></li>
                    <li><span>Transferencias{{ count($d['transferencias']) > 0 ? ' ('.count($d['transferencias']).')' : '' }}</span><b>{{ lempiras($c['transferencias']) }}</b></li>
                    <li class="suma"><span>Trajo</span><b>{{ lempiras($c['trajo']) }}</b></li>
                </ul>
            </div>
        </section>
    @endif

    @if (filled($d['nota']))
        <div class="cg-nota"><b>Nota</b>{{ $d['nota'] }}</div>
    @endif

    <section class="rp-seccion">
        <h2>Cada factura</h2>
        <div class="rp-tabla">
            <table>
                <thead>
                    <tr>
                        <th>Factura</th>
                        <th>Cliente</th>
                        <th>Ruta · vendió</th>
                        <th class="n">Total</th>
                        <th>Cómo terminó</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($d['facturas'] as $f)
                        <tr @class(['tenue' => $f['estado'] !== \App\Enums\EntregaFactura::Entregada])>
                            <td class="dia">{{ $f['corto'] }}</td>
                            <td class="nombre">{{ $f['cliente'] }}<small>{{ $f['municipio'] !== null ? $f['municipio'].' · ' : '' }}Pedido {{ $f['pedido'] }}</small></td>
                            <td>@if ($f['ruta'] !== null)<b>Ruta {{ $f['ruta'] }}</b> · @endif{{ $f['vendio'] }}</td>
                            <td class="n monto">{{ number_format($f['total'], 2) }}</td>
                            <td>
                                @if ($f['estado'] === \App\Enums\EntregaFactura::Entregada)
                                    <span class="bien">Entregada</span>
                                @else
                                    {{ $f['nota'] ?? $f['estado']->getLabel() }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @foreach ($pagos as $titulo => $lista)
        @if ($lista !== [])
            <section class="rp-seccion">
                <h2>{{ $titulo }}</h2>
                <div class="rp-tabla">
                    <table>
                        <thead>
                            <tr>
                                <th>Factura</th>
                                <th>Banco</th>
                                <th>{{ $titulo === 'Cheques' ? 'Número' : 'Referencia' }}</th>
                                <th class="n">Monto</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lista as $pago)
                                <tr>
                                    <td class="dia">{{ $pago['factura'] ?? '—' }}</td>
                                    <td>{{ $pago['banco'] ?? '—' }}</td>
                                    <td class="dia">{{ $pago['referencia'] ?? '—' }}</td>
                                    <td class="n">{{ number_format($pago['monto'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3">Total</td>
                                <td class="n">{{ number_format(array_sum(array_column($lista, 'monto')), 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        @endif
    @endforeach

    @if ($d['devoluciones'] !== [])
        <section class="rp-seccion">
            <h2>Devoluciones en la ruta <small>{{ $creditos === [] ? 'solo cambios: no descuentan' : 'las de crédito se descuentan de lo que debía traer' }}</small></h2>
            <div class="rp-tabla">
                <table>
                    <thead>
                        <tr>
                            <th>Devolución</th>
                            <th>Factura</th>
                            <th>Cliente</th>
                            <th>Tipo</th>
                            <th class="n">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($d['devoluciones'] as $dev)
                            <tr @class(['tenue' => ! $dev['credito']])>
                                <td class="dia">{{ $dev['numero'] }}</td>
                                <td class="dia">{{ $dev['factura'] ?? '—' }}</td>
                                <td class="nombre">{{ $dev['cliente'] }}</td>
                                <td>{{ $dev['tipo'] }}</td>
                                <td class="n">{{ number_format($dev['total'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <p class="rp-nota">Liquidó {{ $d['liquido'] ?? '—' }} el {{ $d['liquidada_el'] ?? '—' }}. Las facturas que no se entregaron y se anularon regresaron a la bodega; las que salen en otra carga quedaron libres para cargarse otra vez.</p>

    @unless ($pantalla)
        <div class="cg-firmas">
            <div class="cg-firma"><b>Entregó el dinero</b>{{ $d['sale'] }} · firma</div>
            <div class="cg-firma"><b>Recibió el dinero</b>{{ $d['liquido'] ?? 'Bodega' }} · firma</div>
        </div>
    @endunless
</div>
