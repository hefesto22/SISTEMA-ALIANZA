{{--
    Hoja de cobro: las facturas de la carga con columnas en blanco para
    anotar, en la ruta, lo que se cobró en cada una (efectivo, cheque o
    transferencia) o que no se entregó. Abajo, los totales para llenar al
    regresar. Las anuladas al corregir el pedido no salen (va la que la sustituye).
    Hoja: App\Http\Controllers\ImprimirCargaController (?hoja=cobro).
    Datos: $d = App\Domain\Cargas\HojaDeCarga::datos().
--}}
@php
    /** @var array<string, mixed> $d */
    $facturas = array_values(array_filter($d['facturas'], fn (array $f): bool => $f['salio']));
@endphp

<div class="rp">
    @include('reportes.partes.carga-datos', ['d' => $d, 'pantalla' => false])

    <section class="rp-seccion">
        <h2>Cobro factura por factura <small>anote lo que pagó cada cliente; los cheques y las transferencias, con el banco y el número</small></h2>
        <div class="rp-tabla">
            <table class="cg-renglones">
                <thead>
                    <tr>
                        <th class="n">#</th>
                        <th>Factura</th>
                        <th>Cliente</th>
                        <th class="n">Total</th>
                        <th class="blanco">Efectivo</th>
                        <th class="blanco">Cheque</th>
                        <th class="blanco">Transferencia</th>
                        <th class="blanco">No se entregó</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($facturas as $i => $f)
                        <tr>
                            <td class="n">{{ $i + 1 }}</td>
                            <td class="dia">{{ $f['corto'] }}</td>
                            <td class="nombre">{{ $f['cliente'] }}<small>{{ $f['municipio'] !== null ? $f['municipio'].' · ' : '' }}vendió {{ $f['vendio'] }}</small></td>
                            <td class="n"><b>{{ number_format($f['total'], 2) }}</b></td>
                            <td class="blanco"></td>
                            <td class="blanco"></td>
                            <td class="blanco"></td>
                            <td class="blanco marca"><span class="caja"></span></td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3">Total de las {{ count($facturas) === 1 ? '1 factura' : count($facturas).' facturas' }}</td>
                        <td class="n">{{ number_format($d['total'], 2) }}</td>
                        <td class="blanco"></td>
                        <td class="blanco"></td>
                        <td class="blanco"></td>
                        <td class="blanco"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>

    <section class="cg-cuadre">
        <div class="cg-cuenta">
            <h3>Al regresar</h3>
            <ul>
                <li class="cg-renglones"><span>Efectivo</span><b>L ____________</b></li>
                <li class="cg-renglones"><span>Cheques (cuántos: ____ )</span><b>L ____________</b></li>
                <li class="cg-renglones"><span>Transferencias (cuántas: ____ )</span><b>L ____________</b></li>
                <li class="suma"><span>Trajo en total</span><b>L ____________</b></li>
            </ul>
        </div>
        <div class="cg-cuenta">
            <h3>Para el cuadre</h3>
            <ul>
                <li><span>Sale con</span><b>{{ lempiras($d['total']) }}</b></li>
                <li class="cg-renglones"><span>No se entregó</span><b>L ____________</b></li>
                <li class="cg-renglones"><span>Devoluciones con crédito</span><b>L ____________</b></li>
                <li class="suma"><span>Debe traer</span><b>L ____________</b></li>
            </ul>
        </div>
    </section>

    <div class="cg-firmas">
        <div class="cg-firma"><b>Entregó el dinero</b>{{ $d['sale'] }} · firma</div>
        <div class="cg-firma"><b>Recibió</b>Bodega · nombre y firma</div>
    </div>
</div>
