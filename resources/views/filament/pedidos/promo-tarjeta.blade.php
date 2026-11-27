{{--
    Una promoción en la preventa, dentro de un x-for con "pr" (lo arma
    LineasPedido::getPromocionesDeHoy): la mecánica en grande, qué compra,
    qué lleva, cuánto queda del tope y cómo agregarla.
     - Las del plan del proveedor ("20+1") no se mezclan: en la lista sale
       cada producto con su botón ("+ 1 CJ20"); en la pregunta, pr ya viene
       con lo del producto que se tocó (paraProducto).
     - Las demás dicen lo que compra y tienen un solo botón.
    Lo usa resources/views/filament/pedidos/lineas-pedido.blade.php (los
    estilos .pv-promo-* están ahí). Un solo elemento raíz: va en un x-for.

    @var bool $enPregunta
--}}
<div class="pv-promo">
    <div class="pv-promo-arriba">
        <span class="pv-promo-mecanica" x-show="pr.partes.mecanica !== ''" x-text="pr.partes.mecanica"></span>
        <span class="pv-promo-titulo" x-text="pr.partes.mecanica !== '' ? pr.partes.compra : pr.partes.titulo"></span>
    </div>

    <div x-show="pr.partes.mecanica === ''">
        <div class="pv-promo-nota">Compra:</div>
        <ul class="pv-promo-lista">
            <template x-for="(texto, i) in pr.partes.productos" x-bind:key="i">
                <li x-text="texto"></li>
            </template>
        </ul>
    </div>

    <div class="pv-promo-lleva">
        {{ svg('heroicon-m-gift') }}
        <span x-text="pr.partes.lleva"></span>
    </div>

    <div class="pv-promo-tope" x-show="topeTexto(pr) !== ''" x-bind:class="{ 'pv-promo-agotada': agotada(pr) }" x-text="topeTexto(pr)"></div>
    <div class="pv-promo-nota" x-show="pr.nota" x-text="pr.nota"></div>

    @if ($enPregunta)
        <button type="button" class="pv-promo-agregar" x-show="! agotada(pr)" x-on:click="agregarPromo(pr)">
            {{ svg('heroicon-m-plus') }}
            <span x-text="'Agregar la promoción' + (pr.solo ? ' · ' + pr.solo.agrega : '')"></span>
        </button>
    @else
        <div class="pv-promo-opciones" x-show="pr.opciones.length > 0">
            <template x-for="op in pr.opciones" x-bind:key="op.producto_id">
                <div class="pv-promo-opcion">
                    <span x-text="op.nombre"></span>
                    <button type="button" class="pv-promo-mas" x-show="! agotada(pr)" x-on:click="agregarPromo(pr, op)" x-bind:aria-label="'Agregar ' + op.agrega + ' de ' + op.nombre">
                        {{ svg('heroicon-m-plus') }}
                        <span x-text="op.agrega"></span>
                    </button>
                </div>
            </template>
        </div>

        <button type="button" class="pv-promo-agregar" x-show="pr.opciones.length === 0 && ! agotada(pr)" x-on:click="agregarPromo(pr)">
            {{ svg('heroicon-m-plus') }} Agregar al pedido
        </button>
    @endif
</div>
