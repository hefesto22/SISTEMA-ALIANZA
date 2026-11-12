{{--
    Tarjeta de arriba de "Actualizar precio": el costo promedio que dejan
    las compras (y el corregido, si se corrige), la última compra, la
    existencia y los avisos de lo que se está escribiendo (bajo el costo,
    cambio muy grande). Se recalcula mientras se escribe.
    Datos: App\Filament\Resources\Productos\Actions\ActualizarPrecioAction::resumen()
--}}
@php
    $avisos ??= [];
@endphp

<style>
    /*
     * Colores con variables del panel. El tema shadcn intercambia en modo oscuro
     * gray-500/600/700 y primary-500/600/700 por sus tonos claros, así que el
     * texto tenue usa gray-400 y los bordes gray-800 en oscuro (esos no cambian).
     */
    .ap-card { --ap-tenue: var(--gray-500); --ap-borde: var(--gray-200); border-radius: 0.75rem; padding: 0.75rem 1rem; border: 1px solid var(--ap-borde); background: var(--gray-50); display: grid; gap: 0.5rem; }
    .dark .ap-card { --ap-tenue: var(--gray-400); --ap-borde: var(--gray-800); background: color-mix(in oklab, var(--gray-900) 60%, transparent); }
    .ap-datos { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: 0.5rem 1.5rem; }
    .ap-dato { display: grid; gap: 0.1rem; min-width: 0; }
    .ap-eyebrow { font-size: 0.68rem; font-weight: 600; letter-spacing: 0.07em; text-transform: uppercase; color: var(--ap-tenue); }
    .ap-valor { font-size: 1.05rem; font-weight: 700; font-variant-numeric: tabular-nums; }
    .ap-valor.ap-nuevo { color: var(--warning-600); }
    .dark .ap-valor.ap-nuevo { color: var(--warning-400); }
    .ap-sub { font-size: 0.75rem; color: var(--ap-tenue); font-variant-numeric: tabular-nums; }
    .ap-avisos { display: grid; gap: 0.2rem; margin: 0; padding: 0.5rem 0.75rem; list-style: none; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 500; color: var(--danger-700); background: color-mix(in oklab, var(--danger-500) 10%, transparent); }
    .dark .ap-avisos { color: var(--danger-400); }
    .ap-pie { font-size: 0.75rem; color: var(--ap-tenue); }
</style>

<div class="ap-card">
    <div class="ap-datos">
        <div class="ap-dato">
            <span class="ap-eyebrow">Costo promedio {{ $gravado ? 'con ISV' : '' }} por {{ $base }}</span>
            @if ($sinCosto)
                <span class="ap-valor">Sin costo todavía</span>
                <span class="ap-sub">Lo pone la primera compra (o corríjalo abajo).</span>
            @else
                <span class="ap-valor">{{ $costo }}</span>
                @if ($gravado)
                    <span class="ap-sub">neto {{ $costoNeto }} + ISV · de las compras</span>
                @else
                    <span class="ap-sub">de las compras</span>
                @endif
            @endif
        </div>

        @if ($corregido)
            <div class="ap-dato">
                <span class="ap-eyebrow">Costo corregido por {{ $base }}</span>
                <span class="ap-valor ap-nuevo">{{ $corregido }}</span>
                <span class="ap-sub">Se guarda en lugar del promedio.</span>
            </div>
        @endif

        <div class="ap-dato">
            <span class="ap-eyebrow">Última compra</span>
            @if ($ultima)
                <span class="ap-valor">{{ $ultima['monto'] }} <span class="ap-sub">la {{ $ultima['sigla'] }}</span></span>
                <span class="ap-sub">{{ $ultima['porBase'] }} por {{ $base }} · {{ $ultima['fecha'] }} · {{ $ultima['numero'] }}</span>
            @else
                <span class="ap-valor">—</span>
                <span class="ap-sub">Todavía no hay compras.</span>
            @endif
        </div>

        <div class="ap-dato">
            <span class="ap-eyebrow">Existencia (todas las bodegas)</span>
            <span class="ap-valor">{{ $existencia }}</span>
        </div>
    </div>

    @if ($avisos !== [])
        <ul class="ap-avisos">
            @foreach ($avisos as $aviso)
                <li>{{ $aviso }}</li>
            @endforeach
        </ul>
    @endif

    <div class="ap-pie">Con %: el precio sale del costo con su % y se recalcula solo en cada compra (si compran barato, baja y la ganancia se mantiene). Fijo: lo pone usted; las compras no lo mueven. Aplica en todas las bodegas y queda en el historial.</div>
</div>
