{{--
    Acta de baja de inventario (ImprimirReporteController::actaDeBaja): lo que
    regresó dañado de la ruta en las devoluciones del periodo (cambio por
    cambio o con crédito), para darlo de baja del inventario a su costo, con
    las firmas de quienes lo constatan. Una hoja por bodega: renglón por
    renglón (fecha, devolución, factura, cliente, producto, cantidad y costo),
    lo de cada proveedor (para el reclamo), el total en letras, el destino de
    la mercadería y las firmas.
    Es el soporte interno de la pérdida; lo que pida el SAR para deducirla se
    confirma con el contador.
    Datos: $reporte (DevolucionesDelPeriodo), $actas (DevolucionesDelPeriodo::acta()),
    $fiscales (DatosFiscales), $usuario y $embebido (dentro del modal de vista
    de impresión no lleva la barra de arriba).
--}}
@php
    use App\Support\NumeroALetras;

    /** @var \App\Domain\Reportes\DevolucionesDelPeriodo $reporte */
    /** @var list<array<string, mixed>> $actas */
    /** @var \App\Models\DatosFiscales $fiscales */
    $periodo = $reporte->periodo->texto();
    $proveedor = $reporte->proveedor?->etiqueta();
@endphp
<!DOCTYPE html>
<html lang="es" translate="no">
<head>
    <meta charset="utf-8">
    <meta name="google" content="notranslate">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acta de baja · {{ $periodo }} · {{ $reporte->nombreBodega() }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #111; background: #f3f4f6; font-size: 12.5px; }
        .barra { max-width: 860px; margin: 16px auto 0; padding: 0 16px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .barra .ayuda { margin-right: auto; font-size: 14px; color: #4b5563; }
        .barra button { font: inherit; padding: 8px 14px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; }
        .barra .principal { background: #111; color: #fff; border-color: #111; }
        .hoja { max-width: 860px; margin: 12px auto 24px; padding: 28px 32px; background: #fff; border-radius: 8px; }
        .embebido .hoja { margin: 20px auto; box-shadow: 0 1px 3px rgb(0 0 0 / 0.12), 0 8px 24px rgb(0 0 0 / 0.06); }
        .cabeza { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px 24px; padding-bottom: 12px; border-bottom: 2px solid #111; }
        h1 { margin: 0 0 4px; font-size: 17px; }
        .doc { text-align: right; }
        .doc .tipo { font-size: 15px; font-weight: 800; letter-spacing: .04em; }
        .doc .linea { margin: 6px 0 2px; font-weight: 700; }
        .muted { color: #4b5563; }
        .texto { margin: 14px 0 10px; line-height: 1.55; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
        th { font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; color: #4b5563; border-bottom: 1.5px solid #111; }
        td small { display: block; font-size: 11px; color: #4b5563; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .fecha, .dev { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .tabla { overflow-x: auto; }
        tfoot td { border-top: 1.5px solid #111; border-bottom: 0; font-weight: 800; }
        .letras { margin-top: 6px; font-weight: 600; }
        h2 { margin: 18px 0 6px; font-size: 12px; text-transform: uppercase; letter-spacing: .05em; }
        .proveedores { max-width: 460px; }
        .destino { display: flex; flex-wrap: wrap; gap: 6px 22px; margin-top: 4px; }
        .caja { display: inline-block; width: 13px; height: 13px; margin-right: 6px; vertical-align: -2px; border: 1.5px solid #111; border-radius: 2px; }
        .renglon { height: 22px; border-bottom: 1px solid #9ca3af; }
        .firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 52px 40px; margin-top: 58px; }
        .firmas div { border-top: 1px solid #111; padding-top: 4px; font-size: 11.5px; }
        .firmas b { display: block; font-size: 12px; }
        .pie { margin-top: 20px; font-size: 10.5px; color: #4b5563; }
        .vacio { padding: 24px 0 8px; font-size: 14px; }

        @media (max-width: 640px) {
            .hoja { margin: 8px 0 16px; padding: 16px; border-radius: 0; }
            .doc { text-align: left; }
            .firmas { grid-template-columns: 1fr; }
        }

        @page { size: A4 portrait; margin: 12mm; }

        @media print {
            body { background: #fff; }
            .barra { display: none; }
            .hoja { max-width: none; margin: 0; padding: 0; border-radius: 0; box-shadow: none; break-after: page; }
            .hoja:last-child { break-after: auto; }
            tr, .firmas, .destino { break-inside: avoid; }
            h2 { break-after: avoid; }
        }
    </style>
</head>
<body @class(['embebido' => $embebido ?? false])>
@unless ($embebido ?? false)
    <div class="barra">
        <span class="ayuda">Para el PDF: en la ventana de imprimir escoja «Guardar como PDF».</span>
        <button type="button" onclick="window.close()">Cerrar</button>
        <button type="button" class="principal" onclick="window.print()">Imprimir o guardar PDF</button>
    </div>
@endunless

@forelse ($actas as $acta)
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
                <div><b>Bodega:</b> {{ $acta['bodega'] }}{{ $acta['direccion'] ? ' · '.$acta['direccion'] : '' }}</div>
            </div>
            <div class="doc">
                <div class="tipo">ACTA DE BAJA DE INVENTARIO</div>
                <div class="muted">Producto dañado en devoluciones</div>
                <div class="linea">No. ____________</div>
                <div>Periodo: {{ $periodo }}</div>
                @if ($proveedor !== null)
                    <div>Proveedor: {{ $proveedor }}</div>
                @endif
            </div>
        </div>

        <p class="texto">
            En la bodega {{ $acta['bodega'] }}, el día ______ de ____________________ de ________, los abajo firmantes
            hacemos constar que el producto que se detalla regresó dañado de la ruta en las devoluciones registradas
            del {{ $periodo }}{{ $proveedor !== null ? ' (productos de '.$proveedor.')' : '' }}, que no se puede vender
            ni regresa a la existencia, y que se da de baja del inventario a su costo por un total de
            <b>{{ lempiras($acta['total']) }}</b>.
        </p>

        <div class="tabla">
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Devolución</th>
                    <th>Cliente</th>
                    <th>Producto</th>
                    <th class="num">Cant.</th>
                    <th class="num">Costo unit.</th>
                    <th class="num">Costo total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($acta['renglones'] as $r)
                    <tr>
                        <td class="fecha">{{ $r['fecha'] }}</td>
                        <td class="dev">
                            {{ $r['devolucion'] }}
                            <small>{{ $r['tipo'] }}{{ $r['factura'] !== null ? ' · Fact. '.$r['factura'] : '' }}</small>
                        </td>
                        <td>
                            {{ $r['cliente'] }}
                            @if ($r['motivo'] !== null)
                                <small>{{ $r['motivo'] }}</small>
                            @endif
                        </td>
                        <td>
                            {{ $r['producto'] }}
                            <small>{{ $r['codigo'] }} · {{ $r['proveedor'] }}</small>
                        </td>
                        <td class="num">{{ number_format($r['cantidad']) }} {{ $r['presentacion'] }}</td>
                        <td class="num">{{ lempiras($r['costo']) }}</td>
                        <td class="num">{{ lempiras($r['perdida']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="6">Total que se da de baja (a costo)</td>
                    <td class="num">{{ lempiras($acta['total']) }}</td>
                </tr>
            </tfoot>
        </table>
        </div>
        <div class="letras">SON: {{ NumeroALetras::lempiras($acta['total']) }}</div>

        @if (count($acta['proveedores']) > 1)
            <h2>Por proveedor (para el reclamo)</h2>
            <table class="proveedores">
                <thead>
                    <tr>
                        <th>Proveedor</th>
                        <th class="num">Productos</th>
                        <th class="num">Costo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($acta['proveedores'] as $p)
                        <tr>
                            <td>{{ $p['proveedor'] }}</td>
                            <td class="num">{{ number_format($p['productos']) }}</td>
                            <td class="num">{{ lempiras($p['perdida']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <h2>Destino de la mercadería</h2>
        <div class="destino">
            <span><span class="caja"></span>Destruida</span>
            <span><span class="caja"></span>Devuelta al proveedor (reclamo)</span>
            <span><span class="caja"></span>Otro: ______________________</span>
        </div>

        <h2>Observaciones</h2>
        <div class="renglon"></div>
        <div class="renglon"></div>

        <div class="firmas">
            <div><b>Encargado de bodega</b>Nombre, identidad y firma</div>
            <div><b>Administración</b>Nombre, identidad y firma</div>
            <div><b>Contador</b>Nombre, identidad y firma</div>
            <div><b>Testigo</b>Nombre, identidad y firma</div>
        </div>

        <div class="pie">
            Soporte de cada renglón: su devolución (DEV) con la orden de cambio firmada (CAM) o la nota de crédito.
            Sacada el {{ now()->format('d/m/Y H:i') }} por {{ $usuario }}.
        </div>
    </div>
@empty
    <div class="hoja">
        <div class="cabeza">
            <div>
                <h1>{{ $fiscales->nombreParaMostrar() }}</h1>
                <div><b>Bodega:</b> {{ $reporte->nombreBodega() }}</div>
            </div>
            <div class="doc">
                <div class="tipo">ACTA DE BAJA DE INVENTARIO</div>
                <div>Periodo: {{ $periodo }}</div>
                @if ($proveedor !== null)
                    <div>Proveedor: {{ $proveedor }}</div>
                @endif
            </div>
        </div>
        <p class="vacio">No regresó nada dañado en las devoluciones de este periodo: no hay nada que dar de baja.</p>
    </div>
@endforelse
</body>
</html>
