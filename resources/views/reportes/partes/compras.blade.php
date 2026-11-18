{{--
    Cuerpo del reporte de compras: el mismo en pantalla (App\Filament\Pages\ReporteCompras)
    y en la hoja de imprimir (ImprimirReporteController::compras).
    Datos: $d = App\Domain\Reportes\ComprasDelPeriodo::datos().
--}}
@php
    /** @var array<string, mixed> $d */
    /** @var \App\Domain\Reportes\FilaCompra $t */
    $t = $d['totales'];
    $pct = fn (?float $parte): string => $parte === null ? '—' : rtrim(rtrim(number_format($parte * 100, 1), '0'), '.').'%';
    $recortado = fn (string $parte): bool => $d['limite'] !== null && $d['cuantos'][$parte] > $d['limite'];
    $conProveedor = $d['proveedor'] !== null;
    $plural = fn (int $n, string $uno, string $varios): string => $n === 1 ? '1 '.$uno : number_format($n).' '.$varios;
@endphp

<div class="rp">
    @if ($t->compras === 0)
        <div class="rp-vacio">No hay compras{{ $conProveedor ? ' a '.$d['proveedor'] : '' }} del {{ $d['periodo'] }} en {{ $d['todas'] ? 'ninguna bodega' : $d['bodega'] }}.</div>
    @else
        <section class="rp-kpis">
            <div class="rp-kpi principal">
                <span>Total comprado</span>
                <strong>{{ lempiras($t->total) }}</strong>
                <small>con ISV de {{ lempiras($t->isv) }}</small>
            </div>
            <div class="rp-kpi">
                <span>Sin ISV</span>
                <strong>{{ lempiras($t->subtotal) }}</strong>
                <small>subtotal de las facturas</small>
            </div>
            <div class="rp-kpi">
                <span>Compras</span>
                <strong>{{ number_format($t->compras) }}</strong>
                <small>{{ $conProveedor ? ($t->compras === 1 ? 'factura del proveedor' : 'facturas del proveedor') : 'a '.$plural($d['deCuantos'], 'proveedor', 'proveedores') }}</small>
            </div>
            <div class="rp-kpi">
                <span>Productos</span>
                <strong>{{ number_format($t->productos) }}</strong>
                <small>{{ $plural($t->unidades, 'unidad', 'unidades') }} en total</small>
            </div>
            <div class="rp-kpi">
                <span>Promedio por compra</span>
                <strong>{{ lempiras(round($t->total / $t->compras, 2)) }}</strong>
                <small>con ISV</small>
            </div>
        </section>

        @if ($d['proveedores'] !== [])
            <section class="rp-seccion">
                <h2>Por proveedor <small>del que más se le compró al que menos</small></h2>
                <div class="rp-tabla">
                    <table>
                        <thead>
                            <tr>
                                <th>Proveedor</th>
                                <th class="n">Compras</th>
                                <th class="n">Productos</th>
                                <th class="n">Sin ISV</th>
                                <th class="n">ISV</th>
                                <th class="n">Total</th>
                                <th class="n">Parte</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['proveedores'] as $f)
                                <tr>
                                    <td class="nombre">{{ $f->nombre }}</td>
                                    <td class="n">{{ number_format($f->compras) }}</td>
                                    <td class="n">{{ number_format($f->productos) }}</td>
                                    <td class="n">{{ lempiras($f->subtotal) }}</td>
                                    <td class="n">{{ lempiras($f->isv) }}</td>
                                    <td class="n"><b>{{ lempiras($f->total) }}</b></td>
                                    <td class="n">{{ $pct($f->parteDe($t->total)) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Total</td>
                                <td class="n">{{ number_format($t->compras) }}</td>
                                <td class="n">{{ number_format($t->productos) }}</td>
                                <td class="n">{{ lempiras($t->subtotal) }}</td>
                                <td class="n">{{ lempiras($t->isv) }}</td>
                                <td class="n">{{ lempiras($t->total) }}</td>
                                <td class="n">100%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="rp-nota">Escoja un proveedor arriba para ver qué se le compró y sacar el reporte que se le manda.</div>
            </section>
        @endif

        @if ($d['categorias'] !== [])
            <section class="rp-seccion">
                <h2>Por categoría</h2>
                <div class="rp-tabla">
                    <table>
                        <thead>
                            <tr>
                                <th>Categoría</th>
                                <th class="n">Productos</th>
                                <th class="n">Unidades</th>
                                <th class="n">Sin ISV</th>
                                <th class="n">ISV</th>
                                <th class="n">Total</th>
                                <th class="n">Parte</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['categorias'] as $f)
                                <tr>
                                    <td class="nombre">{{ $f->nombre }}</td>
                                    <td class="n">{{ number_format($f->productos) }}</td>
                                    <td class="n">{{ number_format($f->unidades) }}</td>
                                    <td class="n">{{ lempiras($f->subtotal) }}</td>
                                    <td class="n">{{ lempiras($f->isv) }}</td>
                                    <td class="n"><b>{{ lempiras($f->total) }}</b></td>
                                    <td class="n">{{ $pct($f->parteDe($t->total)) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Total</td>
                                <td class="n">{{ number_format($t->productos) }}</td>
                                <td class="n">{{ number_format($t->unidades) }}</td>
                                <td class="n">{{ lempiras($t->subtotal) }}</td>
                                <td class="n">{{ lempiras($t->isv) }}</td>
                                <td class="n">{{ lempiras($t->total) }}</td>
                                <td class="n">100%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        @endif

        @if ($d['bodegas'] !== [])
            <section class="rp-seccion">
                <h2>Por bodega <small>a cuál entró</small></h2>
                <div class="rp-tabla">
                    <table>
                        <thead>
                            <tr>
                                <th>Bodega</th>
                                <th class="n">Compras</th>
                                <th class="n">Productos</th>
                                <th class="n">Sin ISV</th>
                                <th class="n">ISV</th>
                                <th class="n">Total</th>
                                <th class="n">Parte</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['bodegas'] as $f)
                                <tr>
                                    <td class="nombre">{{ $f->nombre }}</td>
                                    <td class="n">{{ number_format($f->compras) }}</td>
                                    <td class="n">{{ number_format($f->productos) }}</td>
                                    <td class="n">{{ lempiras($f->subtotal) }}</td>
                                    <td class="n">{{ lempiras($f->isv) }}</td>
                                    <td class="n"><b>{{ lempiras($f->total) }}</b></td>
                                    <td class="n">{{ $pct($f->parteDe($t->total)) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Total</td>
                                <td class="n">{{ number_format($t->compras) }}</td>
                                {{-- Un producto puede entrar a las dos bodegas: no se suma. --}}
                                <td class="n"></td>
                                <td class="n">{{ lempiras($t->subtotal) }}</td>
                                <td class="n">{{ lempiras($t->isv) }}</td>
                                <td class="n">{{ lempiras($t->total) }}</td>
                                <td class="n">100%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        @endif

        @if ($d['productos'] !== null)
            <section class="rp-seccion">
                <h2>Por producto <small>{{ $plural($d['cuantos']['productos'], 'renglón', 'renglones') }}, del que más se compró al que menos</small></h2>
                @if ($recortado('productos'))
                    <div class="rp-nota">Se muestran los {{ $d['limite'] }} primeros. El Excel y el PDF traen los {{ number_format($d['cuantos']['productos']) }}.</div>
                @endif
                <div class="rp-tabla">
                    <table>
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="n">Entró</th>
                                <th class="n">Unidades</th>
                                <th class="n">Costo prom.</th>
                                <th class="n">Sin ISV</th>
                                <th class="n">ISV</th>
                                <th class="n">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['productos'] as $f)
                                <tr>
                                    <td class="nombre">
                                        {{ $f->nombre }}
                                        <small><span class="cod">{{ $f->codigo }}</span> · {{ $conProveedor ? '' : $f->proveedor.' · ' }}{{ $f->detalle }}</small>
                                    </td>
                                    <td class="n">{{ number_format($f->cantidad) }} {{ $f->presentacion }}</td>
                                    <td class="n">{{ number_format($f->unidades) }}</td>
                                    <td class="n">{{ $f->costoPromedio() === null ? '—' : lempiras($f->costoPromedio()) }}</td>
                                    <td class="n">{{ lempiras($f->subtotal) }}</td>
                                    <td class="n">{{ lempiras($f->isv) }}</td>
                                    <td class="n"><b>{{ lempiras($f->total) }}</b></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Total</td>
                                <td class="n"></td>
                                <td class="n">{{ number_format($t->unidades) }}</td>
                                <td class="n"></td>
                                <td class="n">{{ lempiras($t->subtotal) }}</td>
                                <td class="n">{{ lempiras($t->isv) }}</td>
                                <td class="n">{{ lempiras($t->total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="rp-nota">Cada producto en la presentación en que entró. Costo prom.: lo que salió en promedio cada una, sin ISV.</div>
            </section>
        @endif

        @if ($d['compras'] !== null)
            <section class="rp-seccion">
                <h2>Por compra <small>{{ $plural($d['cuantos']['compras'], 'factura', 'facturas') }}, por fecha de la factura</small></h2>
                @if ($recortado('compras'))
                    <div class="rp-nota">Se muestran las {{ $d['limite'] }} primeras. El Excel y el PDF traen las {{ number_format($d['cuantos']['compras']) }}.</div>
                @endif
                <div class="rp-tabla">
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Factura del proveedor</th>
                                @unless ($conProveedor)
                                    <th>Proveedor</th>
                                @endunless
                                @if ($d['todas'])
                                    <th>Bodega</th>
                                @endif
                                <th class="n">Productos</th>
                                <th class="n">Sin ISV</th>
                                <th class="n">ISV</th>
                                <th class="n">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['compras'] as $f)
                                <tr>
                                    <td class="dia">{{ $f->fecha }}</td>
                                    <td class="nombre">
                                        {{ $f->codigo ?? 'Sin número' }}
                                        <small class="cod">{{ $f->nombre }}</small>
                                    </td>
                                    @unless ($conProveedor)
                                        <td>{{ $f->proveedor }}</td>
                                    @endunless
                                    @if ($d['todas'])
                                        <td>{{ $f->bodega }}</td>
                                    @endif
                                    <td class="n">{{ number_format($f->productos) }}</td>
                                    <td class="n">{{ lempiras($f->subtotal) }}</td>
                                    <td class="n">{{ lempiras($f->isv) }}</td>
                                    <td class="n"><b>{{ lempiras($f->total) }}</b></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="{{ 2 + ($conProveedor ? 0 : 1) + ($d['todas'] ? 1 : 0) }}">Total</td>
                                <td class="n"></td>
                                <td class="n">{{ lempiras($t->subtotal) }}</td>
                                <td class="n">{{ lempiras($t->isv) }}</td>
                                <td class="n">{{ lempiras($t->total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="rp-nota">Debajo de cada factura, el número con que se registró aquí (COM-…).</div>
            </section>
        @endif
    @endif
</div>
