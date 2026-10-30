{{--
    Ver pedido, arriba: cliente, estado, quién lo levantó / validó / imprimió
    y el total. Esquema: App\Filament\Resources\Pedidos\Schemas\PedidoInfolist.
--}}
@php
    /** @var \App\Models\Pedido $pedido */
    $pedido = $getRecord();
    $pedido->loadMissing(['cliente.municipio.departamento', 'vendedor', 'bodega', 'validador', 'impresor', 'corrector', 'factura']);
    $cliente = $pedido->cliente;
    $lineas = $pedido->items()->count();
    $porSustituir = $pedido->esReimpresion() ? $pedido->facturaPorSustituir() : null;
    $telefono = filled($cliente->telefono) ? (string) preg_replace('/^(\d{4})(\d{4})$/', '$1-$2', $cliente->telefono) : null;
    $lugar = collect([
        filled($cliente->rtn) ? 'RTN '.$cliente->rtn : null,
        $cliente->direccion,
        $cliente->municipio?->etiqueta(),
    ])->filter()->implode(' · ');

    // [etiqueta, valor, detalle, aviso, sin dato]
    $datos = [
        ['Vendedor', $pedido->vendedor->name, $pedido->vendedor->codigo, null, false],
        ['Bodega', $pedido->bodega->nombre, null, null, false],
        ['Validó', $pedido->validador->name ?? 'Sin validar', $pedido->validado_at?->format('d/m/Y H:i'), null, $pedido->validador === null],
    ];

    if ($pedido->estaValidado()) {
        $datos[] = ['Imprimió', $pedido->impresor->name ?? 'Sin imprimir', $pedido->impreso_at?->format('d/m/Y H:i'), null, $pedido->impresor === null];
    }

    if ($pedido->corregido_at !== null) {
        $datos[] = ['Corrigió', $pedido->corrector->name ?? '—', $pedido->corregido_at->format('d/m/Y H:i'), $pedido->esReimpresion() ? 'Falta reimprimir' : null, false];
    }
@endphp

@include('filament.pedidos.ver.estilos')

<div class="vp vp-resumen">
    <div class="vp-cabeza">
        <div class="vp-cliente">{{ $cliente->nombre }}</div>

        <div class="vp-meta">
            @if ($pedido->esReimpresion())
                <span class="vp-pill reimprimir">Por reimprimir</span>
            @elseif ($pedido->estado === \App\Enums\EstadoPedido::Impreso)
                <span class="vp-pill impreso">Impreso{{ $pedido->impresiones > 1 ? ' · '.$pedido->impresiones.' veces' : '' }}</span>
            @else
                <span class="vp-pill {{ $pedido->estado->value }}">{{ $pedido->estado->getLabel() }}</span>
            @endif

            @if ($pedido->fuera_de_ruta)
                <span class="vp-pill fuera">Fuera de ruta</span>
            @endif

            <span class="vp-tiempo" title="{{ $pedido->created_at?->format('d/m/Y H:i') }}">
                {{ svg('heroicon-m-clock') }} {{ $pedido->created_at?->diffForHumans() }}
            </span>
        </div>

        <div class="vp-tenue">
            {{ collect([$pedido->numero, $pedido->fecha->format('d/m/Y'), $cliente->codigo ? 'Cliente '.$cliente->codigo : null])->filter()->implode(' · ') }}
        </div>

        @if ($telefono || $lugar !== '')
            <div class="vp-tenue">
                @if ($telefono)
                    <a class="vp-tel" href="tel:+504{{ $cliente->telefono }}">{{ svg('heroicon-m-phone') }} {{ $telefono }}</a>
                @endif
                {{ $lugar }}
            </div>
        @endif
    </div>

    <dl class="vp-datos">
        @foreach ($datos as [$etiqueta, $valor, $detalle, $aviso, $sinDato])
            <div @class(['vp-dato', 'vp-sin' => $sinDato])>
                <dt>{{ $etiqueta }}</dt>
                <dd class="vp-dato-valor">{{ $valor }}</dd>
                @if (filled($detalle))
                    <dd class="vp-tenue">{{ $detalle }}</dd>
                @endif
                @if (filled($aviso))
                    <dd class="vp-dato-aviso">{{ $aviso }}</dd>
                @endif
            </div>
        @endforeach
    </dl>

    @if ($pedido->fuera_de_ruta)
        <div class="vp-aviso vp-aviso-ruta">
            <strong>Fuera de ruta</strong>
            <span>{{ $pedido->motivo_fuera_ruta ?: 'El cliente no es de las rutas del vendedor.' }}</span>
        </div>
    @endif

    @if ($porSustituir)
        <div class="vp-aviso vp-aviso-retirar">
            <strong>Factura anulada</strong>
            <span>Al reimprimir sale una nueva. Retire al cliente la N° {{ $porSustituir->numero }} y márquela ANULADA.</span>
        </div>
    @endif

    @if (filled($pedido->notas))
        <div class="vp-aviso vp-aviso-notas">
            <strong>Notas para bodega</strong>
            <span>{{ $pedido->notas }}</span>
        </div>
    @endif

    <div class="vp-pie">
        <div class="vp-pie-izq">
            <span class="vp-tenue">{{ $lineas }} {{ $lineas === 1 ? 'producto' : 'productos' }}</span>
            @if ($pedido->factura)
                <span class="vp-tenue">Factura {{ $pedido->factura->numero }}</span>
            @endif
        </div>

        <div @class(['vp-total', 'vp-anulado' => $pedido->estado === \App\Enums\EstadoPedido::Anulado])>
            <span>Total</span>
            <strong>{{ lempiras($pedido->total) }}</strong>
        </div>
    </div>
</div>
