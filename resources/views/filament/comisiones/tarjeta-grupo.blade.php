{{--
    Tarjeta de un grupo de comisión en Grupos de comisión: nombre y si paga,
    la escala del mes en escalones (el último es la meta), los montos de cada
    bodega que puso los suyos y las categorías por proveedor.
    Columna: App\Filament\Resources\ComisionGrupos\ComisionGrupoResource (ViewColumn "tarjeta").
    Datos: ComisionGrupoResource::tarjeta().
--}}
@php
    /** @var \App\Models\ComisionGrupo $grupo */
    $grupo = $getRecord();
    $t = \App\Filament\Resources\ComisionGrupos\ComisionGrupoResource::tarjeta($grupo);
    $porciento = fn (float $p): string => \App\Domain\Comisiones\Escala::porciento($p);
@endphp

@once
    <style>
        .gc { --gc-tenue: var(--gray-500); --gc-borde: var(--gray-200); --gc-suave: var(--gray-50); --gc-bien: var(--success-600); --gc-acento: var(--primary-600);
            display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; width: 100%; min-width: 0; text-align: start; white-space: normal; }
        .dark .gc { --gc-tenue: var(--gray-400); --gc-borde: rgb(255 255 255 / 0.1); --gc-suave: rgb(255 255 255 / 0.04); --gc-bien: var(--success-400); --gc-acento: var(--primary-400); }
        .gc.apagado { opacity: 0.6; }

        .gc-cabeza { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem; }
        .gc-nombre { font-size: 1.1rem; font-weight: 700; line-height: 1.3; letter-spacing: 0.01em; overflow-wrap: anywhere; }
        .gc-desc { margin-top: 0.2rem; font-size: 0.85rem; line-height: 1.45; color: var(--gc-tenue); }
        .gc-pill { flex-shrink: 0; font-size: 0.72rem; font-weight: 600; line-height: 1.4; padding: 0.1rem 0.55rem; border-radius: 999px; border: 1px solid var(--gc-borde); color: var(--gc-tenue); }

        .gc-titulo { margin-bottom: 0.4rem; font-size: 0.68rem; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: var(--gc-tenue); }
        .gc-vacio { font-size: 0.85rem; color: var(--gc-tenue); }

        /* La escala: un escalón por renglón, el % a la derecha; la meta resaltada. */
        .gc-escala { display: grid; gap: 0.25rem; margin: 0; padding: 0; list-style: none; font-size: 0.875rem; font-variant-numeric: tabular-nums; }
        .gc-escala li { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.4rem 0.65rem; border-radius: 0.5rem; background: var(--gc-suave); }
        .gc-escala li small { margin-inline-start: 0.4rem; font-size: 0.66rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--gc-bien); }
        .gc-escala li b { min-width: 3.25rem; padding: 0.05rem 0.5rem; border-radius: 999px; text-align: center; font-weight: 700; border: 1px solid var(--gc-borde); }
        .gc-escala li.meta { background: color-mix(in oklab, var(--gc-bien) 9%, transparent); }
        .gc-escala li.meta b { color: #fff; border-color: transparent; background: var(--gc-bien); }
        .dark .gc-escala li.meta b { color: var(--gray-950); }

        .gc-bodega { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.15rem 0.5rem; padding: 0.35rem 0; font-size: 0.85rem; border-bottom: 1px dashed var(--gc-borde); }
        .gc-bodega:last-child { border-bottom: 0; }
        .gc-bodega span { font-weight: 600; }
        .gc-bodega em { font-style: normal; color: var(--gc-tenue); font-variant-numeric: tabular-nums; }

        .gc-cats { display: grid; gap: 0.35rem; font-size: 0.8rem; line-height: 1.45; }
        .gc-cats div b { margin-inline-end: 0.35rem; font-weight: 700; color: var(--gc-acento); }
        .gc-cats div span { color: var(--gc-tenue); }

        .gc-pie { padding-top: 0.75rem; border-top: 1px solid var(--gc-borde); font-size: 0.8rem; color: var(--gc-tenue); }

        /* Las tarjetas no se estiran con los textos largos. */
        .grupos-tarjetas .fi-ta-content-grid .fi-ta-record,
        .grupos-tarjetas .fi-ta-content-grid .fi-ta-record-content-ctn,
        .grupos-tarjetas .fi-ta-content-grid .fi-ta-record-content-ctn > div,
        .grupos-tarjetas .fi-ta-content-grid .fi-ta-record-content,
        .grupos-tarjetas .fi-ta-content-grid .fi-ta-col { min-width: 0; max-width: 100%; }

        /* Botones de abajo: Por bodega y Editar parejos, Borrar como ícono al final. */
        .grupos-tarjetas .fi-ta-content-grid .fi-ta-actions { width: 100%; justify-content: stretch; gap: 0.5rem; }
        .grupos-tarjetas .fi-ta-content-grid .fi-ta-actions > .fi-btn { flex: 1 1 0; justify-content: center; }
    </style>
@endonce

<div @class(['gc', 'apagado' => ! $grupo->activo])>
    <div>
        <div class="gc-cabeza">
            <div class="gc-nombre">{{ $grupo->nombre }}</div>
            @unless ($grupo->activo)
                <span class="gc-pill" title="Apagado: lo que se vende de sus categorías no paga comisión">No paga</span>
            @endunless
        </div>
        @if (filled($grupo->descripcion))
            <div class="gc-desc">{{ $grupo->descripcion }}</div>
        @endif
    </div>

    <div>
        <div class="gc-titulo">Escala del mes{{ $t['todas'] ? ' · para todas las bodegas' : '' }}</div>
        @if ($t['escalones'] === [])
            <div class="gc-vacio">Sin escala.</div>
        @else
            <ol class="gc-escala">
                @foreach ($t['escalones'] as $escalon)
                    <li @class(['meta' => $escalon['meta']])>
                        <span>
                            {{ $escalon['desde'] > 0 ? 'Desde '.lempiras($escalon['desde'], 0) : 'Desde el primer lempira' }}
                            @if ($escalon['meta'])
                                <small>Meta</small>
                            @endif
                        </span>
                        <b>{{ $porciento($escalon['porcentaje']) }}</b>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>

    <div>
        <div class="gc-titulo">{{ $t['todas'] ? 'Montos propios por bodega' : 'En su bodega' }}</div>
        @forelse ($t['bodegas'] as $bodega)
            <div class="gc-bodega">
                <span>{{ $t['todas'] ? $bodega['nombre'] : 'Su bodega' }}</span>
                <em>{{ $bodega['escala'] }}</em>
            </div>
        @empty
            <div class="gc-vacio">{{ $t['todas'] ? 'Ninguna: todas usan la escala de arriba.' : 'Usa la escala de arriba.' }}</div>
        @endforelse
    </div>

    <div>
        <div class="gc-titulo">{{ $t['cuantas'] === 1 ? '1 categoría' : $t['cuantas'].' categorías' }}</div>
        @if ($t['categorias'] === [])
            <div class="gc-vacio">Sin categorías: todavía no paga nada.</div>
        @else
            <div class="gc-cats">
                @foreach ($t['categorias'] as $proveedor => $nombres)
                    <div><b>{{ $proveedor }}</b><span>{{ implode(' · ', $nombres) }}</span></div>
                @endforeach
            </div>
        @endif
    </div>

    @if ($t['metas'] > 0)
        <div class="gc-pie">{{ $t['metas'] === 1 ? '1 vendedor tiene' : $t['metas'].' vendedores tienen' }} meta propia en este grupo.</div>
    @endif
</div>
