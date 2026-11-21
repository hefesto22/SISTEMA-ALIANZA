{{--
    Factura(s) del pedido para imprimir. Una sola (ImprimirPedidoController) o
    varias de un solo, una por página (ImprimirPedidosController).
    Datos: $pedidos (pedidos con sus relaciones), $titulo, $fiscales (DatosFiscales),
    $firmado (el enlace viene firmado de la acción Imprimir) y $originales (ids
    de los pedidos que salen como ORIGINAL). Sin firma, toda factura sale
    "COPIA · SIN VALOR FISCAL"; firmada y no original, "REIMPRESIÓN · COPIA".

    Diseño de la factura de Distribuciones Alianza en papel continuo de media
    carta (8.5 × 5.5 pulgadas, @page): emisor arriba; factura y cliente a la
    izquierda, CAI, fecha y forma de pago a la derecha; datos del adquiriente
    exonerado; el detalle (Cant., Presentación, Código, Descripción, Precio
    Unit., Desc., I.S.V. y Total con ISV, G gravado / E exento) hasta "Fin del
    Detalle de la Factura"; abajo observaciones, total en letras, rango
    autorizado, fecha límite, firma y los importes (Acuerdo 481-2017). Si el
    detalle no cabe sigue en otra hoja (el encabezado de la tabla se repite).

    Sin factura (pedido pendiente, o el vendedor viendo uno que bodega no ha
    impreso) sale como PEDIDO, sin valor fiscal.

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
        @page { size: 8.5in 5.5in; margin: 0.3in 0.35in; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "Helvetica Neue", Helvetica, sans-serif; color: #000; margin: 0; background: #e5e7eb; font-size: 8.6pt; line-height: 1.3; }
        .barra { max-width: 8.5in; margin: 16px auto 0; display: flex; gap: 8px; align-items: center; font-size: 14px; }
        .barra .cuantos { margin-right: auto; color: #4b5563; }
        .barra button { font: inherit; padding: 8px 14px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; }
        .barra .principal { background: #111; color: #fff; border-color: #111; }

        .hoja { position: relative; width: 8.5in; min-height: 5.5in; margin: 16px auto 24px; padding: 0.3in 0.35in; background: #fff; box-shadow: 0 1px 4px rgb(0 0 0 / 0.15); display: flex; flex-direction: column; }
        b, .etiqueta { font-weight: 700; }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .chico { font-size: 7.4pt; }

        /* Emisor */
        .emisor { text-align: center; }
        .emisor h1 { margin: 0; font-size: 14pt; line-height: 1.2; }
        .emisor .comercial { font-size: 8pt; letter-spacing: 0.04em; }
        .renglon { display: flex; flex-wrap: wrap; justify-content: center; column-gap: 18px; }
        .documento { position: absolute; top: 0.3in; right: 0.35in; text-align: right; }
        .documento .tipo { display: inline-block; border: 1.5px solid #000; padding: 1px 8px; font-weight: 800; letter-spacing: 0.12em; font-size: 9pt; }
        .documento .sello { display: block; margin-top: 3px; font-weight: 800; font-size: 7.4pt; letter-spacing: 0.06em; }
        .documento .estado { display: block; margin-top: 3px; font-size: 7.4pt; }

        /* Factura y cliente | CAI, fecha y forma de pago */
        .datos { display: grid; grid-template-columns: 1fr 1fr; gap: 0 22px; margin-top: 6px; }
        .datos .fila { display: grid; grid-template-columns: 0.8in 1fr; }
        .datos .derecha .fila { grid-template-columns: 0.95in 1fr; }
        .datos .numero { font-weight: 700; font-size: 9.6pt; }
        .cai { white-space: nowrap; font-size: 8.2pt; }
        .sustituye { margin-top: 4px; border: 1.5px solid #000; padding: 2px 6px; font-weight: 700; }
        .exonerado { margin-top: 4px; }
        .exonerado .campos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-top: 1px; }
        .exonerado .campos div { display: flex; gap: 4px; white-space: nowrap; }
        .exonerado .campos span { flex: 1; border-bottom: 1px solid #000; min-width: 0.4in; }

        /* Detalle */
        table.detalle { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .detalle th { font-weight: 700; text-align: left; padding: 2px 3px; border-bottom: 1px solid #000; white-space: nowrap; }
        .detalle td { padding: 1.5px 3px; vertical-align: top; }
        .detalle .letra { width: 12px; padding-left: 2px; text-align: left; }
        .detalle tr { break-inside: avoid; }
        .detalle .nota { display: block; font-size: 7.2pt; }
        .tachado td { text-decoration: line-through; }
        .tachado td .nota { text-decoration: none; }
        .fin { display: flex; align-items: center; gap: 8px; margin: 4px 0.6in 0; font-size: 7.4pt; }
        .fin::before, .fin::after { content: ""; flex: 1; border-top: 1px dashed #000; }

        /* Pie: observaciones, letras y autorización | firma | importes */
        .pie { margin-top: auto; padding-top: 10px; display: grid; grid-template-columns: 1fr 1.25in 2.1in; gap: 0 16px; align-items: end; break-inside: avoid; }
        .pie .fila { display: grid; grid-template-columns: auto 1fr; gap: 0 6px; }
        .pie .rango { white-space: nowrap; }
        .observaciones span { border-bottom: 1px solid #000; min-height: 1.2em; }
        .letras { margin: 2px 0; font-weight: 700; }
        .firma { text-align: center; border-top: 1px solid #000; padding-top: 2px; margin-bottom: 0.45in; }
        .totales { width: 100%; border-collapse: collapse; }
        .totales td { padding: 0.5px 0; }
        .totales .total td { font-weight: 800; font-size: 10pt; border-top: 1.5px solid #000; padding-top: 2px; }

        @media print {
            body { background: #fff; }
            .barra { display: none; }
            .hoja { width: auto; min-height: 4.88in; margin: 0; padding: 0; box-shadow: none; }
            .documento { top: 0; right: 0; }
            .hoja + .hoja { break-before: page; }
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

        [$exento, $gravado] = $factura !== null
            ? [$factura->importe_exento, $factura->importe_gravado]
            : \App\Domain\Facturacion\EmitirFactura::importes($pedido);
        $descuento = $factura->descuento ?? $pedido->descuento;
        $isv = $factura->isv ?? $pedido->isv;
        $total = $factura->total ?? $pedido->total;

        $razon = $fiscales->razon_social ?: $fiscales->nombreParaMostrar();
        $comercial = $fiscales->nombre_comercial && $fiscales->nombre_comercial !== $razon ? $fiscales->nombre_comercial : null;
        $bodega = $pedido->bodega;
        $municipioBodega = $bodega->municipio && ! str_contains(mb_strtoupper($bodega->nombre), mb_strtoupper($bodega->municipio->nombre)) ? $bodega->municipio->etiqueta() : null;
        $establecimiento = collect([$bodega->nombre, $bodega->direccion, $municipioBodega])->filter()->implode(', ');
        $ruta = \App\Models\Ruta::delPedido($pedido);
        $cliente = $pedido->cliente;
        $direccion = collect([$cliente->direccion, $cliente->municipio?->nombre, $cliente->municipio?->departamento?->nombre])->filter()->implode(', ');
        $fecha = $factura ? $factura->fecha_emision->copy()->locale('es')->isoFormat('DD/MM/YYYY hh:mm:ss a') : $pedido->fecha->format('d/m/Y');
    @endphp

    <div class="hoja">
        <div class="documento">
            <span class="tipo">{{ $factura ? 'FACTURA' : 'PEDIDO' }}</span>
            @if ($factura)
                @if ($esOriginal)
                    {{-- Original: sin sello. --}}
                @elseif ($firmado ?? false)
                    <span class="sello">REIMPRESIÓN · COPIA</span>
                @else
                    <span class="sello">COPIA · SIN VALOR FISCAL</span>
                @endif
            @else
                <span class="estado">{{ $pedido->estado->getLabel() }} · {{ $validado ? 'sin factura todavía' : 'vista previa' }}</span>
                <span class="sello">SIN VALOR FISCAL</span>
            @endif
        </div>

        <div class="emisor">
            <h1>{{ $razon }}</h1>
            @if ($comercial)
                <div class="comercial">{{ $comercial }}</div>
            @endif
            <div class="renglon">
                @if ($fiscales->rtn)
                    <span><b>RTN: {{ $fiscales->rtn }}</b></span>
                @endif
                @if ($fiscales->direccion)
                    <span>{{ $fiscales->direccion }}</span>
                @endif
            </div>
            <div class="renglon">
                @if ($fiscales->telefono)
                    <span><b>Tel:</b> {{ $fiscales->telefono }}</span>
                @endif
                @if ($fiscales->correo)
                    <span><b>Correo:</b> {{ $fiscales->correo }}</span>
                @endif
                @if ($ruta)
                    <span><b>Ruta:</b> {{ \Illuminate\Support\Str::limit($ruta->nombre, 34) }}</span>
                @endif
                <span><b>Vendedor:</b> {{ $pedido->vendedor->name }}</span>
            </div>
            <div class="renglon chico"><span><b>Establecimiento:</b> {{ $establecimiento }}</span></div>
        </div>

        <div class="datos">
            <div>
                @if ($factura)
                    <div class="fila"><span class="etiqueta">Factura:</span><span class="numero">{{ $factura->numero }}</span></div>
                @else
                    <div class="fila"><span class="etiqueta">Pedido:</span><span class="numero">{{ $pedido->numero }}</span></div>
                @endif
                <div class="fila"><span class="etiqueta">Cliente:</span><span>{{ $cliente->codigo }} {{ $factura->cliente_nombre ?? $cliente->nombre }}</span></div>
                <div class="fila"><span class="etiqueta">Teléfono:</span><span>{{ $cliente->telefono }}</span></div>
                <div class="fila"><span class="etiqueta">R.T.N.:</span><span>{{ ($factura ? $factura->cliente_rtn : $cliente->rtn) ?: '-' }}</span></div>
                <div class="fila"><span class="etiqueta">Dirección:</span><span>{{ $direccion }}</span></div>
            </div>
            <div class="derecha">
                @if ($factura)
                    <div class="fila"><span class="etiqueta">C.A.I.:</span><span class="cai">{{ $factura->cai }}</span></div>
                @endif
                <div class="fila"><span class="etiqueta">Fecha:</span><span>{{ $fecha }}</span></div>
                <div class="fila"><span class="etiqueta">Forma de Pago:</span><span>CONTADO</span></div>
                @if ($factura)
                    <div class="fila"><span class="etiqueta">Pedido:</span><span>{{ $pedido->numero }} · {{ $pedido->fecha->format('d/m/Y') }}</span></div>
                @endif
                @if ($pedido->fuera_de_ruta)
                    <div class="fila"><span class="etiqueta">Fuera de ruta:</span><span>{{ $pedido->motivo_fuera_ruta }}</span></div>
                @endif
            </div>
        </div>

        @if ($factura?->reemplazaA)
            <div class="sustituye">
                Sustituye a la factura N° {{ $factura->reemplazaA->numero }}, ANULADA el {{ $factura->reemplazaA->anulada_at?->format('d/m/Y') }}.
                Retire esa hoja al cliente y márquela ANULADA.
            </div>
        @endif

        @if ($factura)
            <div class="exonerado">
                <b>Datos del Adquiriente Exonerado:</b>
                <div class="campos">
                    <div><b>N° Orden de Compra Exenta:</b><span></span></div>
                    <div><b>N° Constancia de Registro de Exonerados:</b><span></span></div>
                    <div><b>N° Registro SAG:</b><span></span></div>
                </div>
            </div>
        @endif

        <table class="detalle">
            <thead>
            <tr>
                <th class="num">{{ $validado ? 'Cant.' : 'Cant. est.' }}</th>
                <th>Presentación</th>
                <th>Código</th>
                <th>Descripción del Producto</th>
                <th class="num">Precio Unit.</th>
                <th class="num">Desc.</th>
                <th class="num">I.S.V.</th>
                <th class="num">Total</th>
                <th class="letra"></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($pedido->items as $item)
                @php
                    $cantidad = $validado ? $item->cantidadFacturable() : $item->cantidad;
                @endphp
                <tr @class(['tachado' => $validado && $item->cantidad_despachada === 0])>
                    <td class="num">{{ $cantidad }}</td>
                    <td>{{ mb_strtoupper($item->presentacion->palabra(max(1, $cantidad))) }}</td>
                    <td>{{ $item->producto->codigo }}</td>
                    <td>
                        {{ $item->producto->descripcion }}
                        @if ($validado && ($falta = $item->faltaEnPalabras()))
                            <span class="nota">{{ $falta }}</span>
                        @elseif ($validado && ($mas = $item->deMasEnPalabras()))
                            <span class="nota">{{ $mas }}</span>
                        @endif
                        @if ($item->promocion)
                            <span class="nota">{{ $item->bonificacion ? 'Regalo de la promoción' : 'Promoción' }} {{ $item->promocion->codigo }}</span>
                        @endif
                    </td>
                    <td class="num">{{ number_format($item->precio, 2) }}</td>
                    <td class="num">{{ number_format($item->descuento, 2) }}</td>
                    <td class="num">{{ number_format($item->isv, 2) }}</td>
                    <td class="num">{{ number_format($item->total, 2) }}</td>
                    <td class="letra">{{ $item->gravado ? 'G' : 'E' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="fin">Fin del Detalle de la {{ $factura ? 'Factura' : 'Pedido' }}</div>

        <div class="pie">
            <div>
                <div class="fila observaciones"><span class="etiqueta">Observaciones:</span><span>{{ $pedido->notas }}</span></div>
                <div class="letras">Total: {{ \App\Support\NumeroALetras::lempiras($total) }}</div>
                @if ($factura)
                    <div class="fila"><span class="etiqueta">Rango Autorizado:</span><span class="rango">del {{ $factura->rangoAutorizado() }}</span></div>
                    <div class="fila"><span class="etiqueta">Fecha Límite:</span><span>{{ $factura->fecha_limite->format('d/m/Y') }}</span></div>
                    <div>{{ $fiscales->leyenda ?: 'La Factura Es Un Derecho, Exíjala' }}</div>
                    <div class="chico">Original: Cliente &nbsp;-&nbsp; Copia: Emisor &nbsp;·&nbsp; G = gravado 15 %, E = exento</div>
                @else
                    <div class="chico">
                        @unless ($validado)
                            Pedido pendiente: totales con los precios de hoy; bodega confirma cantidades y precio al validar.
                        @endunless
                        G = gravado 15 %, E = exento
                    </div>
                @endif
            </div>

            <div class="firma">Firma</div>

            <table class="totales">
                <tr><td>Importe Exonerado: L.</td><td class="num">{{ number_format(0, 2) }}</td></tr>
                <tr><td>Importe Gravado (15%): L.</td><td class="num">{{ number_format($gravado, 2) }}</td></tr>
                <tr><td>Importe Gravado (18%): L.</td><td class="num">{{ number_format(0, 2) }}</td></tr>
                <tr><td>Importe Exento: L.</td><td class="num">{{ number_format($exento, 2) }}</td></tr>
                <tr><td>Sub Total: L.</td><td class="num">{{ number_format($exento + $gravado + $descuento, 2) }}</td></tr>
                <tr><td>Descuento: L.</td><td class="num">{{ number_format($descuento, 2) }}</td></tr>
                <tr><td>Impuesto (15%): L.</td><td class="num">{{ number_format($isv, 2) }}</td></tr>
                <tr><td>Impuesto (18%): L.</td><td class="num">{{ number_format(0, 2) }}</td></tr>
                <tr class="total"><td>Total: L.</td><td class="num">{{ number_format($total, 2) }}</td></tr>
            </table>
        </div>
    </div>
@endforeach
</body>
</html>
