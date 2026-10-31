{{--
    Ver pedido, productos: la misma fila que en la preventa (cantidad, nombre
    y total) y abajo los totales. Si bodega ya validó, la cantidad es la que
    sale y se marca lo que no salió.
--}}
@php
    /** @var \App\Models\Pedido $pedido */
    $pedido = $getRecord();
    $pedido->loadMissing(['items.producto', 'items.promocion']);
    $items = $pedido->items->sortBy('id');
@endphp

@include('filament.pedidos.ver.estilos')

<div class="vp">
    @if ($items->isEmpty())
        <div class="vp-tenue">Sin productos.</div>
    @else
        <ul class="vp-lista">
            @foreach ($items as $item)
                @php
                    $caja = $item->unidad === \App\Enums\UnidadVenta::Caja;
                    $sigla = $caja ? 'CJ' : 'UN';
                    $noSalio = $item->cantidad_despachada === 0;
                    $precio = lempiras($item->precio)
                        .($caja ? ' la caja de '.$item->producto->unidades_por_caja : ' c/u')
                        .($item->gravado ? ' + ISV' : ' · exento');
                @endphp
                <li @class(['vp-linea', 'vp-no-salio' => $noSalio])>
                    <span @class(['vp-cant', 'vp-cant-caja' => $caja])>{{ $item->cantidadFacturable() }} {{ $sigla }}</span>

                    <div class="vp-linea-texto">
                        <div class="vp-linea-nombre">{{ $item->producto->descripcion }}</div>
                        <div class="vp-tenue">{{ $item->producto->codigo }} · {{ $precio }}</div>

                        @if ($falta = $item->faltaEnPalabras())
                            <div class="vp-falta">{{ $falta }}</div>
                        @endif

                        @if ($item->descuento > 0)
                            <div class="vp-ahorro">−{{ lempiras($item->descuento) }}{{ $item->promocion ? ' · '.$item->promocion->codigo : '' }}</div>
                        @endif
                    </div>

                    <div class="vp-linea-total">{{ lempiras($item->total) }}</div>
                </li>
            @endforeach
        </ul>
    @endif

    <dl class="vp-totales">
        <div>
            <dt>Subtotal</dt>
            <dd>{{ lempiras($pedido->subtotal) }}</dd>
        </div>
        @if ($pedido->descuento > 0)
            <div class="vp-ahorro">
                <dt>Descuento promociones</dt>
                <dd>−{{ lempiras($pedido->descuento) }}</dd>
            </div>
        @endif
        <div>
            <dt>ISV</dt>
            <dd>{{ lempiras($pedido->isv) }}</dd>
        </div>
        <div class="vp-totales-total">
            <dt>Total</dt>
            <dd>{{ lempiras($pedido->total) }}</dd>
        </div>
    </dl>
</div>
