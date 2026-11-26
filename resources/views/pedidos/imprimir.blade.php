{{--
    Factura(s) del pedido para imprimir. Una sola (ImprimirPedidoController) o
    varias de un solo, una por página (ImprimirPedidosController).
    Datos: $pedidos (pedidos con sus relaciones), $titulo, $fiscales (DatosFiscales),
    $firmado (el enlace viene firmado de la acción Imprimir), $originales (ids
    de los pedidos que salen como ORIGINAL), $formato (FormatoFactura: el papel),
    $enlaces (la misma hoja en cada papel, para la barra) y $previa (dentro del
    modal de Imprimir: sin barra y a lo ancho del cuadro; una factura todavía
    sin guardar sale marcada VISTA PREVIA). Sin firma, toda factura sale
    "COPIA · SIN VALOR FISCAL"; firmada y no original, "REIMPRESIÓN · COPIA".

    Diseño de la factura de Distribuciones Alianza en dos papeles (@page):
    - Continuo media carta (8.5 × 5.5 pulgadas) para matriciales como la
      Epson LX-350: todo en negro puro y sin letra más chica de 7.6 pt.
    - Carta (8.5 × 11) para impresoras normales: la misma factura con letra
      un poco más grande y los importes al pie de la hoja.
    Emisor arriba; factura y cliente a la izquierda, CAI, fecha y forma de
    pago a la derecha; datos del adquiriente exonerado; el detalle (CJ y UN,
    Código, Descripción, Precio Unit., Desc., I.S.V. y Total con ISV, G
    gravado / E exento; renglones de App\Domain\Facturacion\RenglonFactura)
    hasta "Fin del Detalle de la Factura"; abajo observaciones, total en
    letras, rango autorizado, fecha límite, firma y los importes (Acuerdo
    481-2017). Si el detalle no cabe sigue en otra hoja (el encabezado de la
    tabla se repite).

    Sin factura (pedido pendiente, o el vendedor viendo uno que bodega no ha
    impreso) sale como PEDIDO, sin valor fiscal.

    Desde el panel se carga en un iframe oculto y se imprime ahí mismo
    (resources/views/filament/imprimir-hoja.blade.php).
--}}
@php
    $formato ??= \App\Enums\FormatoFactura::Continuo;
    $enlaces ??= [];
    $previa ??= false;
