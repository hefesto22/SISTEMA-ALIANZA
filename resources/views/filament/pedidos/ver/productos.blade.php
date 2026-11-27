{{--
    Ver pedido, productos: la misma fila que en la preventa (cantidad, nombre
    y total con ISV) y abajo los totales. Si bodega ya validó, la cantidad es
    la que sale y se marca lo que no salió.

    Los regalos de promoción salen como "Gratis" y aparte en los totales: no
    suman en "Productos" ni se restan como descuento (así cuadra: la suma
    de las filas es el total). "Productos" es lo que se cobra, sin ISV;
    después van los descuentos de verdad (promociones con % y venta en
    bodega), el ISV y el total.
--}}
@php
    /** @var \App\Models\Pedido $pedido */
    $pedido = $getRecord();
    $pedido->loadMissing(['items.producto', 'items.presentacion.unidadMedida', 'items.promocion']);
    $items = $pedido->items->sortBy('id');
    $cobrados = $items->reject(fn (\App\Models\PedidoItem $item): bool => $item->bonificacion);
    $regalos = $items->filter(fn (\App\Models\PedidoItem $item): bool => $item->bonificacion && $item->cantidadFacturable() > 0);
    $productos = round((float) $cobrados->sum('subtotal'), 2);
    $descuentoPromociones = round((float) $cobrados->sum(fn (\App\Models\PedidoItem $item): float => $item->descuento - $item->descuento_mayoreo), 2);
    $tasaIsv = \App\Domain\Precios\CalculadoraPrecio::tasaIsv();
    $mecanica = fn (\App\Models\PedidoItem $item): string => $item->promocion !== null && $item->promocion->mecanica() !== '' ? ' '.$item->promocion->mecanica() : '';
@endphp

@include('filament.pedidos.ver.estilos')

<div class="vp">
    @if ($items->isEmpty())
        <div class="vp-tenue">Sin productos.</div>
    @else
        <ul class="vp-lista">
            @foreach ($items as $item)
                @php
                    $caja = ! $item->presentacion->es_base;
                    $sigla = $item->presentacion->sigla();
                    $noSalio = $item->cantidad_despachada === 0;
                    $precio = $item->bonificacion
                        ? 'Regalo de la promoción'.$mecanica($item).': no se cobra'
                        : lempiras($item->precio)
                            .($caja ? ' por '.$item->presentacion->palabra(1) : ' c/u')
                            .($item->gravado ? ' + ISV' : ' · exento');
                @endphp
                <li @class(['vp-linea', 'vp-no-salio' => $noSalio, 'vp-regalo' => $item->bonificacion])>
                    <span @class(['vp-cant', 'vp-cant-caja' => $caja])>{{ $item->cantidadFacturable() }} {{ $sigla }}</span>

                    <div class="vp-linea-texto">
                        <div class="vp-linea-nombre">{{ $item->producto->descripcion }}</div>
                        <div class="vp-tenue">{{ $item->producto->codigo }} · {{ $precio }}</div>

                        @if ($falta = $item->faltaEnPalabras())
                            <div class="vp-falta">{{ $falta }}</div>
                        @elseif ($mas = $item->deMasEnPalabras())
                            <div class="vp-mas">{{ $mas }}</div>
                        @endif

                        @if ($item->descuento_mayoreo > 0)
                            <div class="vp-ahorro">Descuento venta en bodega −{{ lempiras($item->descuento_mayoreo) }} (sin ISV)</div>
                        @elseif ($item->descuento > 0 && ! $item->bonificacion)
                            <div class="vp-ahorro">Promoción{{ $mecanica($item) }} −{{ lempiras($item->descuento) }} (sin ISV)</div>
                        @endif
                    </div>

                    @if ($item->bonificacion)
                        <div class="vp-linea-total vp-gratis">Gratis</div>
                    @else
                        <div class="vp-linea-total">{{ lempiras($item->total) }}</div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    <dl class="vp-totales">
        <div>
            <dt>Productos (sin ISV)</dt>
            <dd>{{ lempiras($productos) }}</dd>
        </div>
        @if ($descuentoPromociones > 0)
            <div class="vp-ahorro">
                <dt>Descuento por promociones</dt>
                <dd>−{{ lempiras($descuentoPromociones) }}</dd>
            </div>
        @endif
        @if ($pedido->descuento_mayoreo > 0)
            <div class="vp-ahorro">
                <dt>Descuento venta en bodega ({{ \App\Filament\Resources\Pedidos\Schemas\PedidoForm::porcentajeTexto($pedido->descuento_mayoreo_porcentaje) }})</dt>
                <dd>−{{ lempiras($pedido->descuento_mayoreo) }}</dd>
            </div>
        @endif
        <div>
            <dt>ISV ({{ \App\Models\Promocion::porcentajeTexto($tasaIsv * 100) }})</dt>
            <dd>{{ lempiras($pedido->isv) }}</dd>
        </div>
        <div class="vp-totales-total">
            <dt>Total a cobrar</dt>
            <dd>{{ lempiras($pedido->total) }}</dd>
        </div>
    </dl>

    @if ($regalos->isNotEmpty())
        <div class="vp-regalos">
            <div class="vp-regalos-titulo">{{ svg('heroicon-m-gift') }} Regalos de promoción · no se cobran</div>
            <ul>
                @foreach ($regalos as $regalo)
                    <li>
                        <span><b>{{ $regalo->cantidadFacturable() }} {{ $regalo->presentacion->sigla() }}</b> {{ $regalo->producto->descripcion }}</span>
                        @if ($regalo->promocion)
                            <span class="vp-tenue">{{ $mecanica($regalo) !== '' ? 'Promoción'.$mecanica($regalo) : \Illuminate\Support\Str::limit($regalo->promocion->nombre, 60) }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
