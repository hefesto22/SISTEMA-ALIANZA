{{--
    Tarjeta de resultado de "Actualizar precio": se recalcula mientras se escriben costo y precio.
    Datos: App\Filament\Resources\Productos\Actions\ActualizarPrecioAction::resultado()
--}}
@php
    $mensaje ??= null;
    $tono ??= 'igual';
    $aviso ??= null;
    $icono = match ($tono) {
        'sube' => 'heroicon-m-arrow-trending-up',
        'baja' => 'heroicon-m-arrow-trending-down',
        'alerta' => 'heroicon-m-exclamation-triangle',
        default => 'heroicon-m-minus',
    };
@endphp

<style>
    /*
     * Colores con variables del panel. El tema shadcn intercambia en modo oscuro
     * gray-500/600/700 y primary-500/600/700 por sus tonos claros, así que el
     * texto tenue usa gray-400 y los bordes gray-800 en oscuro (esos no cambian).
     */
    .ap-card { --ap-tenue: var(--gray-500); --ap-borde: var(--gray-200); height: 100%; border-radius: 0.875rem; padding: 0 1.375rem 1.25rem; border: 1px solid var(--ap-borde); background: var(--gray-50); display: flex; flex-direction: column; overflow: hidden; }
    .dark .ap-card { --ap-tenue: var(--gray-400); --ap-borde: var(--gray-800); background: color-mix(in oklab, var(--gray-900) 60%, transparent); }
    .ap-hero { margin: 0 -1.375rem 0.9rem; padding: 1.25rem 1.375rem 1rem; background: color-mix(in oklab, var(--primary-500) 8%, transparent); border-bottom: 1px solid var(--ap-borde); }
    .ap-eyebrow { font-size: 0.7rem; font-weight: 600; letter-spacing: 0.07em; text-transform: uppercase; color: var(--ap-tenue); }
    .ap-precio { margin-top: 0.35rem; font-size: 2.4rem; line-height: 1.05; font-weight: 700; letter-spacing: -0.02em; color: var(--primary-600); font-variant-numeric: tabular-nums; }
    .ap-sub { margin-top: 0.3rem; font-size: 0.875rem; color: var(--ap-tenue); }
    .ap-filas { margin: 0; display: grid; gap: 0.55rem; }
    .ap-fila { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; font-size: 0.875rem; }
    .ap-fila dt { color: var(--ap-tenue); }
    .ap-fila dd { margin: 0; text-align: right; font-weight: 600; font-variant-numeric: tabular-nums; }
    .ap-fila dd small { display: block; font-size: 0.75rem; font-weight: 400; color: var(--ap-tenue); }
    .ap-chip { align-self: flex-start; margin-top: 1rem; display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.3rem 0.75rem; border-radius: 999px; font-size: 0.8rem; font-weight: 600; }
    .ap-chip svg { width: 1rem; height: 1rem; flex-shrink: 0; }
    .ap-chip.sube { color: var(--warning-700); background: color-mix(in oklab, var(--warning-500) 16%, transparent); }
    .ap-chip.baja { color: var(--success-700); background: color-mix(in oklab, var(--success-500) 16%, transparent); }
    .ap-chip.igual { color: var(--ap-tenue); background: color-mix(in oklab, var(--gray-400) 16%, transparent); }
    .ap-chip.alerta { color: var(--danger-700); background: color-mix(in oklab, var(--danger-500) 16%, transparent); }
    .ap-aviso { margin-top: 0.5rem; font-size: 0.8rem; color: var(--danger-700); }
    .dark .ap-chip.alerta, .dark .ap-aviso { color: var(--danger-400); }
    .dark .ap-chip.sube { color: var(--warning-400); }
    .dark .ap-chip.baja { color: var(--success-400); }
    .ap-pie { margin-top: auto; padding-top: 1rem; font-size: 0.75rem; color: var(--ap-tenue); }
    .ap-vacio { margin: auto 0; text-align: center; font-size: 0.875rem; color: var(--ap-tenue); padding: 2rem 0.5rem; }
</style>

<div class="ap-card">
    @if ($mensaje)
        <div class="ap-vacio">{{ $mensaje }}</div>
    @else
        <div class="ap-hero">
            <div class="ap-eyebrow">Precio de venta por unidad</div>
            <div class="ap-precio">{{ $venta }}</div>
            <div class="ap-sub">{{ $gravado ? 'Con ISV incluido · sin ISV '.$sinIsv : 'Exento de ISV' }}</div>
        </div>

        <dl class="ap-filas">
            @if ($uxc)
                <div class="ap-fila">
                    <dt>Caja de {{ $uxc }}</dt>
                    <dd>
                        {{ $caja }}
                        @if ($gravado)
                            <small>sin ISV {{ $cajaSin }}</small>
                        @endif
                    </dd>
                </div>
            @endif
            <div class="ap-fila">
                <dt>{{ $gravado ? 'Costo con ISV' : 'Costo por unidad' }}</dt>
                <dd>
                    {{ $costo }}
                    @if ($gravado)
                        <small>neto {{ $costoNeto }} + ISV</small>
                    @endif
                </dd>
            </div>
            <div class="ap-fila">
                <dt>Utilidad</dt>
                <dd>
                    {{ $margen }}
                    <small>gana {{ $ganancia }} por unidad</small>
                    @if ($gananciaCaja)
                        <small>{{ $gananciaCaja }} por caja</small>
                    @endif
                </dd>
            </div>
            <div class="ap-fila">
                <dt>Precio de venta hoy</dt>
                <dd>{{ $hoy }}</dd>
            </div>
        </dl>

        <span @class(['ap-chip', $tono])>
            {{ svg($icono) }} {{ $cambio }}
        </span>

        @if ($aviso)
            <div class="ap-aviso">{{ $aviso }}</div>
        @endif

        <div class="ap-pie">Aplica en todas las bodegas y queda en el historial.</div>
    @endif
</div>
