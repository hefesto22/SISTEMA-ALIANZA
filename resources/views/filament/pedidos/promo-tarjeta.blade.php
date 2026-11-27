{{--
    Una promoción en la preventa, dentro de un x-for con "pr" (lo arma
    LineasPedido::getPromocionesDeHoy), como una línea de la hoja del
    proveedor: arriba la mecánica en grande ("24+1"), qué compra y el regalo;
    en medio cada producto con su botón; abajo cuánto queda del tope.
     - Las del plan del proveedor no se mezclan: cada producto con su botón
       ("+ 1 CJ24"); en la pregunta, pr ya viene con lo del producto que se
       tocó (paraProducto) y lleva un solo botón.
     - Las demás dicen lo que compra y tienen un solo botón.
    Lo usa resources/views/filament/pedidos/lineas-pedido.blade.php (los
    estilos .pv-promo-* están ahí). Un solo elemento raíz: va en un x-for.

    @var bool $enPregunta
--}}
<div class="pv-promo">
    <div class="pv-promo-cabeza">
        <div class="pv-promo-arriba">
            <span class="pv-promo-mecanica" x-show="pr.partes.mecanica !== ''" x-text="pr.partes.mecanica"></span>
            <span class="pv-promo-titulo" x-text="pr.partes.mecanica !== '' ? pr.partes.compra : pr.partes.titulo"></span>
        </div>
        <div class="pv-promo-lleva">
            {{ svg('heroicon-m-gift') }}
            <span x-text="pr.partes.lleva"></span>
        </div>
    </div>

    <div class="pv-promo-cuerpo">
        <template x-if="pr.partes.mecanica === ''">
            <div>
                <div class="pv-promo-etiqueta">Compra</div>
                <ul class="pv-promo-lista">
                    <template x-for="(texto, i) in pr.partes.productos" x-bind:key="i">
                        <li x-text="texto"></li>
                    </template>
                </ul>
            </div>
        </template>

        @if ($enPregunta)
            <button type="button" class="pv-promo-agregar" x-show="! agotada(pr)" x-on:click="agregarPromo(pr)">
                {{ svg('heroicon-m-plus') }}
                <span x-text="'Agregar la promoción' + (pr.solo ? ' · ' + pr.solo.agrega : '')"></span>
            </button>
        @else
            <template x-for="op in pr.opciones" x-bind:key="op.producto_id">
                <div class="pv-promo-opcion">
                    <span x-text="op.nombre"></span>
                    <button type="button" class="pv-promo-mas" x-show="! agotada(pr)" x-on:click="agregarPromo(pr, op)" x-bind:aria-label="'Agregar ' + op.agrega + ' de ' + op.nombre">
                        {{ svg('heroicon-m-plus') }}
                        <span x-text="op.agrega"></span>
                    </button>
                </div>
            </template>

            <button type="button" class="pv-promo-agregar" x-show="pr.opciones.length === 0 && ! agotada(pr)" x-on:click="agregarPromo(pr)">
                {{ svg('heroicon-m-plus') }} Agregar al pedido
            </button>
        @endif
    </div>

    <div class="pv-promo-pie" x-show="topeTexto(pr) !== '' || pr.nota">
        <div class="pv-promo-barra" x-show="Number(pr.tope) > 0" aria-hidden="true">
            <span x-bind:style="'width: ' + quedaPct(pr) + '%'"></span>
        </div>
        <div class="pv-promo-tope" x-show="topeTexto(pr) !== ''" x-bind:class="{ 'pv-promo-agotada': agotada(pr) }" x-text="topeTexto(pr)"></div>
        <div class="pv-promo-nota" x-show="pr.nota" x-text="pr.nota"></div>
    </div>
</div>
