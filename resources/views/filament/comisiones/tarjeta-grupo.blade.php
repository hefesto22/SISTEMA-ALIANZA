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
            display: grid; grid-template-columns: minmax(0, 1fr); gap: 0.7rem; width: 100%; min-width: 0; text-align: start; white-space: normal; }
        .dark .gc { --gc-tenue: var(--gray-400); --gc-borde: rgb(255 255 255 / 0.1); --gc-suave: rgb(255 255 255 / 0.05); --gc-bien: var(--success-400); --gc-acento: var(--primary-400); }
        .gc.apagado { opacity: 0.6; }

        .gc-cabeza { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
        .gc-nombre { font-size: 1rem; font-weight: 700; line-height: 1.3; letter-spacing: 0.01em; overflow-wrap: anywhere; }
        .gc-desc { margin-top: 0.1rem; font-size: 0.8rem; line-height: 1.4; color: var(--gc-tenue); }
        .gc-pill { flex-shrink: 0; font-size: 0.7rem; font-weight: 600; line-height: 1.4; padding: 0.05rem 0.5rem; border-radius: 999px; border: 1px solid var(--gc-borde); color: var(--gc-tenue); }

        .gc-titulo { margin-bottom: 0.3rem; font-size: 0.66rem; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: var(--gc-tenue); }
        .gc-vacio { font-size: 0.8rem; color: var(--gc-tenue); }

        /* La escala en una fila: cada escalón con su % arriba y desde cuánto abajo; la meta en verde. */
        .gc-escala { display: grid; grid-template-columns: repeat(auto-fit, minmax(6.25rem, 1fr)); gap: 0.3rem; margin: 0; padding: 0; list-style: none; font-variant-numeric: tabular-nums; }
        .gc-escala li { display: grid; gap: 0.05rem; padding: 0.35rem 0.55rem; border-radius: 0.5rem; background: var(--gc-suave); border: 1px solid transparent; }
        .gc-escala li b { display: flex; align-items: baseline; justify-content: space-between; gap: 0.3rem; font-size: 1rem; font-weight: 700; line-height: 1.25; }
        .gc-escala li b small { font-size: 0.6rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; }
        .gc-escala li span { font-size: 0.72rem; line-height: 1.3; color: var(--gc-tenue); white-space: nowrap; }
        .gc-escala li.meta { border-color: color-mix(in oklab, var(--gc-bien) 40%, transparent); background: color-mix(in oklab, var(--gc-bien) 10%, transparent); }
        .gc-escala li.meta b { color: var(--gc-bien); }
        .gc-escala li.meta span { color: var(--gc-bien); }

        /* Por bodega: una línea si ninguna puso sus montos. */
        .gc-linea { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.1rem 0.5rem; }
        .gc-linea .gc-titulo { margin: 0; }
        .gc-bodegas { display: grid; gap: 0.2rem; }
        .gc-bodega { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.1rem 0.45rem; font-size: 0.8rem; }
        .gc-bodega span { font-weight: 600; }
        .gc-bodega em { font-style: normal; color: var(--gc-tenue); font-variant-numeric: tabular-nums; }

        /* Categorías: un renglón por proveedor (el nombre a la izquierda, alineado) y sus categorías como etiquetas. */
        .gc-cats { display: grid; grid-template-columns: max-content minmax(0, 1fr); align-items: start; gap: 0.35rem 0.65rem; }
        .gc-cats > b { padding-top: 0.12rem; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.02em; color: var(--gc-acento); }
        .gc-cats > ul { display: flex; flex-wrap: wrap; gap: 0.25rem; margin: 0; padding: 0; list-style: none; }
        .gc-cats > ul li { padding: 0.05rem 0.45rem; font-size: 0.68rem; line-height: 1.5; border-radius: 0.35rem; border: 1px solid var(--gc-borde); background: var(--gc-suave); white-space: nowrap; }

        .gc-pie { padding-top: 0.55rem; border-top: 1px solid var(--gc-borde); font-size: 0.76rem; color: var(--gc-tenue); }

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
                    <li @class(['meta' => $escalon['meta']]) title="{{ $porciento($escalon['porcentaje']) }} {{ $escalon['desde'] > 0 ? 'desde '.lempiras($escalon['desde'], 0) : 'desde el primer lempira' }}">
                        <b>
                            {{ $porciento($escalon['porcentaje']) }}
                            @if ($escalon['meta'])
                                <small>Meta</small>
                            @endif
                        </b>
                        <span>{{ $escalon['desde'] > 0 ? 'desde '.lempiras($escalon['desde'], 0) : 'desde 0' }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>

    @if ($t['bodegas'] === [])
        <div class="gc-linea">
            <span class="gc-titulo">{{ $t['todas'] ? 'Por bodega' : 'Su bodega' }}</span>
            <span class="gc-vacio">{{ $t['todas'] ? 'todas usan esta escala' : 'usa esta escala' }}</span>
        </div>
    @else
        <div>
            <div class="gc-titulo">{{ $t['todas'] ? 'Montos propios por bodega' : 'En su bodega' }}</div>
            <div class="gc-bodegas">
                @foreach ($t['bodegas'] as $bodega)
                    <div class="gc-bodega">
                        <span>{{ $t['todas'] ? $bodega['nombre'] : 'Su bodega' }}</span>
                        <em>{{ $bodega['escala'] }}</em>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div>
        <div class="gc-titulo">{{ $t['cuantas'] === 1 ? '1 categoría' : $t['cuantas'].' categorías' }}</div>
        @if ($t['categorias'] === [])
            <div class="gc-vacio">Sin categorías: todavía no paga nada.</div>
        @else
            <div class="gc-cats">
                @foreach ($t['categorias'] as $proveedor => $nombres)
                    <b>{{ $proveedor }}</b>
                    <ul>
                        @foreach ($nombres as $nombre)
                            <li>{{ $nombre }}</li>
                        @endforeach
                    </ul>
                @endforeach
            </div>
        @endif
    </div>

    @if ($t['metas'] > 0)
        <div class="gc-pie">{{ $t['metas'] === 1 ? '1 vendedor tiene' : $t['metas'].' vendedores tienen' }} meta propia en este grupo.</div>
    @endif
</div>
