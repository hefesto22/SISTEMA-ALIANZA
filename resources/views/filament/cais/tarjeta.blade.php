{{--
    Tarjeta de un CAI en CAI y datos fiscales: el documento (factura o nota
    de crédito) y su estado, el CAI (con copiar) y su nota, la numeración
    autorizada, la fecha límite, cuánto se ha usado (barra) con el número
    que sigue, y las bodegas que lo usan.
    Columna: App\Filament\Resources\Cais\CaiResource (ViewColumn "tarjeta").
--}}
@php
    use App\Models\Cai;

    /** @var Cai $cai */
    $cai = $getRecord();
    $situacion = $cai->situacion();
    $total = $cai->totalNumeros();
    $usados = min($cai->usados(), $total);
    $porciento = $total > 0 ? round($usados * 100 / $total, 1) : 0.0;
    $pocos = ! $cai->estaAgotado() && $cai->disponibles() <= max(10, (int) ($total * 0.05));
    $pronto = ! $cai->estaVencido() && $cai->fecha_limite->lte(today()->addDays(30));
    $esNota = $cai->tipo_documento === Cai::NOTA_CREDITO;
    $correlativo = fn (int $n): string => str_pad((string) $n, 8, '0', STR_PAD_LEFT);
    $bodegas = $cai->bodegas->pluck('nombre')->all();
    $estado = match ($situacion) {
        'Vigente'  => 'bien',
        'Inactivo' => 'apagado',
        default    => 'mal',
    };
@endphp

@once
    <style>
        .ct { --ct-tenue: var(--gray-500); --ct-borde: var(--gray-200); --ct-suave: var(--gray-100); --ct-bien: var(--success-600); --ct-ojo: var(--warning-600); --ct-mal: var(--danger-600); --ct-acento: var(--primary-600);
            display: grid; grid-template-columns: minmax(0, 1fr); gap: 0.75rem; width: 100%; min-width: 0; text-align: start; white-space: normal; font-variant-numeric: tabular-nums; }
        .dark .ct { --ct-tenue: var(--gray-400); --ct-borde: rgb(255 255 255 / 0.1); --ct-suave: rgb(255 255 255 / 0.08); --ct-bien: var(--success-400); --ct-ojo: var(--warning-400); --ct-mal: var(--danger-400); --ct-acento: var(--primary-400); }
        .ct.apagado { opacity: 0.65; }

        .ct-cabeza { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.4rem 0.75rem; }
        .ct-doc { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: var(--ct-acento); }
        .ct-doc small { padding: 0 0.35rem; border-radius: 0.3rem; font-size: 0.66rem; letter-spacing: 0.02em; color: var(--ct-tenue); background: var(--ct-suave); }
        .ct-doc.nota { color: var(--ct-ojo); }
        .ct-chips { display: flex; flex-wrap: wrap; gap: 0.3rem; }
        .ct-chip { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.05rem 0.5rem; border-radius: 999px; font-size: 0.7rem; font-weight: 600; line-height: 1.5; white-space: nowrap; }
        .ct-chip::before { content: ''; width: 0.4rem; height: 0.4rem; border-radius: 999px; background: currentColor; }
        .ct-chip.bien { color: var(--ct-bien); background: color-mix(in oklab, var(--ct-bien) 12%, transparent); }
        .ct-chip.ojo { color: var(--ct-ojo); background: color-mix(in oklab, var(--ct-ojo) 14%, transparent); }
        .ct-chip.mal { color: var(--ct-mal); background: color-mix(in oklab, var(--ct-mal) 12%, transparent); }
        .ct-chip.apagado { color: var(--ct-tenue); background: var(--ct-suave); }

        .ct-cai { display: flex; align-items: center; gap: 0.35rem; min-width: 0; }
        .ct-cai code { min-width: 0; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace; font-size: 0.85rem; font-weight: 600; letter-spacing: 0.01em; overflow-wrap: anywhere; }
        .ct-copiar { flex-shrink: 0; cursor: pointer; display: inline-grid; place-items: center; width: 1.6rem; height: 1.6rem; border-radius: 0.4rem; color: var(--ct-tenue); }
        .ct-copiar:hover { color: inherit; background: var(--ct-suave); }
        .ct-copiar .ct-ico { width: 1rem; height: 1rem; }
        .ct-nota { margin-top: 0.15rem; font-size: 0.76rem; line-height: 1.4; color: var(--ct-tenue); }

        .ct-datos { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem 1rem; margin: 0; }
        .ct-datos dt { font-size: 0.64rem; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: var(--ct-tenue); }
        .ct-datos dd { margin: 0.1rem 0 0; display: grid; font-size: 0.85rem; line-height: 1.35; }
        .ct-datos dd b { font-weight: 600; }
        .ct-datos dd b.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace; }
        .ct-datos dd span { font-size: 0.76rem; color: var(--ct-tenue); }
        .ct-datos .ojo { color: var(--ct-ojo); }
        .ct-datos .mal { color: var(--ct-mal); }

        /* Cuánto se ha usado: texto, barra y el número que sigue. */
        .ct-uso { display: grid; gap: 0.3rem; }
        .ct-uso-txt { display: flex; justify-content: space-between; gap: 0.5rem; font-size: 0.78rem; color: var(--ct-tenue); }
        .ct-uso-txt b { font-weight: 700; color: var(--gray-950); }
        .dark .ct-uso-txt b { color: #fff; }
        .ct-barra { height: 0.4rem; border-radius: 999px; overflow: hidden; background: var(--ct-suave); }
        .ct-barra i { display: block; height: 100%; min-width: 0.4rem; border-radius: inherit; background: var(--ct-acento); }
        .ct-barra.ojo i { background: var(--ct-ojo); }
        .ct-barra.mal i { background: var(--ct-mal); }
        .ct-sig { font-size: 0.76rem; color: var(--ct-tenue); }
        .ct-sig code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace; font-weight: 600; color: inherit; }

        .ct-pie { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.25rem 0.5rem; padding-top: 0.65rem; border-top: 1px solid var(--ct-borde); font-size: 0.78rem; }
        .ct-pie > span { font-size: 0.64rem; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: var(--ct-tenue); }
        .ct-pie ul { display: flex; flex-wrap: wrap; gap: 0.25rem; margin: 0; padding: 0; list-style: none; }
        .ct-pie li { padding: 0 0.4rem; border-radius: 0.3rem; font-size: 0.72rem; line-height: 1.6; background: var(--ct-suave); }
        .ct-pie em { font-style: normal; color: var(--ct-tenue); }

        /* Las tarjetas no se estiran con los textos largos. */
        .cai-tarjetas .fi-ta-content-grid .fi-ta-record,
        .cai-tarjetas .fi-ta-content-grid .fi-ta-record-content-ctn,
        .cai-tarjetas .fi-ta-content-grid .fi-ta-record-content-ctn > div,
        .cai-tarjetas .fi-ta-content-grid .fi-ta-record-content,
        .cai-tarjetas .fi-ta-content-grid .fi-ta-col { min-width: 0; max-width: 100%; }

        /* Abajo: Editar ancho y Borrar como ícono al final. */
        .cai-tarjetas .fi-ta-content-grid .fi-ta-actions { width: 100%; justify-content: stretch; gap: 0.5rem; }
        .cai-tarjetas .fi-ta-content-grid .fi-ta-actions > .fi-btn { flex: 1 1 0; justify-content: center; }
    </style>
