{{--
    Cuerpo del inventario valorizado: el mismo en pantalla (App\Filament\Pages\ReporteInventario)
    y en la hoja de imprimir (ImprimirReporteController::inventario).
    Datos: $d = App\Domain\Reportes\InventarioValorizado::datos().
--}}
@php
    /** @var array<string, mixed> $d */
    /** @var \App\Domain\Reportes\FilaInventario $t */
    $t = $d['totales'];
    $pct = fn (?float $margen): string => $margen === null ? '—' : rtrim(rtrim(number_format($margen * 100, 1), '0'), '.').'%';
@endphp

<div class="rp">
    <section class="rp-kpis">
        <div class="rp-kpi principal">
            <span>Invertido</span>
            <strong>{{ lempiras($t->invertido) }}</strong>
            <small>al costo promedio, sin ISV</small>
        </div>
        <div class="rp-kpi">
            <span>Con ISV</span>
            <strong>{{ lempiras($t->invertidoConIsv) }}</strong>
            <small>lo que se le pagó al proveedor</small>
        </div>
        <div class="rp-kpi">
            <span>A precio de venta</span>
            <strong>{{ lempiras($t->valorVenta) }}</strong>
            <small>sin ISV, si se vende todo</small>
        </div>
        <div class="rp-kpi">
            <span>Ganancia esperada</span>
            <strong @class(['neg' => $t->ganancia() < 0])>{{ lempiras($t->ganancia()) }}</strong>
            <small>margen {{ $pct($t->margen()) }}</small>
        </div>
        <div class="rp-kpi">
            <span>Productos</span>
            <strong>{{ number_format($t->productos) }}</strong>
            <small>con existencia · {{ number_format($t->unidades) }} unidades</small>
        </div>
    </section>

    @if ($d['negativos'] > 0)
        <div class="rp-aviso">
            {{ $d['negativos'] === 1 ? '1 producto está' : $d['negativos'].' productos están' }} en negativo en
            {{ $d['todas'] ? 'alguna bodega' : 'la bodega' }} (se despachó sin tener la existencia cargada): no se suma.
            Arréglelo en Productos → Ajustar existencia.
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
                            <th class="n">Productos</th>
                            <th class="n">Invertido</th>
                            <th class="n">Con ISV</th>
                            <th class="n">A precio de venta</th>
                            <th class="n">Ganancia esperada</th>
                            <th class="n">Margen</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($d['bodegas'] as $f)
                            <tr>
                                <td class="nombre">{{ $f->nombre }}</td>
                                <td class="n">{{ number_format($f->productos) }}</td>
                                <td class="n">{{ lempiras($f->invertido) }}</td>
                                <td class="n">{{ lempiras($f->invertidoConIsv) }}</td>
                                <td class="n">{{ lempiras($f->valorVenta) }}</td>
                                <td @class(['n', 'neg' => $f->ganancia() < 0])>{{ lempiras($f->ganancia()) }}</td>
                                <td class="n">{{ $pct($f->margen()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="n">{{ number_format($t->productos) }}</td>
                            <td class="n">{{ lempiras($t->invertido) }}</td>
                            <td class="n">{{ lempiras($t->invertidoConIsv) }}</td>
                            <td class="n">{{ lempiras($t->valorVenta) }}</td>
                            <td @class(['n', 'neg' => $t->ganancia() < 0])>{{ lempiras($t->ganancia()) }}</td>
                            <td class="n">{{ $pct($t->margen()) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="rp-nota">Un producto que está en varias bodegas cuenta en cada una; en el total, una vez.</div>
        </section>
    @endif

    @if ($d['proveedores'] !== [])
        <section class="rp-seccion">
            <h2>Por proveedor <small>del que más dinero tiene al que menos</small></h2>
            <div class="rp-tabla">
                <table>
                    <thead>
                        <tr>
                            <th>Proveedor</th>
                            <th class="n">Productos</th>
                            <th class="n">Invertido</th>
                            <th class="n">Con ISV</th>
                            <th class="n">A precio de venta</th>
                            <th class="n">Ganancia esperada</th>
                            <th class="n">Margen</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($d['proveedores'] as $f)
                            <tr>
                                <td class="nombre">{{ $f->nombre }}</td>
                                <td class="n">{{ number_format($f->productos) }}</td>
                                <td class="n">{{ lempiras($f->invertido) }}</td>
                                <td class="n">{{ lempiras($f->invertidoConIsv) }}</td>
                                <td class="n">{{ lempiras($f->valorVenta) }}</td>
                                <td @class(['n', 'neg' => $f->ganancia() < 0])>{{ lempiras($f->ganancia()) }}</td>
                                <td class="n">{{ $pct($f->margen()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="n">{{ number_format($t->productos) }}</td>
                            <td class="n">{{ lempiras($t->invertido) }}</td>
                            <td class="n">{{ lempiras($t->invertidoConIsv) }}</td>
                            <td class="n">{{ lempiras($t->valorVenta) }}</td>
                            <td @class(['n', 'neg' => $t->ganancia() < 0])>{{ lempiras($t->ganancia()) }}</td>
                            <td class="n">{{ $pct($t->margen()) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    @endif

    <section class="rp-seccion">
        <h2>Productos <small>{{ $d['cuantos'] === 1 ? '1 producto' : number_format($d['cuantos']).' productos' }}, por proveedor y nombre</small></h2>
        @if ($d['limite'] !== null && $d['cuantos'] > $d['limite'])
            <div class="rp-nota">Se muestran los primeros {{ $d['limite'] }}. Busque un producto o escoja el proveedor; el Excel y el PDF traen los {{ number_format($d['cuantos']) }}.</div>
        @endif

        @if ($d['productos'] === [])
            <div class="rp-vacio">No hay productos {{ $d['filtros'] ? 'con esos filtros' : 'con existencia' }}.</div>
        @else
            <div class="rp-tabla">
                <table>
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th class="n">Existencia</th>
                            <th class="n">Costo unidad</th>
                            <th class="n">Invertido</th>
                            <th class="n">Precio unidad</th>
                            <th class="n">A precio de venta</th>
                            <th class="n">Ganancia esperada</th>
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
                                <td class="n">{{ $f->existencia }}</td>
                                <td class="n">{{ lempiras((float) $f->costoUnidad, 4) }}</td>
                                <td class="n">{{ lempiras($f->invertido) }}</td>
                                <td class="n">
                                    {{ lempiras((float) $f->precioUnidad) }}
                                    @if ($f->precioFijo)
                                        <span class="fijo" title="Precio fijo, puesto a mano">fijo</span>
                                    @endif
                                </td>
                                <td class="n">{{ lempiras($f->valorVenta) }}</td>
                                <td @class(['n', 'neg' => $f->ganancia() < 0])>{{ lempiras($f->ganancia()) }}</td>
                                <td class="n">{{ $pct($f->margen()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="n">{{ number_format($t->unidades) }} UN</td>
                            <td></td>
                            <td class="n">{{ lempiras($t->invertido) }}</td>
                            <td></td>
                            <td class="n">{{ lempiras($t->valorVenta) }}</td>
                            <td @class(['n', 'neg' => $t->ganancia() < 0])>{{ lempiras($t->ganancia()) }}</td>
                            <td class="n">{{ $pct($t->margen()) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </section>

    <div class="rp-nota">Montos sin ISV, salvo "Con ISV". Costo y precio por unidad. Invertido = existencia × costo promedio; a precio de venta = existencia × precio de venta.</div>
</div>
