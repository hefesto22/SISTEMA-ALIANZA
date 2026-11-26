{{--
    Hoja de carga: lo que se sube al camión, producto por producto (lo
    vendido y los regalos), y las facturas que lleva. En papel, una casilla
    para ir marcando lo que se subió y las firmas de bodega y de quien sale.
    En pantalla (la carga en ruta) va sin casillas ni firmas.
    Hoja: App\Http\Controllers\ImprimirCargaController (?hoja=carga).
    Datos: $d = App\Domain\Cargas\HojaDeCarga::datos(); $pantalla.
--}}
@php
    /** @var array<string, mixed> $d */
    $pantalla ??= false;
    $hayRegalos = collect($d['productos'])->sum('regalo') > 0;
@endphp

<div class="rp">
    @include('reportes.partes.carga-datos', ['d' => $d, 'pantalla' => $pantalla])

    <section class="rp-seccion">
        <h2>Lo que se sube al camión <small>{{ count($d['productos']) === 1 ? '1 producto' : count($d['productos']).' productos' }}, sumado de las facturas que lleva</small></h2>
        @if ($d['productos'] === [])
            <div class="rp-vacio">La carga no lleva facturas vigentes.</div>
        @else
            <div class="rp-tabla">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Producto</th>
                            <th>Presentación</th>
                            @if ($hayRegalos)
                                <th class="n">Vendido</th>
                                <th class="n">Regalo</th>
                            @endif
                            <th class="n">Sube</th>
                            @unless ($pantalla)
                                <th class="marca">Listo</th>
                            @endunless
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($d['productos'] as $p)
                            <tr>
                                <td class="cod">{{ $p['codigo'] }}</td>
                                <td class="nombre">{{ $p['descripcion'] }}</td>
                                <td class="dia">{{ $p['sigla'] }}</td>
                                @if ($hayRegalos)
                                    <td class="n">{{ number_format($p['vendido']) }}</td>
                                    <td class="n">{{ $p['regalo'] > 0 ? number_format($p['regalo']) : '' }}</td>
                                @endif
                                <td class="n"><b>{{ number_format($p['total']) }}</b></td>
                                @unless ($pantalla)
                                    <td class="marca"><span class="caja"></span></td>
                                @endunless
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

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
