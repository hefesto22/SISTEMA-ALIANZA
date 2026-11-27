{{--
    Orden de cambio (ImprimirOrdenDeCambioController): lo que se le lleva a
    un cliente por un cambio por cambio, como documento interno sin valor
    fiscal (CAM-000001). Una hoja por orden. Dice a quién (cliente, RTN,
    municipio, ruta y vendedor), de qué devolución y factura sale, qué se le
    entrega (lo mismo que devolvió, en la presentación en que regresó) y
    lleva las firmas de quien entrega y de quien recibe conforme. Si es para
    otro cliente (se le quedó debiendo porque su producto se le dio al que
    devolvió), dice su pedido y de quién fue el cambio; si es lo que le faltó
    de su factura, lo dice. La factura no cambia de valor. De la segunda
    impresión en adelante dice COPIA.
    Datos: $ordenes (Reposicion con sus relaciones) y $fiscales (DatosFiscales).
--}}
@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Reposicion> $ordenes */
    /** @var \App\Models\DatosFiscales $fiscales */
    $titulo = $ordenes->count() === 1 ? 'Orden de cambio '.$ordenes->first()?->numero : $ordenes->count().' órdenes de cambio';
@endphp
<!DOCTYPE html>
<html lang="es" translate="no">
<head>
    <meta charset="utf-8">
    <meta name="google" content="notranslate">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #111; margin: 0; background: #f3f4f6; font-size: 13px; }
        .barra { max-width: 840px; margin: 16px auto 0; display: flex; justify-content: flex-end; }
        .barra button { font: inherit; padding: 6px 14px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; }
        .hoja { max-width: 840px; margin: 16px auto 24px; background: #fff; padding: 28px 32px; border-radius: 8px; }
        .cabeza { display: flex; justify-content: space-between; gap: 24px; border-bottom: 2px solid #111; padding-bottom: 12px; }
        h1 { margin: 0 0 4px; font-size: 18px; }
        .doc { text-align: right; }
        .doc .tipo { font-size: 16px; font-weight: 800; letter-spacing: .04em; }
        .doc .num { font-family: ui-monospace, monospace; font-size: 15px; font-weight: 700; }
        .muted { color: #4b5563; }
        .aviso { margin-top: 6px; font-weight: 700; color: #b91c1c; }
        .copia { display: inline-block; margin-top: 4px; padding: 1px 8px; border: 1.5px solid #111; font-weight: 800; letter-spacing: .08em; }
        .datos { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; margin: 14px 0; }
        .nota { margin: 10px 0; padding: 8px 10px; border: 1px solid #f59e0b; border-radius: 6px; background: #fffbeb; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #4b5563; }
        .num-c { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .cant { font-size: 15px; font-weight: 800; }
        .marca { width: 1%; text-align: center; }
        .caja { display: inline-block; width: 14px; height: 14px; border: 1.5px solid #111; border-radius: 2px; }
        .texto { margin-top: 12px; }
        .firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 56px; }
        .firmas div { border-top: 1px solid #111; padding-top: 4px; font-size: 12px; }
        .pie { margin-top: 18px; font-size: 11px; color: #4b5563; }
        @media print {
            body { background: #fff; }
            .barra { display: none; }
            .hoja { margin: 0; padding: 0; max-width: none; border-radius: 0; break-after: page; }
            .hoja:last-child { break-after: auto; }
        }
    </style>
</head>
<body>
    <div class="barra"><button type="button" onclick="window.print()">Imprimir</button></div>

    @foreach ($ordenes as $orden)
        @php
            $devolucion = $orden->devolucion;
            $factura = $devolucion->factura;
            $cliente = $orden->cliente;
            // Se le quedó debiendo: el cambio fue de otro cliente y a este se le lleva lo de su pedido.
            $deOtro = $orden->pedido_id !== null && $orden->pedido_id !== $devolucion->pedido_id;
        @endphp
        <div class="hoja">
            <div class="cabeza">
                <div>
                    <h1>{{ $fiscales->nombreParaMostrar() }}</h1>
                    @if ($fiscales->rtn)
                        <div><b>RTN:</b> {{ $fiscales->rtn }}</div>
                    @endif
                    @if ($fiscales->direccion)
                        <div><b>Casa matriz:</b> {{ $fiscales->direccion }}</div>
                    @endif
                    <div><b>Bodega:</b> {{ $orden->bodega->nombre }}</div>
                </div>
                <div class="doc">
                    <div class="tipo">ORDEN DE CAMBIO</div>
                    <div class="num">{{ $orden->numero }}</div>
                    <div class="aviso">DOCUMENTO INTERNO · SIN VALOR FISCAL</div>
                    <div>Fecha: {{ $orden->created_at?->format('d/m/Y') }}</div>
                    @if ($orden->impresiones > 1)
                        <div class="copia">COPIA</div>
                    @endif
                </div>
            </div>

            <div class="datos">
                <div><b>Cliente:</b> {{ $cliente->nombre }}</div>
                <div><b>RTN:</b> {{ $cliente->rtn ?: 'Consumidor final' }}</div>
                <div><b>Municipio:</b> {{ $cliente->municipio->nombre ?? '—' }}</div>
                <div><b>Ruta · vendedor:</b> {{ $orden->deRuta() ?? '—' }}</div>
                @if ($cliente->direccion)
                    <div style="grid-column: 1 / -1"><b>Dirección:</b> {{ $cliente->direccion }}</div>
                @endif
                @if ($deOtro)
                    <div><b>Su pedido:</b> {{ $orden->pedido?->numero }}</div>
                    <div><b>Cambio de:</b> {{ $devolucion->cliente->nombre }} · {{ $devolucion->numero }}</div>
                @else
                    <div><b>{{ $devolucion->falto ? 'Faltante' : 'Devolución' }}:</b> {{ $devolucion->numero }} del {{ $devolucion->fecha->format('d/m/Y') }}</div>
                    <div><b>Factura:</b> {{ $factura?->numero ?? '—' }}</div>
                @endif
                <div style="grid-column: 1 / -1"><b>Motivo:</b> {{ $devolucion->motivo ?? 'Cambio de producto' }}</div>
            </div>

            @if ($orden->nota)
                <div class="nota">{{ $orden->nota }}</div>
            @endif

            <table>
                <thead>
                    <tr>
                        <th class="num-c">Cant.</th>
                        <th>Pres.</th>
                        <th>Producto</th>
                        <th>Código</th>
                        <th class="marca">Entregado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orden->productos() as $fila)
                        <tr>
                            <td class="num-c cant">{{ number_format($fila['cantidad']) }}</td>
                            <td>{{ $fila['presentacion']->sigla() }}</td>
                            <td>{{ $fila['presentacion']->producto->descripcion }}</td>
                            <td class="muted">{{ $fila['presentacion']->producto->codigo }}</td>
                            <td class="marca"><span class="caja"></span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="texto">
                @if ($deOtro)
                    Se le entrega lo que se le quedó debiendo de su pedido {{ $orden->pedido?->numero }}: su producto se le dio a otro cliente en el cambio {{ $devolucion->numero }}. Su factura no cambia de valor.
                @elseif ($devolucion->falto)
                    Se le entrega lo que le faltó en la entrega de su factura{{ $factura ? ' '.$factura->numero : '' }} ({{ $devolucion->numero }}). La factura no cambia de valor.
                @else
                    Cambio por cambio: se le entrega lo mismo que devolvió en la devolución {{ $devolucion->numero }}{{ $factura ? ' (factura '.$factura->numero.')' : '' }}. La factura no cambia de valor.
                @endif
            </div>

            <div class="firmas">
                <div>Entregó (nombre y firma)</div>
                <div>Recibió conforme (nombre, identidad, firma y fecha)</div>
            </div>

            <div class="pie">
                Original: bodega (regresa firmada con la liquidación de la carga) · Copia: cliente. Este documento no sustituye a la factura ni a la nota de crédito.
            </div>
        </div>
    @endforeach
</body>
</html>
