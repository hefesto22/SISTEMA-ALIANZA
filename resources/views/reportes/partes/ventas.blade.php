{{--
    Cuerpo del reporte de ventas: el mismo en pantalla (App\Filament\Pages\ReporteVentas)
    y en la hoja de imprimir (ImprimirReporteController::ventas).
    Datos: $d = App\Domain\Reportes\VentasDelPeriodo::datos().
--}}
@php
    /** @var array<string, mixed> $d */
    /** @var \App\Domain\Reportes\FilaVenta $t */
    $t = $d['totales'];
    $pct = fn (?float $margen): string => $margen === null ? '—' : rtrim(rtrim(number_format($margen * 100, 1), '0'), '.').'%';
    $recortado = fn (string $parte): bool => $d['limite'] !== null && $d['cuantos'][$parte] > $d['limite'];
@endphp

<div class="rp">
    @if ($t->pedidos === 0)
        <div class="rp-vacio">No hay ventas despachadas del {{ $d['periodo'] }} en {{ $d['todas'] ? 'ninguna bodega' : $d['bodega'] }}.</div>
    @else
        @if ($d['verTotales'])
            <section class="rp-kpis">
                <div class="rp-kpi principal">
                    <span>Venta</span>
                    <strong>{{ lempiras($t->venta) }}</strong>
                    <small>sin ISV{{ $t->descuento > 0 ? ', ya con '.lempiras($t->descuento).' de descuentos' : '' }}</small>
                </div>
                <div class="rp-kpi">
                    <span>Ganancia</span>
                    <strong @class(['neg' => $t->ganancia() < 0])>{{ lempiras($t->ganancia()) }}</strong>
                    <small>margen {{ $pct($t->margen()) }}</small>
                </div>
                <div class="rp-kpi">
                    <span>Costo de lo vendido</span>
                    <strong>{{ lempiras($t->costo) }}</strong>
                    <small>al costo promedio del día</small>
                </div>
                <div class="rp-kpi">
                    <span>Total facturado</span>
                    <strong>{{ lempiras($t->total) }}</strong>
                    <small>con ISV de {{ lempiras($t->isv) }}</small>
                </div>
                <div class="rp-kpi">
                    <span>Pedidos</span>
                    <strong>{{ number_format($t->pedidos) }}</strong>
                    <small>{{ $t->clientes === 1 ? '1 cliente' : number_format($t->clientes).' clientes' }}</small>
                </div>
            </section>
        @endif

        @if ($d['estimados'] > 0)
            <div class="rp-aviso">
                {{ $d['estimados'] === 1 ? '1 pedido se despachó' : $d['estimados'].' pedidos se despacharon' }} antes de que hubiera inventario:
                no tienen salida en el kárdex y su costo se calculó con el costo de hoy.
            </div>
        @endif

        @if ($d['bodegas'] !== [])
            <section class="rp-seccion">
                <h2>Por bodega</h2>
                <div class="rp-tabla">
                    <table>
                        <thead>
                            <tr>
                                <th>Bodega</th>
                                <th class="n">Pedidos</th>
                                <th class="n">Clientes</th>
                                <th class="n">Venta</th>
                                <th class="n">Costo</th>
                                <th class="n">Ganancia</th>
                                <th class="n">Margen</th>
                                <th class="n">Total con ISV</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['bodegas'] as $f)
                                <tr>
                                    <td class="nombre">{{ $f->nombre }}</td>
                                    <td class="n">{{ number_format($f->pedidos) }}</td>
                                    <td class="n">{{ number_format($f->clientes) }}</td>
                                    <td class="n">{{ lempiras($f->venta) }}</td>
                                    <td class="n">{{ lempiras($f->costo) }}</td>
                                    <td @class(['n', 'neg' => $f->ganancia() < 0])>{{ lempiras($f->ganancia()) }}</td>
                                    <td class="n">{{ $pct($f->margen()) }}</td>
                                    <td class="n">{{ lempiras($f->total) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Total</td>
                                <td class="n">{{ number_format($t->pedidos) }}</td>
                                <td class="n">{{ number_format($t->clientes) }}</td>
                                <td class="n">{{ lempiras($t->venta) }}</td>
                                <td class="n">{{ lempiras($t->costo) }}</td>
                                <td @class(['n', 'neg' => $t->ganancia() < 0])>{{ lempiras($t->ganancia()) }}</td>
                                <td class="n">{{ $pct($t->margen()) }}</td>
                                <td class="n">{{ lempiras($t->total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        @endif

        @if ($d['verTotales'])
            <section class="rp-seccion">
                <h2>Por día <small>día en que se despachó</small></h2>
                <div class="rp-tabla">
                    <table>
                        <thead>
                            <tr>
                                <th>Día</th>
                                <th class="n">Pedidos</th>
                                <th class="n">Venta</th>
                                <th class="n">Descuentos</th>
                                <th class="n">Costo</th>
                                <th class="n">Ganancia</th>
                                <th class="n">Margen</th>
                                <th class="n">Total con ISV</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['dias'] as $f)
                                <tr>
                                    <td class="dia">{{ $f->nombre }}</td>
                                    <td class="n">{{ number_format($f->pedidos) }}</td>
                                    <td class="n">{{ lempiras($f->venta) }}</td>
                                    <td class="n">{{ lempiras($f->descuento) }}</td>
                                    <td class="n">{{ lempiras($f->costo) }}</td>
                                    <td @class(['n', 'neg' => $f->ganancia() < 0])>{{ lempiras($f->ganancia()) }}</td>
                                    <td class="n">{{ $pct($f->margen()) }}</td>
                                    <td class="n">{{ lempiras($f->total) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Total</td>
                                <td class="n">{{ number_format($t->pedidos) }}</td>
                                <td class="n">{{ lempiras($t->venta) }}</td>
                                <td class="n">{{ lempiras($t->descuento) }}</td>
                                <td class="n">{{ lempiras($t->costo) }}</td>
                                <td @class(['n', 'neg' => $t->ganancia() < 0])>{{ lempiras($t->ganancia()) }}</td>
                                <td class="n">{{ $pct($t->margen()) }}</td>
                                <td class="n">{{ lempiras($t->total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        @endif

        @if ($d['vendedores'] !== null)
            <section class="rp-seccion">
                <h2>Por vendedor <small>{{ $d['cuantos']['vendedores'] === 1 ? '1 vendedor' : $d['cuantos']['vendedores'].' vendedores' }}</small></h2>
                <div class="rp-tabla">
                    <table>
                        <thead>
                            <tr>
                                <th>Vendedor</th>
                                <th class="n">Pedidos</th>
                                <th class="n">Clientes</th>
                                <th class="n">Venta</th>
                                <th class="n">Descuentos</th>
                                <th class="n">Costo</th>
                                <th class="n">Ganancia</th>
                                <th class="n">Margen</th>
                                <th class="n">Total con ISV</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['vendedores'] as $f)
                                <tr>
                                    <td class="nombre">
                                        {{ $f->nombre }}
                                        @if ($f->codigo)
                                            <small class="cod">{{ $f->codigo }}</small>
                                        @endif
                                    </td>
                                    <td class="n">{{ number_format($f->pedidos) }}</td>
                                    <td class="n">{{ number_format($f->clientes) }}</td>
                                    <td class="n">{{ lempiras($f->venta) }}</td>
                                    <td class="n">{{ lempiras($f->descuento) }}</td>
                                    <td class="n">{{ lempiras($f->costo) }}</td>
                                    <td @class(['n', 'neg' => $f->ganancia() < 0])>{{ lempiras($f->ganancia()) }}</td>
                                    <td class="n">{{ $pct($f->margen()) }}</td>
                                    <td class="n">{{ lempiras($f->total) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Total</td>
                                <td class="n">{{ number_format($t->pedidos) }}</td>
                                <td class="n">{{ number_format($t->clientes) }}</td>
                                <td class="n">{{ lempiras($t->venta) }}</td>
                                <td class="n">{{ lempiras($t->descuento) }}</td>
                                <td class="n">{{ lempiras($t->costo) }}</td>
                                <td @class(['n', 'neg' => $t->ganancia() < 0])>{{ lempiras($t->ganancia()) }}</td>
                                <td class="n">{{ $pct($t->margen()) }}</td>
                                <td class="n">{{ lempiras($t->total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        @endif

        @if ($d['productos'] !== null)
            <section class="rp-seccion">
                <h2>Por producto <small>{{ $d['cuantos']['productos'] === 1 ? '1 producto' : number_format($d['cuantos']['productos']).' productos' }}, del que más vendió al que menos</small></h2>
                @if ($recortado('productos'))
                    <div class="rp-nota">Se muestran los {{ $d['limite'] }} que más vendieron. El Excel y el PDF traen los {{ number_format($d['cuantos']['productos']) }}.</div>
                @endif
                <div class="rp-tabla">
                    <table>
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="n">Salió</th>
                                <th class="n">Pedidos</th>
                                <th class="n">Venta</th>
                                <th class="n">Descuentos</th>
                                <th class="n">Costo</th>
                                <th class="n">Ganancia</th>
                                <th class="n">Margen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['productos'] as $f)
                                <tr>
                                    <td class="nombre">
                                        {{ $f->nombre }}
                                        <small><span class="cod">{{ $f->codigo }}</span> · {{ $f->detalle }}</small>
                                    </td>
                                    <td class="n">{{ $f->cantidad }}</td>
                                    <td class="n">{{ number_format($f->pedidos) }}</td>
                                    <td class="n">{{ lempiras($f->venta) }}</td>
                                    <td class="n">{{ lempiras($f->descuento) }}</td>
                                    <td class="n">{{ lempiras($f->costo) }}</td>
                                    <td @class(['n', 'neg' => $f->ganancia() < 0])>{{ lempiras($f->ganancia()) }}</td>
                                    <td class="n">{{ $pct($f->margen()) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Total</td>
                                <td class="n">{{ number_format($t->unidades) }} UN</td>
                                <td class="n">{{ number_format($t->pedidos) }}</td>
                                <td class="n">{{ lempiras($t->venta) }}</td>
                                <td class="n">{{ lempiras($t->descuento) }}</td>
                                <td class="n">{{ lempiras($t->costo) }}</td>
                                <td @class(['n', 'neg' => $t->ganancia() < 0])>{{ lempiras($t->ganancia()) }}</td>
                                <td class="n">{{ $pct($t->margen()) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        @endif

        @if ($d['clientes'] !== null)
            <section class="rp-seccion">
                <h2>Por cliente <small>{{ $d['cuantos']['clientes'] === 1 ? '1 cliente' : number_format($d['cuantos']['clientes']).' clientes' }}, del que más compró al que menos</small></h2>
                @if ($recortado('clientes'))
                    <div class="rp-nota">Se muestran los {{ $d['limite'] }} que más compraron. El Excel y el PDF traen los {{ number_format($d['cuantos']['clientes']) }}.</div>
                @endif
                <div class="rp-tabla">
                    <table>
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th class="n">Pedidos</th>
                                <th class="n">Venta</th>
                                <th class="n">Descuentos</th>
                                <th class="n">Costo</th>
                                <th class="n">Ganancia</th>
                                <th class="n">Margen</th>
                                <th class="n">Total con ISV</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['clientes'] as $f)
                                <tr>
                                    <td class="nombre">
                                        {{ $f->nombre }}
                                        <small>{{ collect([$f->codigo, $f->detalle])->filter()->implode(' · ') }}</small>
                                    </td>
                                    <td class="n">{{ number_format($f->pedidos) }}</td>
                                    <td class="n">{{ lempiras($f->venta) }}</td>
                                    <td class="n">{{ lempiras($f->descuento) }}</td>
                                    <td class="n">{{ lempiras($f->costo) }}</td>
                                    <td @class(['n', 'neg' => $f->ganancia() < 0])>{{ lempiras($f->ganancia()) }}</td>
                                    <td class="n">{{ $pct($f->margen()) }}</td>
                                    <td class="n">{{ lempiras($f->total) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Total</td>
                                <td class="n">{{ number_format($t->pedidos) }}</td>
                                <td class="n">{{ lempiras($t->venta) }}</td>
                                <td class="n">{{ lempiras($t->descuento) }}</td>
                                <td class="n">{{ lempiras($t->costo) }}</td>
                                <td @class(['n', 'neg' => $t->ganancia() < 0])>{{ lempiras($t->ganancia()) }}</td>
                                <td class="n">{{ $pct($t->margen()) }}</td>
                                <td class="n">{{ lempiras($t->total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        @endif

        <div class="rp-nota">Venta, costo y ganancia van sin ISV. Cuenta como venta lo que bodega ya despachó (por imprimir e impreso), el día que se despachó.</div>
    @endif
</div>