@endonce

<div @class(['ct', 'apagado' => ! $cai->activo])>
    <div class="ct-cabeza">
        <span @class(['ct-doc', 'nota' => $esNota])>
            {{ $esNota ? 'Nota de crédito' : 'Factura' }}
            <small>{{ $cai->tipo_documento }}</small>
        </span>
        <span class="ct-chips">
            @if ($cai->esDePrueba())
                <span class="ct-chip ojo" title="No vale ante el SAR: cámbielo por el que le dieron">De prueba</span>
            @endif
            <span class="ct-chip {{ $estado }}">{{ $situacion }}</span>
        </span>
    </div>

    <div>
        <div class="ct-cai" x-data="{ copiado: false }">
            <code>{{ $cai->cai }}</code>
            {{-- La tarjeta entera es un botón (abre Editar): adentro no puede ir otro <button>. --}}
            <span
                role="button"
                tabindex="0"
                class="ct-copiar"
                title="Copiar el CAI"
                x-show="!! window.navigator.clipboard"
                x-on:click.stop.prevent="window.navigator.clipboard.writeText(@js($cai->cai)); copiado = true; setTimeout(() => copiado = false, 1500)"
                x-on:keydown.enter.stop.prevent="window.navigator.clipboard.writeText(@js($cai->cai)); copiado = true; setTimeout(() => copiado = false, 1500)"
            >
                <x-filament::icon icon="heroicon-o-clipboard-document" class="ct-ico" x-show="! copiado" />
                <x-filament::icon icon="heroicon-o-check" class="ct-ico" x-show="copiado" x-cloak />
            </span>
        </div>
        @if (filled($cai->notas))
            <div class="ct-nota">{{ $cai->notas }}</div>
        @endif
    </div>

    <dl class="ct-datos">
        <div>
            <dt>Numeración</dt>
            <dd>
                <b class="mono">{{ $cai->prefijo() }}</b>
                <span>{{ $correlativo($cai->rango_desde) }} al {{ $correlativo($cai->rango_hasta) }}</span>
            </dd>
        </div>
        <div>
            <dt>Fecha límite</dt>
            <dd>
                <b @class(['mal' => $cai->estaVencido(), 'ojo' => $pronto])>{{ $cai->fecha_limite->format('d/m/Y') }}</b>
                <span @class(['mal' => $cai->estaVencido(), 'ojo' => $pronto])>{{ $cai->estaVencido() ? 'Ya venció' : 'Vence '.$cai->fecha_limite->copy()->endOfDay()->diffForHumans() }}</span>
            </dd>
        </div>
    </dl>

    <div class="ct-uso">
        <div class="ct-uso-txt">
            <span><b>{{ number_format($usados) }}</b> de {{ number_format($total) }} usados</span>
            <span>{{ number_format($cai->disponibles()) }} libres</span>
        </div>
        <div @class(['ct-barra', 'mal' => $cai->estaAgotado(), 'ojo' => $pocos])>
            <i style="width: {{ $porciento }}%"></i>
        </div>
        <div class="ct-sig">
            @if ($cai->estaAgotado())
                Ya no quedan números: pida otro CAI al SAR.
            @else
                Sigue: <code>{{ $cai->numero($cai->siguiente) }}</code>
            @endif
        </div>
    </div>

    <div class="ct-pie">
        <span>Bodegas</span>
        @if ($bodegas === [])
            <em>Global: todas las que no tengan uno propio</em>
        @else
            <ul>
                @foreach ($bodegas as $nombre)
                    <li>{{ $nombre }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
