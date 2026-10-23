{{--
    Tarjeta "Así la ve el vendedor" del formulario de promociones; se arma mientras se escribe.
    Datos: App\Filament\Resources\Promociones\Schemas\PromocionForm::vistaPrevia()
--}}
@php
    $compra ??= [];
    $regalo ??= null;
    $nota ??= '';
@endphp

<style>
    /* Mismas reglas que la tarjeta de precios: en oscuro el tema shadcn cambia gray-500/600/700. */
    .pv-card { --pv-tenue: var(--gray-500); --pv-borde: var(--gray-200); border-radius: 0.875rem; border: 1px solid var(--pv-borde); background: var(--gray-50); overflow: hidden; }
    .dark .pv-card { --pv-tenue: var(--gray-400); --pv-borde: var(--gray-800); background: color-mix(in oklab, var(--gray-900) 60%, transparent); }
    .pv-hero { padding: 1rem 1.25rem; background: color-mix(in oklab, var(--primary-500) 8%, transparent); border-bottom: 1px solid var(--pv-borde); }
    .pv-arriba { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; }
    .pv-eyebrow { font-size: 0.7rem; font-weight: 600; letter-spacing: 0.07em; text-transform: uppercase; color: var(--pv-tenue); }
    .pv-codigo { font-size: 0.75rem; font-weight: 600; font-variant-numeric: tabular-nums; padding: 0.15rem 0.55rem; border-radius: 0.375rem; border: 1px dashed var(--pv-borde); color: var(--pv-tenue); }
    .pv-nombre { margin-top: 0.4rem; font-size: 1.15rem; font-weight: 700; line-height: 1.3; }
    .pv-chips { margin-top: 0.6rem; display: flex; flex-wrap: wrap; gap: 0.4rem; }
    .pv-chip { font-size: 0.75rem; font-weight: 600; padding: 0.2rem 0.6rem; border-radius: 999px; color: var(--pv-tenue); background: color-mix(in oklab, var(--gray-400) 16%, transparent); }
    .pv-chip.ok { color: var(--success-700); background: color-mix(in oklab, var(--success-500) 16%, transparent); }
    .dark .pv-chip.ok { color: var(--success-400); }
    .pv-cuerpo { padding: 1rem 1.25rem 1.1rem; display: grid; gap: 0.75rem; }
    .pv-titulo { font-size: 0.7rem; font-weight: 600; letter-spacing: 0.07em; text-transform: uppercase; color: var(--pv-tenue); margin-bottom: 0.3rem; }
    .pv-lista { margin: 0; padding: 0; list-style: none; display: grid; gap: 0.3rem; font-size: 0.875rem; }
    .pv-lista li { display: flex; gap: 0.5rem; align-items: baseline; }
    .pv-lista b { white-space: nowrap; font-variant-numeric: tabular-nums; }
    .pv-flecha { display: flex; justify-content: center; color: var(--pv-tenue); }
    .pv-flecha svg { width: 1.1rem; height: 1.1rem; }
    .pv-pendiente { font-size: 0.875rem; color: var(--pv-tenue); font-style: italic; }
    .pv-regalo { border-radius: 0.625rem; padding: 0.7rem 0.85rem; border: 1px solid color-mix(in oklab, var(--success-500) 35%, transparent); background: color-mix(in oklab, var(--success-500) 8%, transparent); }
    .pv-beneficio { display: inline-block; margin-top: 0.45rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase; padding: 0.2rem 0.6rem; border-radius: 999px; color: var(--success-700); background: color-mix(in oklab, var(--success-500) 18%, transparent); }
    .dark .pv-beneficio { color: var(--success-400); }
    .pv-nota { font-size: 0.8rem; font-style: italic; color: var(--pv-tenue); border-top: 1px dashed var(--pv-borde); padding-top: 0.6rem; }
</style>

<div class="pv-card">
    <div class="pv-hero">
        <div class="pv-arriba">
            <span class="pv-eyebrow">Así la ve el vendedor</span>
            <span class="pv-codigo">{{ $codigo ?? 'PROMO-····' }}</span>
        </div>
        <div class="pv-nombre">{{ $nombre !== '' ? $nombre : 'Nueva promoción' }}</div>
        <div class="pv-chips">
            <span @class(['pv-chip', 'ok' => $activa])>{{ $activa ? 'Activa' : 'Inactiva' }}</span>
            <span class="pv-chip">{{ $vigencia }}</span>
        </div>
    </div>

    <div class="pv-cuerpo">
        <div>
            <div class="pv-titulo">Si compra</div>
            @if ($compra === [])
                <div class="pv-pendiente">Escoja los productos que tiene que comprar.</div>
            @else
                <ul class="pv-lista">
                    @foreach ($compra as $linea)
                        <li><b>{{ $linea['cantidad'] }}</b> <span>{{ $linea['producto'] }}</span></li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="pv-flecha">{{ svg('heroicon-m-arrow-down') }}</div>

        <div class="pv-regalo">
            <div class="pv-titulo">Se lleva</div>
            @if ($regalo === null)
                <div class="pv-pendiente">Escoja el producto de regalo o con descuento.</div>
            @else
                <ul class="pv-lista">
                    <li><b>{{ $regalo['cantidad'] }}{{ $regalo['mismo'] ? ' más' : '' }}</b> <span>{{ $regalo['producto'] }}</span></li>
                </ul>
            @endif
            <span class="pv-beneficio">{{ $beneficio }}</span>
        </div>

        @if ($nota !== '')
            <div class="pv-nota">{{ $nota }}</div>
        @endif
    </div>
</div>
