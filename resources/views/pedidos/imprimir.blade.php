@php
    /** @var \App\Models\Pedido $pedido */
    $despachado = ! $pedido->estaPendiente();
    $conPromo = $pedido->descuento > 0;
    $promociones = $pedido->items->pluck('promocion')->filter()->unique('id');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pedido {{ $pedido->numero }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #111; margin: 0; background: #f3f4f6; }
        .hoja { max-width: 820px; margin: 24px auto; background: #fff; padding: 28px 32px; border-radius: 8px; }
        .barra { max-width: 820px; margin: 16px auto 0; display: flex; gap: 8px; justify-content: flex-end; }
        .barra button { font: inherit; padding: 8px 14px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; }
        .barra .principal { background: #111; color: #fff; border-color: #111; }
        header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 12px; margin-bottom: 16px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #6b7280; font-size: 13px; }
        .estado { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; background: #fef3c7; color: #92400e; }
        .estado.despachado { background: #dcfce7; color: #166534; }
        .estado.anulado { background: #e5e7eb; color: #374151; }
        .datos { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 24px; font-size: 14px; margin-bottom: 16px; }
        .datos b { display: inline-block; min-width: 90px; color: #374151; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; border-bottom: 1px solid #111; padding: 6px 4px; font-weight: 600; }
        td { border-bottom: 1px solid #e5e7eb; padding: 6px 4px; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .falta { color: #b91c1c; font-weight: 600; }
        .tachado { color: #9ca3af; text-decoration: line-through; }
        .promo { color: #15803d; }
        .totales { margin-left: auto; margin-top: 12px; width: 280px; font-size: 14px; }
        .totales td { border: 0; padding: 3px 4px; }
        .totales .total td { font-size: 17px; font-weight: 700; border-top: 2px solid #111; padding-top: 6px; }
        .nota { margin-top: 16px; font-size: 12px; color: #4b5563; }
        .firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 56px; font-size: 12px; text-align: center; }
        .firmas div { border-top: 1px solid #111; padding-top: 6px; }
        @media print {
            body { background: #fff; }
            .barra { display: none; }
            .hoja { margin: 0; padding: 0; max-width: none; border-radius: 0; }
        }
    </style>
</head>
<body>
<div class="barra">
    <button type="button" onclick="window.close()">Cerrar</button>
    <button type="button" class="principal" onclick="window.print()">Imprimir</button>
</div>

<div class="hoja">
    <header>
        <div>
            <h1>{{ config('app.name') }}</h1>
            <div class="muted">{{ $pedido->bodega->nombre }}@if ($pedido->bodega->telefono) · Tel. {{ $pedido->bodega->telefono }}@endif</div>
        </div>
        <div style="text-align:right">
            <div style="font-size:18px;font-weight:700">Pedido {{ $pedido->numero }}</div>
            <div class="muted">{{ $pedido->fecha->format('d/m/Y') }}</div>
            <span class="estado {{ $pedido->estado->value }}">{{ $pedido->estado->getLabel() }}</span>
        </div>
    </header>

    <div class="datos">
        <div><b>Cliente:</b> {{ $pedido->cliente->nombre }}@if ($pedido->cliente->codigo) ({{ $pedido->cliente->codigo }})@endif</div>
        <div><b>RTN:</b> {{ $pedido->cliente->rtn ?? '—' }}</div>
        <div><b>Dirección:</b> {{ $pedido->cliente->direccion ?? '—' }}@if ($pedido->cliente->municipio) · {{ $pedido->cliente->municipio->nombre }}, {{ $pedido->cliente->municipio->departamento->nombre }}@endif</div>
        <div><b>Teléfono:</b> {{ $pedido->cliente->telefono ?? '—' }}</div>
        <div><b>Vendedor:</b> {{ $pedido->vendedor->name }}@if ($pedido->vendedor->codigo) ({{ $pedido->vendedor->codigo }})@endif</div>
        @if ($pedido->fuera_de_ruta)
            <div><b>Fuera de ruta:</b> {{ $pedido->motivo_fuera_ruta }}</div>
        @endif
        <div><b>Despachó:</b> {{ $pedido->validador?->name ?? 'Pendiente de validar' }}@if ($pedido->validado_at) · {{ $pedido->validado_at->format('d/m/Y H:i') }}@endif</div>
    </div>

    <table>
        <thead>
        <tr>
            <th>Código</th>
            <th>Producto</th>
            <th>En</th>
            <th class="num">Pidió</th>
            <th class="num">{{ $despachado ? 'Sale' : 'Cant.' }}</th>
            <th class="num">Precio</th>
            @if ($conPromo)
                <th class="num">Desc.</th>
            @endif
            <th class="num">Total</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($pedido->items as $item)
            <tr @class(['tachado' => $despachado && $item->cantidad_despachada === 0])>
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
                <td class="num">{{ lempiras($item->total) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totales">
        <tr><td>Subtotal</td><td class="num">{{ lempiras($pedido->subtotal) }}</td></tr>
        @if ($conPromo)
            <tr><td>Descuento promociones</td><td class="num promo">−{{ lempiras($pedido->descuento) }}</td></tr>
        @endif
        <tr><td>ISV 15%</td><td class="num">{{ lempiras($pedido->isv) }}</td></tr>
        <tr class="total"><td>Total</td><td class="num">{{ lempiras($pedido->total) }}</td></tr>
    </table>

    <p class="nota">
        @if ($despachado)
            Cantidades y precios confirmados por bodega al despachar. Los precios no incluyen ISV; el ISV se suma al final.
        @else
            Pedido pendiente: totales calculados con los precios de hoy. Bodega confirma cantidades y precio al despachar.
        @endif
        @foreach ($promociones as $promocion)
            <br>{{ $promocion->codigo }} · {{ $promocion->nombre }}: {{ $promocion->resumen() }}.
        @endforeach
        @if ($pedido->notas)
            <br>Notas: {{ $pedido->notas }}
        @endif
    </p>

    <div class="firmas">
        <div>Entregó</div>
        <div>Recibió conforme</div>
    </div>
</div>
</body>
</html>
