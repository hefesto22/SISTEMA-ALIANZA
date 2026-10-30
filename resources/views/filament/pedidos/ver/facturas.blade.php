{{--
    Ver pedido, facturas: la vigente y las anuladas, con su historial
    (a cuál sustituye, copias, quién la anuló y por cuál se cambió).
--}}
@php
    /** @var \App\Models\Pedido $pedido */
    $pedido = $getRecord();
    $facturas = $pedido->facturas()->with(['emisor', 'reemplazaA', 'reemplazo', 'anulador'])->get();
@endphp

@include('filament.pedidos.ver.estilos')

<div class="vp">
    <ul class="vp-lista">
        @foreach ($facturas as $factura)
            @php
                $historial = \App\Filament\Resources\Facturas\FacturaResource::historial($factura);
                $anulada = $factura->estaAnulada();
            @endphp
            <li @class(['vp-factura', 'vp-anulada' => $anulada])>
                <div class="vp-factura-arriba">
                    <span class="vp-factura-numero">{{ $factura->numero }}</span>
                    <span class="vp-pill {{ $factura->estado->value }}">{{ $factura->estado->getLabel() }}</span>
                    <span class="vp-factura-total">{{ lempiras($factura->total) }}</span>
                </div>

                <div class="vp-tenue">
                    {{ collect(['Emitida '.$factura->fecha_emision->format('d/m/Y H:i'), $factura->emisor?->name, 'CAI '.$factura->cai])->filter()->implode(' · ') }}
                </div>

                @if ($historial !== '—')
                    <div @class(['vp-tenue' => ! $anulada, 'vp-falta' => $anulada])>{{ $historial }}</div>
                @endif
            </li>
        @endforeach
    </ul>
</div>
