{{--
    Hoja para imprimir o guardar como PDF un reporte (ventas o inventario
    valorizado). Abre la ventana de imprimir sola; ahí se escoge la impresora
    o "Guardar como PDF".
    Datos: $titulo, $subtitulo, $empresa, $usuario, $parte (vista del cuerpo) y $d (sus datos).
    Controlador: App\Http\Controllers\ImprimirReporteController.
--}}
<!DOCTYPE html>
<html lang="es" translate="no">
<head>
    <meta charset="utf-8">
    <meta name="google" content="notranslate">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo }} · {{ $subtitulo }}</title>
    @include('reportes.partes.estilos')
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #111; background: #f3f4f6; }
        .barra { max-width: 1100px; margin: 16px auto 0; padding: 0 16px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .barra .ayuda { margin-right: auto; font-size: 14px; color: #4b5563; }
        .barra button { font: inherit; padding: 8px 14px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; }
        .barra .principal { background: #111; color: #fff; border-color: #111; }
        .hoja { max-width: 1100px; margin: 12px auto 24px; padding: 24px 28px; background: #fff; border-radius: 8px; }
        .cabeza { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px 24px; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 2px solid #111; }
        .cabeza .empresa { font-size: 13px; font-weight: 600; color: #374151; }
        .cabeza h1 { margin: 2px 0; font-size: 20px; }
        .cabeza .sub { font-size: 13px; color: #374151; }
        .cabeza .cuando { font-size: 12px; line-height: 1.5; color: #6b7280; text-align: right; }

        @media (max-width: 640px) {
            .hoja { margin: 8px 0 16px; padding: 16px; border-radius: 0; }
            .cabeza .cuando { text-align: left; }
        }

        @page { size: A4 landscape; margin: 10mm; }

        @media print {
            body { background: #fff; }
            .barra { display: none; }
            .hoja { max-width: none; margin: 0; padding: 0; border-radius: 0; }
            .rp { gap: 12px; }
            .rp-kpi, .rp-aviso { break-inside: avoid; }
            .rp-kpi strong { font-size: 16px; }
            .rp-tabla { overflow: visible; border-radius: 0; }
            .rp table { font-size: 10.5px; }
            .rp th, .rp td { padding: 4px 6px; }
            .rp tr { break-inside: avoid; }
            .rp-seccion h2, .rp-nota { break-after: avoid; }
        }
    </style>
</head>
<body>
<div class="barra">
    <span class="ayuda">Para el PDF: en la ventana de imprimir escoja «Guardar como PDF».</span>
    <button type="button" onclick="window.close()">Cerrar</button>
    <button type="button" class="principal" onclick="window.print()">Imprimir o guardar PDF</button>
</div>

<div class="hoja">
    <div class="cabeza">
        <div>
            <div class="empresa">{{ $empresa }}</div>
            <h1>{{ $titulo }}</h1>
            <div class="sub">{{ $subtitulo }}</div>
        </div>
        <div class="cuando">
            Sacado el {{ now()->format('d/m/Y H:i') }}<br>
            por {{ $usuario }}
        </div>
    </div>

    @include($parte, ['d' => $d])
</div>

<script>
    window.addEventListener('load', () => setTimeout(() => window.print(), 400));
</script>
</body>
</html>
