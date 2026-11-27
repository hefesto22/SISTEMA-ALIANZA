{{--
    Cuerpo del reporte de devoluciones y pérdidas: el mismo en pantalla
    (App\Filament\Pages\ReporteDevoluciones) y en la hoja de imprimir
    (ImprimirReporteController::devoluciones).
    Datos: $d = App\Domain\Reportes\DevolucionesDelPeriodo::datos().
--}}
@php
    /** @var array<string, mixed> $d */
    $t = $d['totales'];
    $recortado = fn (string $parte): bool => $d['limite'] !== null && $d['cuantos'][$parte] > $d['limite'];
    $conProveedor = $d['proveedor'] !== null;
    $plural = fn (int $n, string $uno, string $varios): string => $n === 1 ? '1 '.$uno : number_format($n).' '.$varios;
@endphp

<style>
    .rp .perdida { color: var(--rp-mal); }
    .rp td.dia small { display: block; }
    .rp .tipo { display: inline-block; padding: 0.05rem 0.45rem; border-radius: 0.375rem; font-size: 0.72rem; font-weight: 600; border: 1px solid var(--rp-borde); }
</style>

<div class="rp">
    @if ($t['devoluciones'] === 0)
        <div class="rp-vacio">No hay devoluciones{{ $conProveedor ? ' de productos de '.$d['proveedor'] : '' }} del {{ $d['periodo'] }} en {{ $d['todas'] ? 'ninguna bodega' : $d['bodega'] }}.</div>
    @else
        <section class="rp-kpis">
            <div class="rp-kpi principal">
                <span>Pérdidas</span>
                <strong @class(['neg' => $t['perdida'] > 0])>{{ lempiras($t['perdida']) }}</strong>
                <small>lo que regresó dañado, a costo{{ $t['productos_perdidos'] > 0 ? ' · '.$plural($t['productos_perdidos'], 'producto', 'productos') : '' }}</small>
            </div>
            <div class="rp-kpi">
                <span>Cambio por cambio</span>
                <strong>{{ number_format($t['cambios']) }}</strong>
                <small>se repuso {{ lempiras($t['repuesto']) }} a costo</small>
            </div>
            <div class="rp-kpi">
                <span>Regresó a bodega</span>
                <strong>{{ lempiras($t['regreso']) }}</strong>
                <small>lo bueno, a costo</small>
            </div>
            <div class="rp-kpi">
                <span>Con crédito</span>
                <strong>{{ lempiras($t['credito']) }}</strong>
                <small>{{ $plural($t['creditos'], 'devolución', 'devoluciones') }}: se devolvió el dinero</small>
            </div>
            <div class="rp-kpi">
                <span>Devoluciones</span>
                <strong>{{ number_format($t['devoluciones']) }}</strong>
                <small>{{ number_format($t['cambios']) }} de cambio · {{ number_format($t['creditos']) }} con crédito</small>
            </div>
        </section>

        @if ($d['proveedores'] !== [])
            <section class="rp-seccion">
                <h2>Pérdidas por proveedor <small>lo dañado, a costo, de lo que más se perdió a lo que menos</small></h2>
                <div class="rp-tabla">
                    <table>
                        <thead>
                            <tr>
                                <th>Proveedor</th>
                                <th class="n">Productos</th>
                                <th class="n">Pérdida</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['proveedores'] as $f)
                                <tr>
                                    <td class="nombre">{{ $f['proveedor'] }}</td>
                                    <td class="n">{{ number_format($f['productos']) }}</td>
                                    <td class="n perdida"><b>{{ lempiras($f['perdida']) }}</b></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Total</td>
                                <td class="n">{{ number_format($t['productos_perdidos']) }}</td>
                                <td class="n">{{ lempiras($t['perdida']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="rp-nota">Escoja un proveedor arriba para sacar lo que se le reclama.</div>
            </section>
        @endif

        @if ($d['perdidas'] !== null)
            <section class="rp-seccion">
                <h2>Pérdidas <small>lo que regresó dañado y no volvió a la bodega{{ $conProveedor ? '' : ', por proveedor' }}</small></h2>
                @if ($d['perdidas'] === [])
                    <div class="rp-vacio">No regresó nada dañado.</div>
                @else
                    @if ($recortado('perdidas'))
                        <div class="rp-nota">Se muestran los {{ $d['limite'] }} primeros. El Excel y el PDF traen los {{ number_format($d['cuantos']['perdidas']) }}.</div>
                    @endif
                    <div class="rp-tabla">
                        <table>
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th class="n">Dañado</th>
                                    <th class="n">Costo c/u</th>
                                    <th class="n">Pérdida</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($d['perdidas'] as $f)
                                    <tr>
                                        <td class="nombre">
                                            {{ $f['producto'] }}
                                            <small><span class="cod">{{ $f['codigo'] }}</span>{{ $conProveedor ? '' : ' · '.$f['proveedor'] }}</small>
                                        </td>
                                        <td class="n">{{ number_format($f['cantidad']) }} {{ $f['presentacion'] }}</td>
                                        <td class="n">{{ lempiras($f['costo']) }}</td>
                                        <td class="n perdida"><b>{{ lempiras($f['perdida']) }}</b></td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3">Total perdido</td>
                                    <td class="n">{{ lempiras($t['perdida']) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="rp-nota">Cada producto en la presentación en que regresó. El costo es el que tenía al devolverlo.</div>
                @endif
            </section>
        @endif

        @if ($d['cambios'] !== null)
            <section class="rp-seccion">
                <h2>Cambio por cambio <small>lo que regresó y se le repuso al cliente con lo mismo</small></h2>
                @if ($d['cambios'] === [])
                    <div class="rp-vacio">No hubo cambios.</div>
                @else
                    @if ($recortado('cambios'))
                        <div class="rp-nota">Se muestran los {{ $d['limite'] }} primeros. El Excel y el PDF traen los {{ number_format($d['cuantos']['cambios']) }}.</div>
                    @endif
                    <div class="rp-tabla">
                        <table>
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th class="n">Regresó a bodega</th>
                                    <th class="n">Dañado</th>
                                    <th class="n">Se repuso</th>
                                    <th class="n">A costo</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($d['cambios'] as $f)
                                    <tr>
                                        <td class="nombre">
                                            {{ $f['producto'] }}
                                            <small><span class="cod">{{ $f['codigo'] }}</span>{{ $conProveedor ? '' : ' · '.$f['proveedor'] }}</small>
                                        </td>
                                        <td class="n">{{ $f['buenas'] > 0 ? number_format($f['buenas']).' '.$f['presentacion'] : '—' }}</td>
                                        <td @class(['n', 'perdida' => $f['danadas'] > 0])>{{ $f['danadas'] > 0 ? number_format($f['danadas']).' '.$f['presentacion'] : '—' }}</td>
                                        <td class="n"><b>{{ number_format($f['repuestas']) }} {{ $f['presentacion'] }}</b></td>
                                        <td class="n">{{ lempiras($f['costo']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="4">Total repuesto</td>
                                    <td class="n">{{ lempiras($t['repuesto']) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="rp-nota">Lo bueno volvió a la existencia. Lo dañado no volvió y también está en Pérdidas. Al cliente se le repone todo: sale de la bodega cuando se sube al camión (Cambios por entregar).</div>
                @endif
            </section>
        @endif

        @if ($d['devoluciones'] !== null)
            <section class="rp-seccion">
                <h2>Cada devolución <small>{{ $plural($d['cuantos']['devoluciones'], 'devolución', 'devoluciones') }}, en el orden en que se registraron</small></h2>
                @if ($recortado('devoluciones'))
                    <div class="rp-nota">Se muestran las {{ $d['limite'] }} primeras. El Excel y el PDF traen las {{ number_format($d['cuantos']['devoluciones']) }}.</div>
                @endif
                <div class="rp-tabla">
                    <table>
                        <thead>
                            <tr>
                                <th>Devolución</th>
                                <th>Cliente</th>
                                <th>Tipo</th>
                                <th class="n">Regresó a bodega</th>
                                <th class="n">Pérdida</th>
                                <th class="n">Crédito</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['devoluciones'] as $f)
                                <tr>
                                    <td class="dia">{{ $f['numero'] }}<small class="cod">{{ $f['fecha'] }}</small></td>
                                    <td class="nombre">
                                        {{ $f['cliente'] }}
                                        <small>{{ collect([$f['factura'] !== null ? 'Factura '.$f['factura'] : null, $f['carga'], $f['registro'] !== null ? 'registró '.$f['registro'] : null])->filter()->implode(' · ') }}</small>
                                        @if ($f['motivo'] !== null)
                                            <small>{{ $f['motivo'] }}</small>
                                        @endif
                                    </td>
                                    <td><span class="tipo">{{ $f['tipo']->getLabel() }}</span></td>
                                    <td class="n">{{ lempiras($f['regreso']) }}</td>
                                    <td @class(['n', 'perdida' => $f['perdida'] > 0])>{{ $f['perdida'] > 0 ? lempiras($f['perdida']) : '—' }}</td>
                                    <td class="n">{{ $f['credito'] === null ? '—' : lempiras($f['credito']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3">Total</td>
                                <td class="n">{{ lempiras($t['regreso']) }}</td>
                                <td class="n">{{ lempiras($t['perdida']) }}</td>
                                <td class="n">{{ lempiras($t['credito']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="rp-nota">Regresó a bodega y pérdida van a costo; el crédito es lo que se le devolvió al cliente (con ISV).</div>
            </section>
        @endif
    @endif
</div>
