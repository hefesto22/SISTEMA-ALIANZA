{{--
    Órdenes de cambio en Pedidos (App\Filament\Resources\Pedidos\Pages\ListPedidos),
    arriba de los pedidos, para que se vea lo que está pendiente de
    entregársele a cada cliente por un cambio por cambio
    (App\Domain\Devoluciones\OrdenesDeCambio):
      - Por imprimir: las que no se han impreso; "Imprimir" (o "Imprimir
        las N") las marca impresas y pasan a Impreso.
      - Impreso: impresas, esperan carga (se escogen en Cargar camión o en
        Nueva carga); "Reimprimir" saca una copia.
      - Cargado: las que van en ruta, con su carga.
    Datos: $cuales (OrdenesDeCambio::POR_IMPRIMIR…), $ordenes, $imprime.
--}}
@php
    use App\Domain\Devoluciones\OrdenesDeCambio;

    /** @var list<array<string, mixed>> $ordenes */
    $cuantas = count($ordenes);
    $ids = array_column($ordenes, 'id');
    $titulo = match ($cuales) {
        OrdenesDeCambio::POR_IMPRIMIR => 'Órdenes de cambio por imprimir',
        OrdenesDeCambio::POR_CARGAR   => 'Órdenes de cambio por cargar',
        default                       => 'Órdenes de cambio en ruta',
    };
    $ayuda = match ($cuales) {
        OrdenesDeCambio::POR_IMPRIMIR => 'Cambio por cambio: lo que se le debe llevar a cada cliente. Al imprimirla pasa a Impreso y de ahí se sube a la carga; el cliente firma al recibir.',
        OrdenesDeCambio::POR_CARGAR   => 'Ya impresas: se escogen al armar la carga (Cargar camión o Nueva carga).',
        default                       => 'Van en el camión; al liquidar la carga se marcan entregadas.',
    };
@endphp

<style>
    .oc { --oc-tenue: var(--gray-500); --oc-borde: var(--gray-200); --oc-fondo: #fff; display: grid; gap: 0.75rem; padding: 1rem 1.15rem; border-radius: 0.75rem; border: 1px solid color-mix(in oklab, var(--warning-500) 45%, transparent); background: color-mix(in oklab, var(--warning-500) 6%, var(--oc-fondo)); }
    .dark .oc { --oc-tenue: var(--gray-400); --oc-borde: rgb(255 255 255 / 0.1); --oc-fondo: var(--gray-900); }
    .oc-arriba { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.5rem 1rem; }
    .oc-titulo { margin: 0; font-size: 1rem; font-weight: 700; }
    .oc-titulo span { margin-inline-start: 0.35rem; padding: 0.05rem 0.5rem; border-radius: 999px; font-size: 0.75rem; color: var(--warning-700); background: color-mix(in oklab, var(--warning-500) 18%, transparent); }
    .dark .oc-titulo span { color: var(--warning-400); }
    .oc-ayuda { margin: 0.15rem 0 0; font-size: 0.85rem; color: var(--oc-tenue); }
    .oc-lista { display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr)); }
    .oc-orden { min-width: 0; display: flex; flex-direction: column; gap: 0.35rem; padding: 0.85rem 1rem; border-radius: 0.65rem; border: 1px solid var(--oc-borde); background: var(--oc-fondo); }
    .oc-num { display: flex; justify-content: space-between; gap: 0.5rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.04em; color: var(--warning-700); }
    .dark .oc-num { color: var(--warning-400); }
    .oc-num small { font-weight: 600; letter-spacing: 0; color: var(--oc-tenue); }
    .oc-cliente { font-weight: 700; line-height: 1.3; }
    .oc-de { font-size: 0.8rem; color: var(--oc-tenue); }
    .oc-lleva { font-size: 0.85rem; }
    .oc-lleva b { font-weight: 600; }
    .oc-nota { font-size: 0.75rem; font-weight: 600; color: var(--warning-700); }
    .dark .oc-nota { color: var(--warning-400); }
    .oc-pie { margin-top: auto; padding-top: 0.4rem; display: flex; justify-content: flex-end; }
</style>

<section class="oc">
    <div class="oc-arriba">
        <div>
            <h2 class="oc-titulo">{{ $titulo }}<span>{{ number_format($cuantas) }}</span></h2>
            <p class="oc-ayuda">{{ $ayuda }}</p>
        </div>
        @if ($imprime && $cuales === OrdenesDeCambio::POR_IMPRIMIR && $cuantas > 1)
            <x-filament::button size="sm" icon="heroicon-o-printer" wire:click="mountAction('imprimirCambios', { ids: {{ json_encode($ids) }} })">
                Imprimir las {{ number_format($cuantas) }}
            </x-filament::button>
        @endif
    </div>

    <div class="oc-lista">
        @foreach ($ordenes as $o)
            <article class="oc-orden" wire:key="orden-de-cambio-{{ $o['id'] }}">
                <div class="oc-num">
                    {{ $o['numero'] }}
                    <small>{{ $o['carga'] ?? $o['fecha'] }}</small>
                </div>
                <div class="oc-cliente">{{ $o['cliente'] }}</div>
                <div class="oc-de">{{ implode(' · ', array_filter([$o['municipio'], $o['ruta']])) }}</div>
                <div class="oc-lleva"><b>Lleva:</b> {{ $o['lleva'] }}</div>
                <div class="oc-de">{{ $o['devolucion'] }}{{ $o['factura'] !== null ? ' · factura '.$o['factura'] : '' }}</div>
                @if ($o['nota'] !== null)
                    <div class="oc-nota">{{ $o['nota'] }}</div>
                @endif
                @if ($imprime && $cuales !== OrdenesDeCambio::EN_RUTA)
                    <div class="oc-pie">
                        <x-filament::button size="sm" :color="$cuales === OrdenesDeCambio::POR_IMPRIMIR ? 'primary' : 'gray'" :outlined="$cuales !== OrdenesDeCambio::POR_IMPRIMIR" icon="heroicon-o-printer" wire:click="mountAction('imprimirCambios', { ids: [{{ $o['id'] }}] })">
                            {{ $cuales === OrdenesDeCambio::POR_IMPRIMIR ? 'Imprimir' : 'Reimprimir' }}
                        </x-filament::button>
                    </div>
                @endif
            </article>
        @endforeach
    </div>
</section>
