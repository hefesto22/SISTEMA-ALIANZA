{{--
    Tarjeta de resultado de "Actualizar precio": se recalcula mientras se escriben el costo y los precios.
    Una fila por presentación (UN, CJ12…): precio de venta, utilidad, ganancia y cuánto cambia contra hoy.
    Datos: App\Filament\Resources\Productos\Actions\ActualizarPrecioAction::resultado()
--}}
@php
    $mensaje ??= null;
    $aviso ??= null;
    $filas ??= [];
@endphp

<style>
    /*
     * Colores con variables del panel. El tema shadcn intercambia en modo oscuro
     * gray-500/600/700 y primary-500/600/700 por sus tonos claros, así que el
     * texto tenue usa gray-400 y los bordes gray-800 en oscuro (esos no cambian).
     */
    .ap-card { --ap-tenue: var(--gray-500); --ap-borde: var(--gray-200); height: 100%; border-radius: 0.875rem; padding: 1rem 1.125rem 1.125rem; border: 1px solid var(--ap-borde); background: var(--gray-50); display: flex; flex-direction: column; gap: 0.75rem; overflow: hidden; }
    .dark .ap-card { --ap-tenue: var(--gray-400); --ap-borde: var(--gray-800); background: color-mix(in oklab, var(--gray-900) 60%, transparent); }
    .ap-eyebrow { font-size: 0.7rem; font-weight: 600; letter-spacing: 0.07em; text-transform: uppercase; color: var(--ap-tenue); }
    .ap-costo { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; font-size: 0.875rem; }
    .ap-costo strong { font-variant-numeric: tabular-nums; }
    .ap-costo small { display: block; font-size: 0.75rem; color: var(--ap-tenue); text-align: right; }
    .ap-pres { display: grid; gap: 0.5rem; }
    .ap-p { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; gap: 0.15rem 0.75rem; padding: 0.6rem 0.7rem; border-radius: 0.6rem; background: #fff; outline: 1px solid var(--ap-borde); }
    .dark .ap-p { background: rgb(255 255 255 / 0.03); }
    .ap-sigla { grid-row: span 2; padding: 0.25rem 0.45rem; border-radius: 0.4rem; font-size: 0.8rem; font-weight: 800; color: var(--primary-700); background: color-mix(in oklab, var(--primary-500) 14%, transparent); }
    .dark .ap-sigla { color: var(--primary-400); }
    .ap-venta { font-size: 1.25rem; font-weight: 700; letter-spacing: -0.01em; font-variant-numeric: tabular-nums; color: var(--primary-600); }
    .ap-detalle { grid-column: 2; font-size: 0.75rem; color: var(--ap-tenue); font-variant-numeric: tabular-nums; }
    .ap-chip { grid-row: span 2; justify-self: end; display: grid; justify-items: end; gap: 0.1rem; font-size: 0.75rem; font-weight: 700; font-variant-numeric: tabular-nums; }
    .ap-chip span { padding: 0.15rem 0.5rem; border-radius: 999px; }
    .ap-chip small { font-weight: 500; color: var(--ap-tenue); }
    .ap-chip.sube span { color: var(--warning-700); background: color-mix(in oklab, var(--warning-500) 16%, transparent); }
    .ap-chip.baja span { color: var(--success-700); background: color-mix(in oklab, var(--success-500) 16%, transparent); }
    .ap-chip.igual span { color: var(--ap-tenue); background: color-mix(in oklab, var(--gray-400) 16%, transparent); }
    .dark .ap-chip.sube span { color: var(--warning-400); }
    .dark .ap-chip.baja span { color: var(--success-400); }
    .ap-aviso { font-size: 0.8rem; color: var(--danger-700); }
    .dark .ap-aviso { color: var(--danger-400); }
    .ap-pie { margin-top: auto; font-size: 0.75rem; color: var(--ap-tenue); }
    .ap-vacio { margin: auto 0; text-align: center; font-size: 0.875rem; color: var(--ap-tenue); padding: 2rem 0.5rem; }
</style>

<div class="ap-card">
    @if ($mensaje)
        <div class="ap-vacio">{{ $mensaje }}</div>
    @else
        <div class="ap-costo">
            <span class="ap-eyebrow">{{ $gravado ? 'Costo con ISV' : 'Costo' }} por {{ $base }}</span>
            <span>
                <strong>{{ $costo }}</strong>
                @if ($gravado)
                    <small>neto {{ $costoNeto }} + ISV</small>
                @endif
            </span>
        </div>

        <div class="ap-pres">
            @foreach ($filas as $fila)
                <div class="ap-p">
                    <span class="ap-sigla" title="{{ $fila['nombre'] }}">{{ $fila['sigla'] }}</span>
                    <span class="ap-venta">{{ $fila['venta'] }}</span>
                    <span @class(['ap-chip', $fila['tono']])>
                        <span>{{ $fila['cambio'] }}</span>
                        <small>hoy {{ $fila['hoy'] }}</small>
                    </span>
                    <span class="ap-detalle">
                        {{ $fila['fijo'] ? 'Fijo' : 'Calculado' }} · utilidad {{ $fila['margen'] }} · gana {{ $fila['ganancia'] }}@if ($gravado) · sin ISV {{ $fila['sinIsv'] }}@endif
                    </span>
                </div>
            @endforeach
        </div>

        @if ($aviso)
            <div class="ap-aviso">{{ $aviso }}</div>
        @endif

        <div class="ap-pie">Calculado: sale del costo con el % de utilidad y se recalcula solo en cada compra. Fijo: las compras cambian el costo, no ese precio. Aplica en todas las bodegas y queda en el historial.</div>
    @endif
</div>
