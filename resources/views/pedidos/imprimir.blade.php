{{--
    Factura(s) del pedido para imprimir. Una sola (ImprimirPedidoController) o
    varias de un solo, una por página (ImprimirPedidosController).
    Datos: $pedidos (pedidos con sus relaciones), $titulo, $fiscales (DatosFiscales),
    $firmado (el enlace viene firmado de la acción Imprimir) y $originales (ids
    de los pedidos que salen como ORIGINAL). Sin firma, toda factura sale
    "COPIA · SIN VALOR FISCAL"; firmada y no original, "REIMPRESIÓN · COPIA".

    Con factura vigente sale como FACTURA con los requisitos del Acuerdo
    481-2017 (datos del emisor, CAI, número, rango autorizado, fecha límite,
    desglose de importes, total en letras). Sin factura (pedido pendiente, o
    el vendedor viendo uno que bodega no ha impreso) sale como PEDIDO, sin
    valor fiscal.

    Desde el panel se carga en un iframe oculto y se imprime ahí mismo
    (resources/views/filament/imprimir-hoja.blade.php).
--}}
<!DOCTYPE html>
<html lang="es" translate="no">
<head>
    <meta charset="utf-8">
    <meta name="google" content="notranslate">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #111; margin: 0; background: #f3f4f6; }
        .hoja { max-width: 840px; margin: 24px auto; background: #fff; padding: 28px 32px; border-radius: 8px; }
        .barra { max-width: 840px; margin: 16px auto 0; display: flex; gap: 8px; align-items: center; }
        .barra .cuantos { margin-right: auto; color: #4b5563; font-size: 14px; }
        .barra button { font: inherit; padding: 8px 14px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; }
        .barra .principal { background: #111; color: #fff; border-color: #111; }

        header { display: grid; grid-template-columns: 1fr auto; gap: 24px; border-bottom: 2px solid #111; padding-bottom: 12px; margin-bottom: 12px; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        .emisor { font-size: 12px; line-height: 1.45; color: #374151; }
        .emisor .razon { font-weight: 600; color: #111; }
        .documento { text-align: right; font-size: 12px; line-height: 1.5; min-width: 290px; }
        .documento .tipo { font-size: 20px; font-weight: 800; letter-spacing: 0.06em; }
        .documento .numero { font-size: 16px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .documento .cai { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11px; }
        .muted { color: #6b7280; }
        .estado { display: inline-block; margin-top: 4px; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; background: #fef3c7; color: #92400e; }
        .estado.anulado { background: #e5e7eb; color: #374151; }
        .reimpresion { display: inline-block; margin-top: 4px; padding: 2px 10px; border: 2px solid #111; font-size: 11px; font-weight: 800; letter-spacing: 0.08em; }
        .sustituye { border: 2px solid #111; padding: 8px 12px; margin-bottom: 12px; font-size: 12px; font-weight: 600; }

        .datos { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; font-size: 13px; margin-bottom: 12px; }
        .datos b { display: inline-block; min-width: 82px; color: #374151; }

        table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        th { text-align: left; border-bottom: 1px solid #111; padding: 5px 4px; font-weight: 600; }
        td { border-bottom: 1px solid #e5e7eb; padding: 5px 4px; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .falta { color: #b91c1c; font-weight: 600; }
        .tachado { color: #9ca3af; text-decoration: line-through; }
        .promo { color: #15803d; }

        .pie { display: grid; grid-template-columns: 1fr 300px; gap: 24px; margin-top: 12px; }
        .exonerados { font-size: 11px; color: #374151; display: grid; gap: 6px; }
        .exonerados div { display: flex; gap: 6px; align-items: flex-end; }
        .exonerados span { flex: 1; min-width: 60px; border-bottom: 1px solid #9ca3af; height: 1em; }
        .letras { margin-top: 8px; font-size: 12px; font-weight: 600; }
        .totales { font-size: 12.5px; }
        .totales td { border: 0; padding: 2px 4px; }
        .totales .total td { font-size: 16px; font-weight: 700; border-top: 2px solid #111; padding-top: 6px; }

        .autorizacion { margin-top: 14px; padding-top: 8px; border-top: 1px solid #d1d5db; font-size: 11px; line-height: 1.5; color: #374151; }
        .nota { margin-top: 10px; font-size: 11.5px; color: #4b5563; }
        .firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 44px; font-size: 12px; text-align: center; }
        .firmas div { border-top: 1px solid #111; padding-top: 6px; }

        @media print {
            body { background: #fff; }
            .barra { display: none; }
            .hoja { margin: 0; padding: 0; max-width: none; border-radius: 0; }
            .hoja + .hoja { break-before: page; page-break-before: always; }
        }
    </style>
</head>
<body>
<div class="barra">
    <span class="cuantos">{{ $titulo }}{{ $pedidos->count() > 1 ? ', una por página' : '' }}</span>
    <button type="button" onclick="window.close()">Cerrar</button>
    <button type="button" class="principal" onclick="window.print()">Imprimir</button>
</div>

@foreach ($pedidos as $pedido)
    @php
        /** @var \App\Models\Pedido $pedido */
        /** @var \App\Models\DatosFiscales $fiscales */
        $factura = $pedido->factura;
        $esOriginal = ($firmado ?? false) && in_array($pedido->id, $originales ?? [], true);
        $validado = $pedido->estaValidado();
        $conPromo = $pedido->descuento > 0;
        $promociones = $pedido->items->pluck('promocion')->filter()->unique('id');

        [$exento, $gravado] = $factura !== null
            ? [$factura->importe_exento, $factura->importe_gravado]
            : \App\Domain\Facturacion\EmitirFactura::importes($pedido);
        $descuento = $factura->descuento ?? $pedido->descuento;
        $isv = $factura->isv ?? $pedido->isv;
        $total = $factura->total ?? $pedido->total;

        $bodega = $pedido->bodega;
        $establecimiento = collect([$bodega->nombre, $bodega->direccion, $bodega->municipio?->etiqueta()])->filter()->implode(', ');
        $contacto = collect([
            $fiscales->telefono ? 'Tel. '.$fiscales->telefono : null,
            $bodega->telefono && $bodega->telefono !== $fiscales->telefono ? 'Bodega '.$bodega->telefono : null,
            $fiscales->correo,
        ])->filter()->implode(' · ');
    @endphp

    <div class="hoja">
        <header>
            <div class="emisor">
                <h1>{{ $fiscales->nombreParaMostrar() }}</h1>
                @if ($fiscales->razon_social && $fiscales->razon_social !== $fiscales->nombreParaMostrar())
                    <div class="razon">{{ $fiscales->razon_social }}</div>
                @endif
                @if ($fiscales->rtn)
                    <div><b>RTN:</b> {{ $fiscales->rtn }}</div>
                @endif
                @if ($fiscales->direccion)
                    <div><b>Casa matriz:</b> {{ $fiscales->direccion }}</div>
                @endif
                <div><b>Establecimiento:</b> {{ $establecimiento }}</div>
                @if ($contacto)
                    <div>{{ $contacto }}</div>
                @endif
            </div>

            <div class="documento">
                @if ($factura)
                    <div class="tipo">FACTURA</div>
                    <div class="numero">N° {{ $factura->numero }}</div>
                    <div>Fecha de emisión: {{ $factura->fecha_emision->format('d/m/Y H:i') }}</div>
                    <div class="cai">CAI: {{ $factura->cai }}</div>
                    @if ($esOriginal)
                        {{-- Original: sin sello. --}}
                    @elseif ($firmado ?? false)
                        <span class="reimpresion">REIMPRESIÓN · COPIA</span>
                    @else
                        <span class="reimpresion">COPIA · SIN VALOR FISCAL</span>
                    @endif
                @else
                    <div class="tipo">PEDIDO</div>
                    <div class="numero">{{ $pedido->numero }}</div>
                    <div>{{ $pedido->fecha->format('d/m/Y') }}</div>
                    <span class="estado {{ $pedido->estado->value }}">
                        {{ $pedido->estado->getLabel() }} · {{ $validado ? 'sin factura todavía' : 'vista previa' }}
                    </span>
                    <div class="muted">Sin valor fiscal</div>
                @endif
            </div>
        </header>

        @if ($factura?->reemplazaA)
            <div class="sustituye">
                Sustituye a la factura N° {{ $factura->reemplazaA->numero }}, ANULADA el {{ $factura->reemplazaA->anulada_at?->format('d/m/Y') }}.
                Retire esa hoja al cliente y márquela ANULADA.
            </div>
        @endif

        <div class="datos">
            <div><b>Cliente:</b> {{ $factura->cliente_nombre ?? $pedido->cliente->nombre }}@if ($pedido->cliente->codigo) ({{ $pedido->cliente->codigo }})@endif</div>
            <div><b>RTN:</b> {{ ($factura ? $factura->cliente_rtn : $pedido->cliente->rtn) ?: '—' }}</div>
            <div><b>Dirección:</b> {{ $pedido->cliente->direccion ?? '—' }}@if ($pedido->cliente->municipio) · {{ $pedido->cliente->municipio->nombre }}, {{ $pedido->cliente->municipio->departamento->nombre }}@endif</div>
            <div><b>Teléfono:</b> {{ $pedido->cliente->telefono ?? '—' }}</div>
            <div><b>Vendedor:</b> {{ $pedido->vendedor->name }}@if ($pedido->vendedor->codigo) ({{ $pedido->vendedor->codigo }})@endif</div>
            <div><b>Pedido:</b> {{ $pedido->numero }} · {{ $pedido->fecha->format('d/m/Y') }}</div>
            @if ($pedido->fuera_de_ruta)
                <div><b>Fuera de ruta:</b> {{ $pedido->motivo_fuera_ruta }}</div>
            @endif
            <div><b>Validó:</b> {{ $pedido->validador?->name ?? 'Pendiente de validar' }}@if ($pedido->validado_at) · {{ $pedido->validado_at->format('d/m/Y H:i') }}@endif</div>
        </div>

        <table>
            <thead>
            <tr>
                <th>Código</th>
                <th>Descripción</th>
                <th>En</th>
                <th class="num">Pidió</th>
                <th class="num">{{ $validado ? 'Cant.' : 'Cant. est.' }}</th>
                <th class="num">Precio unit.</th>
                @if ($conPromo)
                    <th class="num">Descuento</th>
                @endif
                <th class="num">Importe</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($pedido->items as $item)
                <tr @class(['tachado' => $validado && $item->cantidad_despachada === 0])>
                    <td>{{ $item->producto->codigo }}</td>
                    <td>{{ $item->producto->descripcion }}@if (! $item->gravado) <span class="muted">(E)</span>@endif</td>
                    <td>{{ $item->unidad->getLabel() }}</td>
                    <td class="num">{{ $item->cantidad }}</td>
                    <td class="num @if ($item->faltante() > 0) falta @endif">{{ $item->cantidadFacturable() }}</td>
                    <td class="num">{{ lempiras($item->precio) }}</td>
                    @if ($conPromo)
                        <td class="num promo">
                            @if ($item->descuento > 0)
                                −{{ lempiras($item->descuento) }}
                                @if ($item->promocion)
                                    <br><span class="muted">{{ $item->promocion->codigo }}</span>
                                @endif
                            @endif
                        </td>
                    @endif
                    <td class="num">{{ lempiras($item->subtotal - $item->descuento) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <div class="pie">
            <div>
                @if ($factura)
                    <div class="exonerados">
                        <div>N° correlativo de orden de compra exenta: <span></span></div>
                        <div>N° correlativo de constancia de registro de exonerado: <span></span></div>
                        <div>N° identificativo del registro de la SAG: <span></span></div>
                    </div>
                @endif
                <div class="letras">SON: {{ \App\Support\NumeroALetras::lempiras($total) }}</div>
                <div class="muted" style="font-size:11px;margin-top:4px">(E) = exento. Precios sin ISV; el ISV se suma al final.</div>
            </div>

            <table class="totales">
                <tr><td>Descuentos y rebajas otorgados</td><td class="num">{{ lempiras($descuento) }}</td></tr>
                <tr><td>Importe exonerado</td><td class="num">{{ lempiras(0) }}</td></tr>
                <tr><td>Importe exento</td><td class="num">{{ lempiras($exento) }}</td></tr>
                <tr><td>Importe gravado 15%</td><td class="num">{{ lempiras($gravado) }}</td></tr>
                <tr><td>Importe gravado 18%</td><td class="num">{{ lempiras(0) }}</td></tr>
                <tr><td>ISV 15%</td><td class="num">{{ lempiras($isv) }}</td></tr>
                <tr><td>ISV 18%</td><td class="num">{{ lempiras(0) }}</td></tr>
                <tr class="total"><td>Total a pagar</td><td class="num">{{ lempiras($total) }}</td></tr>
            </table>
        </div>

        @if ($factura)
            <div class="autorizacion">
                <b>Rango autorizado:</b> del {{ $factura->rangoAutorizado() }}
                · <b>Fecha límite de emisión:</b> {{ $factura->fecha_limite->format('d/m/Y') }}
                <br>Original: Cliente · Copia: Obligado tributario emisor
                @if ($fiscales->leyenda)
                    <br>{{ $fiscales->leyenda }}
                @endif
            </div>
        @endif

        <p class="nota">
            @unless ($validado)
                Pedido pendiente: totales calculados con los precios de hoy. Bodega confirma cantidades y precio al validar.<br>
            @endunless
            @foreach ($promociones as $promocion)
                {{ $promocion->codigo }} · {{ $promocion->nombre }}: {{ $promocion->resumen() }}.<br>
            @endforeach
            @if ($pedido->notas)
                Notas: {{ $pedido->notas }}
            @endif
        </p>

        <div class="firmas">
            <div>Entregó</div>
            <div>Recibió conforme</div>
        </div>
    </div>
@endforeach
</body>
</html>
