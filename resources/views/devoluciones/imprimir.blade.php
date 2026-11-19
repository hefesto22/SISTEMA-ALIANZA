{{--
    Hoja de una devolución (ImprimirDevolucionController). Con nota de crédito
    sale como NOTA DE CRÉDITO con lo que pide el Acuerdo 481-2017 (Art. 25 y
    26): datos del emisor, CAI, número, rango y fecha límite, cliente y RTN,
    la factura que modifica (número, CAI y fecha), el motivo, las unidades,
    el desglose de importes, el total en letras y quién la recibe. Sin nota
    sale como CONSTANCIA DE DEVOLUCIÓN, sin valor fiscal.
    Datos: $devolucion (con sus relaciones) y $fiscales (DatosFiscales).
--}}
@php
    /** @var \App\Models\Devolucion $devolucion */
    /** @var \App\Models\DatosFiscales $fiscales */
    $nota = $devolucion->conNota();
    $titulo = $nota ? "Nota de crédito {$devolucion->nota_numero}" : "Devolución {$devolucion->numero}";
    $factura = $devolucion->factura;
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
        .datos { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; margin: 14px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #4b5563; }
        .num-c { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .danado { color: #b91c1c; font-weight: 600; }
        .totales { margin-left: auto; width: 300px; margin-top: 10px; }
        .totales div { display: flex; justify-content: space-between; padding: 2px 0; }
        .totales .total { font-weight: 800; font-size: 15px; border-top: 1px solid #111; margin-top: 4px; padding-top: 4px; }
        .letras { margin-top: 8px; font-weight: 600; }
        .firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 48px; }
        .firmas div { border-top: 1px solid #111; padding-top: 4px; font-size: 12px; }
        .pie { margin-top: 18px; font-size: 11px; color: #4b5563; }
        @media print {
            body { background: #fff; }
            .barra { display: none; }
            .hoja { margin: 0; padding: 0; max-width: none; border-radius: 0; }
        }
    </style>
</head>
<body>
    <div class="barra"><button type="button" onclick="window.print()">Imprimir</button></div>

    <div class="hoja">
        <div class="cabeza">
            <div>
                <h1>{{ $fiscales->nombreParaMostrar() }}</h1>
                @if ($fiscales->razon_social && $fiscales->razon_social !== $fiscales->nombreParaMostrar())
                    <div>{{ $fiscales->razon_social }}</div>
                @endif
                @if ($fiscales->rtn)
                    <div><b>RTN:</b> {{ $fiscales->rtn }}</div>
                @endif
                @if ($fiscales->direccion)
                    <div><b>Casa matriz:</b> {{ $fiscales->direccion }}</div>
                @endif
                <div><b>Bodega:</b> {{ $devolucion->bodega->nombre }}</div>
                <div class="muted">{{ collect([$fiscales->telefono ? 'Tel. '.$fiscales->telefono : null, $fiscales->correo])->filter()->implode(' · ') }}</div>
            </div>
            <div class="doc">
                @if ($nota)
                    <div class="tipo">NOTA DE CRÉDITO</div>
                    <div class="num">{{ $devolucion->nota_numero }}</div>
                    <div><b>CAI:</b> {{ $devolucion->cai }}</div>
                    <div>Rango autorizado: {{ $devolucion->rango_desde }} al {{ $devolucion->rango_hasta }}</div>
                    <div>Fecha límite de emisión: {{ $devolucion->fecha_limite?->format('d/m/Y') }}</div>
                @else
                    <div class="tipo">CONSTANCIA DE DEVOLUCIÓN</div>
                    <div class="num">{{ $devolucion->numero }}</div>
                    <div class="aviso">SIN VALOR FISCAL</div>
                @endif
                <div>Fecha: {{ $devolucion->fecha->format('d/m/Y') }}</div>
            </div>
        </div>

        <div class="datos">
            <div><b>Cliente:</b> {{ $devolucion->cliente_nombre ?? $devolucion->cliente->nombre }}</div>
            <div><b>RTN:</b> {{ ($devolucion->cliente_rtn ?? $devolucion->cliente->rtn) ?: 'Consumidor final' }}</div>
            <div><b>Pedido:</b> {{ $devolucion->pedido->numero }} · {{ $devolucion->numero }}</div>
            <div><b>Tipo:</b> {{ $devolucion->tipo->getLabel() }}{{ $devolucion->tipo === \App\Enums\TipoDevolucion::Cambio ? ' (se repone lo mismo)' : '' }}</div>
            @if ($factura)
                <div><b>Factura que modifica:</b> {{ $factura->numero }}</div>
                <div><b>CAI de la factura:</b> {{ $factura->cai }} · {{ $factura->fecha_emision->format('d/m/Y') }}</div>
            @endif
            <div style="grid-column: 1 / -1"><b>Motivo:</b> {{ $devolucion->motivo ?? 'Devolución de producto' }}</div>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="num-c">Cant.</th>
                    <th>Pres.</th>
                    <th>Producto</th>
                    <th>Estado</th>
                    <th class="num-c">Precio</th>
                    <th class="num-c">Importe</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($devolucion->items as $item)
                    <tr>
                        <td class="num-c">{{ $item->cantidad }}</td>
                        <td>{{ $item->presentacion->sigla() }}</td>
                        <td>{{ $item->producto->descripcion }}<br><span class="muted">{{ $item->producto->codigo }}{{ $item->gravado ? '' : ' · exento' }}</span></td>
                        <td @class(['danado' => $item->danado])>{{ $item->danado ? 'Dañado' : 'Bueno' }}</td>
                        <td class="num-c">{{ lempiras($item->precio) }}</td>
                        <td class="num-c">{{ lempiras($item->subtotal) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totales">
            <div><span>Importe exento</span><span>{{ lempiras($devolucion->importe_exento) }}</span></div>
            <div><span>Importe gravado 15%</span><span>{{ lempiras($devolucion->importe_gravado) }}</span></div>
            <div><span>ISV 15%</span><span>{{ lempiras($devolucion->isv) }}</span></div>
            <div class="total"><span>{{ $devolucion->tipo === \App\Enums\TipoDevolucion::Credito ? 'Total del crédito' : 'Valor de lo repuesto' }}</span><span>{{ lempiras($devolucion->total) }}</span></div>
        </div>
        <div class="letras">SON: {{ \App\Support\NumeroALetras::lempiras($devolucion->total) }}</div>

        <div class="firmas">
            <div>Entregó: {{ $devolucion->usuario?->name }}</div>
            <div>Recibió (nombre, identidad y firma)</div>
        </div>

        <div class="pie">
            Moneda: lempiras. {{ $nota ? 'Original: cliente · Copia: emisor.' : 'Este documento no sustituye a la nota de crédito.' }}
        </div>
    </div>
</body>
</html>
