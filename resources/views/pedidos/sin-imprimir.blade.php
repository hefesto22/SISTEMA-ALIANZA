{{--
    No se pudo imprimir (bodega sin CAI vigente, faltan datos fiscales…).
    El panel lee data-error y lo muestra como aviso (filament/imprimir-hoja).
--}}
<!DOCTYPE html>
<html lang="es" translate="no">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>No se pudo imprimir</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #111; background: #f3f4f6; margin: 0; }
        .aviso { max-width: 560px; margin: 64px auto; background: #fff; border: 1px solid #fecaca; border-radius: 8px; padding: 24px 28px; }
        h1 { font-size: 18px; margin: 0 0 8px; color: #b91c1c; }
        p { margin: 0; font-size: 14px; line-height: 1.5; }
    </style>
</head>
<body data-error="{{ $mensaje }}">
    <div class="aviso">
        <h1>No se pudo imprimir</h1>
        <p>{{ $mensaje }}</p>
    </div>
</body>
</html>