@endphp
<!DOCTYPE html>
<html lang="es" translate="no">
<head>
    <meta charset="utf-8">
    <meta name="google" content="notranslate">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo }}</title>
    <style>
        @page { size: {{ $formato->pagina() }}; margin: 0; }
        * { box-sizing: border-box; }

        /* Papel continuo media carta (LX-350). */
        body {
            --alto: 5.5in; --margen-arriba: 0.25in; --margen-abajo: 0.2in; --margen-lado: 0.35in;
            --letra: 8.6pt; --chica: 7.8pt; --nota: 7.6pt; --titulo: 13.5pt; --numero: 9.6pt; --cai: 8.2pt; --total: 10pt; --firma: 0.18in; --sello: 1.25in;
        }
        /* Carta (impresora normal). */
        body.carta {
            --alto: 11in; --margen-arriba: 0.5in; --margen-abajo: 0.45in; --margen-lado: 0.45in;
            --letra: 9.4pt; --chica: 8.2pt; --nota: 8pt; --titulo: 15pt; --numero: 10.6pt; --cai: 8.8pt; --total: 11pt; --firma: 0.4in; --sello: 1.4in;
        }

        body { font-family: Arial, "Helvetica Neue", Helvetica, sans-serif; color: #000; margin: 0; background: #e5e7eb; font-size: var(--letra); line-height: 1.2; }
        .barra { max-width: 8.5in; margin: 16px auto 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; font-size: 14px; line-height: 1.3; }
        .barra .cuantos { margin-right: auto; color: #4b5563; }
        .barra button { font: inherit; padding: 8px 14px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; }
        .barra .principal { background: #111; color: #fff; border-color: #111; }
        .papel { display: inline-flex; align-items: center; margin-right: 8px; }
        .papel .que { color: #4b5563; margin-right: 8px; }
        .papel a { padding: 8px 12px; border: 1px solid #d1d5db; background: #fff; color: #111; text-decoration: none; }
        .papel a:first-of-type { border-radius: 6px 0 0 6px; }
        .papel a:last-of-type { border-radius: 0 6px 6px 0; border-left: 0; }
        .papel a.elegido { background: #111; color: #fff; border-color: #111; }
        body.previa .hoja { margin: 12px auto; }

        .hoja { position: relative; width: 8.5in; min-height: var(--alto); margin: 16px auto 24px; padding: var(--margen-arriba) var(--margen-lado) var(--margen-abajo); background: #fff; box-shadow: 0 1px 4px rgb(0 0 0 / 0.15); display: flex; flex-direction: column; }
        b, .etiqueta { font-weight: 700; }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .nw { white-space: nowrap; }
        .chica { font-size: var(--chica); }

        /* Emisor al centro; a la derecha FACTURA o PEDIDO con su sello (a la izquierda el mismo ancho, vacío, para que quede centrado). */
        .encabezado { display: grid; grid-template-columns: var(--sello) 1fr var(--sello); align-items: start; }
        .emisor { text-align: center; }
        .emisor h1 { margin: 0; font-size: var(--titulo); line-height: 1.15; }
        .renglon { display: flex; flex-wrap: wrap; justify-content: center; column-gap: 1.6em; }
        .comercial { font-weight: 700; letter-spacing: 0.08em; }
        .documento { text-align: right; }
        .documento .tipo { display: inline-block; border: 1.5px solid #000; padding: 1px 8px; font-weight: 800; letter-spacing: 0.12em; font-size: var(--numero); }
        .documento .sello { display: block; margin-top: 3px; font-weight: 800; font-size: var(--chica); letter-spacing: 0.02em; }
        .documento .estado { display: block; margin-top: 3px; font-size: var(--chica); }

        /* Factura y cliente | CAI, fecha y forma de pago */
        .datos { display: grid; grid-template-columns: 1.08fr 1fr; gap: 0 2em; margin-top: 5px; }
        .datos .fila { display: grid; grid-template-columns: 6.6em 1fr; align-items: baseline; }
        .datos .derecha .fila { grid-template-columns: 7.9em 1fr; }
        .datos .numero { font-weight: 700; font-size: var(--numero); line-height: 1.1; }
        .datos .provisional { font-size: var(--nota); margin-left: 6px; }
        .datos .aparte { margin-left: 1.4em; white-space: nowrap; }
        .cai { white-space: nowrap; font-size: var(--cai); }
        .sustituye { margin-top: 4px; border: 1.5px solid #000; padding: 2px 6px; font-weight: 700; }
        .exonerado { display: flex; flex-wrap: wrap; align-items: baseline; gap: 2px 10px; margin-top: 4px; white-space: nowrap; }
        .exonerado .campo { flex: 1; display: flex; gap: 4px; }
        .exonerado .campo span { flex: 1; min-width: 0.3in; border-bottom: 1px solid #000; }

        /* Detalle */
        table.detalle { width: 100%; border-collapse: collapse; margin-top: 5px; }
        .detalle th { font-weight: 700; text-align: left; padding: 2px 4px; border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; white-space: nowrap; }
        .detalle td { padding: 0.5px 4px; vertical-align: top; line-height: 1.18; }
        .detalle th:first-child, .detalle td:first-child { padding-left: 1px; }
        .detalle .cant { width: 2.4em; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .detalle .cant small { font-size: var(--nota); }
        .detalle .codigo { white-space: nowrap; font-variant-numeric: tabular-nums; letter-spacing: 0.03em; padding-left: 0.9em; padding-right: 0.9em; }
        .detalle .descripcion { width: 100%; }
        .detalle .letra { width: 1.4em; padding-left: 3px; padding-right: 1px; text-align: left; }
        .detalle tr { break-inside: avoid; }
        .detalle .nota { display: block; font-size: var(--nota); }
        .detalle .precio { display: block; }
        .detalle .precio small { font-size: var(--nota); margin-right: 3px; }
        .regalo .nota { font-weight: 700; }
        .tachado td { text-decoration: line-through; }
        .tachado td .nota { text-decoration: none; }
        .fin { display: flex; align-items: center; gap: 8px; margin: 3px 0.6in 0; font-size: var(--chica); }
        .fin::before, .fin::after { content: ""; flex: 1; border-top: 1px dashed #000; }

        /* Pie: observaciones, letras y autorización | importes y firma | totales */
        .pie { margin-top: auto; padding-top: 4px; display: grid; grid-template-columns: 1fr auto auto; gap: 0 1.6em; align-items: end; break-inside: avoid; }
        .pie .fila { display: grid; grid-template-columns: auto 1fr; gap: 0 5px; }
        .pie .fila .etiqueta { white-space: nowrap; }
        .observaciones span { border-bottom: 1px solid #000; min-height: 1.2em; }
        .letras { margin: 1px 0 2px; font-weight: 700; }
        .firma { text-align: center; border-top: 1px solid #000; margin-top: var(--firma); padding-top: 1px; }
        .totales { border-collapse: collapse; }
        .totales td { padding: 0; }
        .totales td.num { padding-left: 8px; min-width: 5.6em; }
        .totales .total td { font-weight: 800; font-size: var(--total); border-top: 1.5px solid #000; padding-top: 1px; }

        @media print {
            body { background: #fff; }
            .barra { display: none; }
            .hoja { width: auto; min-height: calc(var(--alto) - 0.06in); margin: 0; box-shadow: none; box-decoration-break: clone; -webkit-box-decoration-break: clone; }
            .hoja + .hoja { break-before: page; }
        }
    </style>
</head>
<body class="{{ $formato->value }}{{ $previa ? ' previa' : '' }}">
@if ($previa)
    <script>
        // En el modal la hoja se achica si el cuadro es más angosto que la hoja (8.5 pulgadas).
        (() => {
            const ajustar = () => { document.body.style.zoom = Math.min(1, (document.documentElement.clientWidth - 16) / 816); };
            ajustar();
            addEventListener('resize', ajustar);
        })();
    </script>
@else
    <div class="barra">
        <span class="cuantos">{{ $titulo }}{{ $pedidos->count() > 1 ? ', una por página' : '' }}</span>
        @if ($enlaces !== [])
            <span class="papel">
                <span class="que">Papel:</span>
                @foreach (\App\Enums\FormatoFactura::cases() as $opcion)
                    <a href="{{ $enlaces[$opcion->value] ?? '#' }}" title="{{ $opcion->getDescription() }}" @class(['elegido' => $opcion === $formato])>{{ $opcion->corto() }}</a>
                @endforeach
            </span>
        @endif
        <button type="button" onclick="window.close()">Cerrar</button>
        <button type="button" class="principal" onclick="window.print()">Imprimir</button>
    </div>
@endif

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
        // La ruta en que se vendió (fija desde la primera factura); el pedido sin facturar, la de hoy.
        $ruta = $pedido->ruta ?? \App\Models\Ruta::delPedido($pedido);
        $cliente = $pedido->cliente;
        $direccion = collect([$cliente->direccion, $cliente->municipio?->nombre, $cliente->municipio?->departamento?->nombre])->filter()->implode(', ');
        $fecha = $factura ? $factura->fecha_emision->copy()->locale('es')->isoFormat('DD/MM/YYYY hh:mm:ss a') : $pedido->fecha->format('d/m/Y');
        $renglones = \App\Domain\Facturacion\RenglonFactura::delPedido($pedido, $validado);
    @endphp

    <div class="hoja">
        <div class="encabezado">
            <div></div>
            <div class="emisor">
                <h1>{{ $razon }}</h1>
                <div class="renglon">
                    @if ($comercial)
                        <span class="comercial">{{ $comercial }}</span>
                    @endif
                    @if ($fiscales->rtn)
                        <span><b>RTN: {{ $fiscales->rtn }}</b></span>
                    @endif
                    @if ($fiscales->direccion)
                        <span>{{ $fiscales->direccion }}</span>
                    @endif
                    @if ($fiscales->telefono)
                        <span><b>Tel:</b> {{ $fiscales->telefono }}</span>
                    @endif
                    @if ($fiscales->correo)
                        <span><b>Correo:</b> {{ $fiscales->correo }}</span>
                    @endif
                </div>
                <div class="renglon chica">
                    <span><b>Establecimiento:</b> {{ $establecimiento }}</span>
                </div>
            </div>
            <div class="documento">
                <span class="tipo">{{ $factura ? 'FACTURA' : 'PEDIDO' }}</span>
                @if ($factura)
                    @if (! $factura->exists)
                        {{-- La que se va a emitir (modal de Imprimir): el número se confirma al imprimir. --}}
                        <span class="sello">VISTA PREVIA</span>
                    @elseif ($esOriginal)
                        {{-- Original: sin sello. --}}
                    @elseif ($firmado ?? false)
                        <span class="sello"><span class="nw">REIMPRESIÓN ·</span> <span class="nw">COPIA</span></span>
                    @else
                        <span class="sello"><span class="nw">COPIA ·</span> <span class="nw">SIN VALOR FISCAL</span></span>
                    @endif
                @else
                    <span class="estado">{{ $pedido->estado->getLabel() }} · {{ $validado ? 'sin factura todavía' : 'vista previa' }}</span>
                    <span class="sello">SIN VALOR FISCAL</span>
                @endif
            </div>
        </div>

        <div class="datos">
            <div>
                @if ($factura)
                    <div class="fila"><span class="etiqueta">Factura:</span><span><span class="numero">{{ $factura->numero }}</span>@unless ($factura->exists)<span class="provisional">(se confirma al imprimir)</span>@endunless</span></div>
                @else
                    <div class="fila"><span class="etiqueta">Pedido:</span><span class="numero">{{ $pedido->numero }}</span></div>
                @endif
                <div class="fila"><span class="etiqueta">Cliente:</span><span>{{ $cliente->codigo }} {{ $factura->cliente_nombre ?? $cliente->nombre }}</span></div>
                <div class="fila">
                    <span class="etiqueta">Teléfono:</span>
                    <span>{{ $cliente->telefono ?: '-' }}<span class="aparte"><b>R.T.N.:</b> {{ ($factura ? $factura->cliente_rtn : $cliente->rtn) ?: '-' }}</span></span>
                </div>
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
                <div class="fila"><span class="etiqueta">Vendedor:</span><span>{{ $pedido->vendedor->name }}</span></div>
                @if ($ruta)
                    <div class="fila"><span class="etiqueta">Ruta:</span><span><b>N° {{ $ruta->numero }}</b> · {{ \Illuminate\Support\Str::limit($ruta->nombre, 30) }}</span></div>
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
            <div class="exonerado chica">
                <b>Adquiriente Exonerado:</b>
                <div class="campo">N° Orden de Compra Exenta<span></span></div>
                <div class="campo">N° Constancia de Registro de Exonerados<span></span></div>
                <div class="campo">N° Registro SAG<span></span></div>
            </div>
        @endif

        <table class="detalle">
            <thead>
            <tr>
                <th class="cant">CJ</th>
                <th class="cant">UN</th>
                <th class="codigo">Código</th>
                <th class="descripcion">Descripción del Producto</th>
                <th class="num">Precio Unit.</th>
                <th class="num">Desc.</th>
                <th class="num">I.S.V.</th>
                <th class="num">Total</th>
                <th class="letra"></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($renglones as $renglon)
                @php
                    $cajas = $renglon->cajas();
                    $unidades = $renglon->unidades();
                    $precios = $renglon->precios();
                @endphp
                <tr @class(['regalo' => $renglon->esRegalo(), 'tachado' => $renglon->noSale()])>
                    <td class="cant">
                        @if ($cajas !== null)
                            {{ $cajas }}@if ($renglon->medidaCaja()) <small>{{ $renglon->medidaCaja() }}</small>@endif
                        @endif
                    </td>
                    <td class="cant">
                        @if ($unidades !== null)
                            {{ $unidades }}@if ($renglon->medidaSuelta()) <small>{{ $renglon->medidaSuelta() }}</small>@endif
                        @endif
                    </td>
                    <td class="codigo">{{ $renglon->producto()->codigo }}</td>
                    <td class="descripcion">
                        {{ $renglon->producto()->descripcion }}
                        @foreach ($renglon->notas() as $nota)
                            <span class="nota">{{ $nota }}</span>
                        @endforeach
                    </td>
                    <td class="num">
                        @foreach ($precios as [$medida, $precio])
                            <span class="precio">@if (count($precios) > 1)<small>{{ $medida }}</small>@endif{{ number_format($precio, 2) }}</span>
                        @endforeach
                    </td>
                    <td class="num">{{ number_format($renglon->descuento(), 2) }}</td>
                    <td class="num">{{ number_format($renglon->isv(), 2) }}</td>
                    <td class="num">{{ number_format($renglon->total(), 2) }}</td>
                    <td class="letra">{{ $renglon->gravado() ? 'G' : 'E' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="fin">Fin del Detalle de la {{ $factura ? 'Factura' : 'Pedido' }} &nbsp;·&nbsp; G = gravado 15 %, E = exento</div>

        <div class="pie">
            <div>
                <div class="fila observaciones"><span class="etiqueta">Observaciones:</span><span>{{ $pedido->notas }}</span></div>
                <div class="letras">Total: {{ \App\Support\NumeroALetras::lempiras($total) }}</div>
                @if ($factura)
                    <div class="fila chica"><span class="etiqueta">Rango Autorizado:</span><span>del <span class="nw">{{ $factura->rango_desde }}</span> al <span class="nw">{{ $factura->rango_hasta }}</span></span></div>
                    <div class="fila chica"><span class="etiqueta">Fecha Límite de Emisión:</span><span>{{ $factura->fecha_limite->format('d/m/Y') }}</span></div>
                    <div class="chica"><b>{{ $fiscales->leyenda ?: 'La Factura Es Un Derecho, Exíjala' }}</b> &nbsp;·&nbsp; Original: Cliente - Copia: Emisor</div>
                @elseif (! $validado)
                    <div class="chica">Pedido pendiente: cantidades pedidas y precios de hoy; bodega confirma al validar.</div>
                @endif
            </div>

            <div>
                <table class="totales">
                    <tr><td>Importe Exonerado:</td><td class="num">{{ number_format(0, 2) }}</td></tr>
                    <tr><td>Importe Exento:</td><td class="num">{{ number_format($exento, 2) }}</td></tr>
                    <tr><td>Importe Gravado 15%:</td><td class="num">{{ number_format($gravado, 2) }}</td></tr>
                    <tr><td>Importe Gravado 18%:</td><td class="num">{{ number_format(0, 2) }}</td></tr>
                </table>
                <div class="firma">Firma</div>
            </div>

            <table class="totales">
                <tr><td>Sub Total:</td><td class="num">{{ number_format($exento + $gravado + $descuento, 2) }}</td></tr>
                <tr><td>Descuento:</td><td class="num">{{ number_format($descuento, 2) }}</td></tr>
                <tr><td>I.S.V. 15%:</td><td class="num">{{ number_format($isv, 2) }}</td></tr>
                <tr><td>I.S.V. 18%:</td><td class="num">{{ number_format(0, 2) }}</td></tr>
                <tr class="total"><td>Total: L.</td><td class="num">{{ number_format($total, 2) }}</td></tr>
            </table>
        </div>
    </div>
@endforeach
</body>
</html>
