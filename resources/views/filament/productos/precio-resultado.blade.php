{{--
    Tarjeta de resultado de "Actualizar precio": se recalcula mientras se escribe el costo.
    Datos: App\Filament\Resources\Productos\Actions\ActualizarPrecioAction::resultado()
--}}
@php
    $mensaje ??= null;
    $tono ??= 'igual';
    $aviso ??= null;
    $flecha = match ($tono) {
        'sube' => '▲',
        'baja' => '▼',
        'alerta' => '⚠',
        default => '=',
    };
@endphp

<style>
    .ap-card { height: 100%; border-radius: 0.875rem; padding: 1.25rem 1.375rem; border: 1px solid var(--gray-200); background: var(--gray-50); display: flex; flex-direction: column; }
    .dark .ap-card { border-color: var(--gray-700); background: color-mix(in oklab, var(--gray-800) 55%, transparent); }
    .ap-eyebrow { font-size: 0.7rem; font-weight: 600; letter-spacing: 0.07em; text-transform: uppercase; color: var(--gray-500); }
    .ap-precio { margin-top: 0.35rem; font-size: 2.4rem; line-height: 1.05; font-weight: 700; letter-spacing: -0.02em; color: var(--primary-600); font-variant-numeric: tabular-nums; }
    .dark .ap-precio { color: var(--primary-400); }
    .ap-sub { margin-top: 0.3rem; font-size: 0.875rem; color: var(--gray-500); }
    .ap-sep { height: 1px; margin: 1rem 0 0.75rem; background: var(--gray-200); }
    .dark .ap-sep { background: var(--gray-700); }
    .ap-filas { margin: 0; display: grid; gap: 0.55rem; }
    .ap-fila { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; font-size: 0.875rem; }
    .ap-fila dt { color: var(--gray-500); }
    .ap-fila dd { margin: 0; text-align: right; font-weight: 600; font-variant-numeric: tabular-nums; }
    .ap-fila dd small { display: block; font-size: 0.75rem; font-weight: 400; color: var(--gray-500); }
    .ap-chip { align-self: flex-start; margin-top: 1rem; display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.3rem 0.75rem; border-radius: 999px; font-size: 0.8rem; font-weight: 600; }
    .ap-chip.sube { color: var(--warning-700); background: color-mix(in oklab, var(--warning-500) 16%, transparent); }
    .ap-chip.baja { color: var(--success-700); background: color-mix(in oklab, var(--success-500) 16%, transparent); }
    .ap-chip.igual { color: var(--gray-600); background: color-mix(in oklab, var(--gray-500) 14%, transparent); }
    .ap-chip.alerta { color: var(--danger-700); background: color-mix(in oklab, var(--danger-500) 16%, transparent); }
    .ap-aviso { margin-top: 0.5rem; font-size: 0.8rem; color: var(--danger-700); }
    .dark .ap-chip.alerta, .dark .ap-aviso { color: var(--danger-400); }
    .dark .ap-chip.sube { color: var(--warning-400); }
    .dark .ap-chip.baja { color: var(--success-400); }
    .dark .ap-chip.igual { color: var(--gray-300); }
    .ap-pie { margin-top: auto; padding-top: 1rem; font-size: 0.75rem; color: var(--gray-500); }
    .ap-vacio { margin: auto 0; text-align: center; font-size: 0.875rem; color: var(--gray-500); padding: 2rem 0.5rem; }
</style>

<div class="ap-card">
    @if ($mensaje)
        <div class="ap-vacio">{{ $mensaje }}</div>
    @else
        <div class="ap-eyebrow">{{ $manual ? 'Precio por unidad · a mano' : 'Nuevo precio por unidad' }}</div>
        <div class="ap-precio">{{ $precio }}</div>
        <div class="ap-sub">{{ $precioIsv ? 'Con ISV '.$precioIsv : 'Exento de ISV' }}</div>

        <div class="ap-sep"></div>

        <dl class="ap-filas">
            @if ($uxc)
                <div class="ap-fila">
                    <dt>Caja de {{ $uxc }}</dt>
                    <dd>
                        {{ $caja }}
                        @if ($cajaIsv)
                            <small>con ISV {{ $cajaIsv }}</small>
                        @endif
                    </dd>
                </div>
            @endif
            <div class="ap-fila">
                <dt>Costo por unidad</dt>
                <dd>{{ $costo }}<small>antes {{ $costoAntes }}</small></dd>
            </div>
            <div class="ap-fila">
                <dt>Utilidad</dt>
                <dd>{{ $margen }}</dd>
            </div>
            <div class="ap-fila">
                <dt>Precio de hoy</dt>
                <dd>{{ $hoy }}</dd>
            </div>
        </dl>

        <span @class(['ap-chip', $tono])>
            {{ $flecha }} {{ $cambio }}
        </span>

        @if ($aviso)
            <div class="ap-aviso">{{ $aviso }}</div>
        @endif

        <div class="ap-pie">Aplica en todas las bodegas y queda en el historial.</div>
    @endif
</div>
