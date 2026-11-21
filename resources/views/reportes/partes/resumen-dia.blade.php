{{--
    Cuerpo del resumen de facturas de un día (botón "Resumen del día" de Impresos).
    Hoja: App\Http\Controllers\ResumenDiaController.
    Datos: $d = App\Domain\Facturacion\ResumenDelDia::datos().
--}}
@php
    /** @var array<string, mixed> $d */
    /** @var list<\App\Models\Factura> $facturas */
    $facturas = $d['facturas'];
    $columnas = $d['variasBodegas'] ? 5 : 4;
@endphp

<style>
    .rp tr.anulada td { color: var(--rp-tenue); }
    .rp tr.anulada td.monto { text-decoration: line-through; }
    .rp .estado-anulada { font-weight: 600; color: var(--rp-mal); }
</style>

<div class="rp">
    @if ($facturas === [])
        <div class="rp-vacio">No se emitieron facturas el {{ $d['fecha'] }} en {{ $d['bodega'] }}.</div>
    @else
        <section class="rp-kpis">
            <div class="rp-kpi principal">
                <span>Total facturado</span>
                <strong>{{ lempiras($d['total']) }}</strong>
                <small>{{ $d['vigentes'] === 1 ? '1 factura vigente' : number_format($d['vigentes']).' facturas vigentes' }}</small>
            </div>
            <div class="rp-kpi">
                <span>Sin ISV</span>
                <strong>{{ lempiras($d['exento'] + $d['gravado']) }}</strong>
                <small>exento {{ lempiras($d['exento']) }} · gravado {{ lempiras($d['gravado']) }}</small>
            </div>
            <div class="rp-kpi">
                <span>ISV 15 %</span>
                <strong>{{ lempiras($d['isv']) }}</strong>
                <small>de las vigentes</small>
            </div>
            <div class="rp-kpi">
                <span>Números usados</span>
                <strong>{{ number_format(count($facturas)) }}</strong>
                <small>{{ $d['numeros'] }}</small>
            </div>
            <div class="rp-kpi">
                <span>Anuladas</span>
                <strong @class(['neg' => $d['anuladas'] > 0])>{{ number_format($d['anuladas']) }}</strong>
                <small>no suman al total</small>
            </div>
            <div class="rp-kpi">
                <span>Copias</span>
                <strong>{{ number_format($d['copias']) }}</strong>
                <small>reimpresiones de la misma factura</small>
            </div>
        </section>

        <section class="rp-seccion">
            <h2>Facturas <small>en el orden de su número</small></h2>
            <div class="rp-tabla">
                <table>
                    <thead>
                        <tr>
                            <th>Factura</th>
                            <th>Hora</th>
                            <th>Cliente</th>
                            <th>Vendedor</th>
                            @if ($d['variasBodegas'])
                                <th>Bodega</th>
                            @endif
                            <th class="n">Exento</th>
                            <th class="n">Gravado</th>
                            <th class="n">ISV</th>
                            <th class="n">Total</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($facturas as $factura)
                            <tr @class(['anulada' => $factura->estaAnulada()])>
                                <td class="dia">{{ $factura->numero }}</td>
                                <td class="dia">{{ $factura->fecha_emision->format('h:i a') }}</td>
                                <td class="nombre">{{ $factura->cliente_nombre }}<small>Pedido {{ $factura->pedido->numero }}</small></td>
                                <td>{{ $factura->pedido->vendedor->name }}</td>
                                @if ($d['variasBodegas'])
                                    <td>{{ $factura->bodega->nombre }}</td>
                                @endif
                                <td class="n monto">{{ number_format($factura->importe_exento, 2) }}</td>
                                <td class="n monto">{{ number_format($factura->importe_gravado, 2) }}</td>
                                <td class="n monto">{{ number_format($factura->isv, 2) }}</td>
                                <td class="n monto">{{ number_format($factura->total, 2) }}</td>
                                <td>
                                    @if ($factura->estaAnulada())
                                        <span class="estado-anulada">Anulada</span>{{ $factura->reemplazo ? ' · la sustituye '.$factura->reemplazo->numero : '' }}
                                    @else
                                        Vigente
                                    @endif
                                    @if ($factura->impresiones > 1)
                                        · {{ $factura->impresiones - 1 === 1 ? '1 copia' : ($factura->impresiones - 1).' copias' }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="{{ $columnas }}">Total de las {{ number_format($d['vigentes']) }} vigentes</td>
                            <td class="n">{{ number_format($d['exento'], 2) }}</td>
                            <td class="n">{{ number_format($d['gravado'], 2) }}</td>
                            <td class="n">{{ number_format($d['isv'], 2) }}</td>
                            <td class="n">{{ number_format($d['total'], 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <p class="rp-nota">Las anuladas salen en la lista porque su número se usó ese día, pero no suman al total.</p>
    @endif
</div>
