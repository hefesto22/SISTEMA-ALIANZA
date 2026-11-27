{{--
    Arriba de los CAI (App\Filament\Resources\Cais\Pages\ManageCais::resumen()):
      - avisos: se factura con un CAI de prueba, bodegas que no pueden
        facturar (o emitir notas de crédito) y datos fiscales que faltan;
      - Datos fiscales: lo que sale impreso en cada factura, con Editar
        (acción datosFiscales de la página);
      - Por bodega: con qué CAI emite cada bodega activa (el suyo o el global),
        cuántos números le quedan y si vence pronto. Las bodegas que usan los
        mismos CAI van juntas en un renglón ("Todas las bodegas" si son todas).
    Datos: $fiscales, $rtn, $faltan, $edita, $notas, $bodegas, $prueba.
--}}
@php
    /** @var \App\Models\DatosFiscales $fiscales */
    /** @var list<array{nombre: string, factura: \App\Models\Cai|null, nota: \App\Models\Cai|null}> $bodegas */
    $sinFactura = array_column(array_filter($bodegas, fn (array $b): bool => $b['factura'] === null), 'nombre');
    $sinNota = $notas ? array_column(array_filter($bodegas, fn (array $b): bool => $b['nota'] === null), 'nombre') : [];
    $conNombre = filled($fiscales->nombre_comercial) || filled($fiscales->razon_social);
    $listaDe = fn (array $nombres): string => implode(', ', $nombres);
    $documentos = $notas
        ? ['factura' => 'Facturas', 'nota' => 'Notas de crédito']
        : ['factura' => 'Facturas'];

    // Las bodegas que emiten con los mismos CAI, juntas.
    $grupos = [];

    foreach ($bodegas as $b) {
        $clave = ($b['factura']?->id ?? 0).'-'.($b['nota']?->id ?? 0);
        $grupos[$clave] ??= ['nombres' => [], 'factura' => $b['factura'], 'nota' => $b['nota']];
        $grupos[$clave]['nombres'][] = $b['nombre'];
    }

    $todasIguales = count($grupos) === 1 && count($bodegas) > 1;
@endphp

<style>
    .cf { --cf-tenue: var(--gray-500); --cf-borde: var(--gray-200); --cf-fondo: #fff; --cf-suave: var(--gray-50); --cf-bien: var(--success-600); --cf-ojo: var(--warning-600); --cf-mal: var(--danger-600);
        display: grid; gap: 1rem; min-width: 0; }
    .dark .cf { --cf-tenue: var(--gray-400); --cf-borde: rgb(255 255 255 / 0.1); --cf-fondo: var(--gray-900); --cf-suave: rgb(255 255 255 / 0.04); --cf-bien: var(--success-400); --cf-ojo: var(--warning-400); --cf-mal: var(--danger-400); }

    .cf-avisos { display: grid; gap: 0.6rem; }
    .cf-tarjetas { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 24rem), 1fr)); align-items: start; }

    .cf-tarjeta { min-width: 0; display: grid; gap: 0.9rem; padding: 1.1rem 1.25rem; border-radius: 0.75rem; background: var(--cf-fondo); border: 1px solid var(--cf-borde); box-shadow: 0 1px 2px rgb(0 0 0 / 0.04); }
    .cf-cabeza { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem; }
    .cf-titulo { margin: 0; font-size: 0.95rem; font-weight: 700; line-height: 1.3; }
    .cf-ayuda { margin: 0.1rem 0 0; font-size: 0.78rem; line-height: 1.35; color: var(--cf-tenue); }

    .cf-chip { display: inline-flex; align-items: center; gap: 0.3rem; flex-shrink: 0; padding: 0.1rem 0.55rem; border-radius: 999px; font-size: 0.7rem; font-weight: 600; line-height: 1.5; white-space: nowrap; }
    .cf-chip::before { content: ''; width: 0.4rem; height: 0.4rem; border-radius: 999px; background: currentColor; }
    .cf-chip.bien { color: var(--cf-bien); background: color-mix(in oklab, var(--cf-bien) 12%, transparent); }
    .cf-chip.ojo { color: var(--cf-ojo); background: color-mix(in oklab, var(--cf-ojo) 14%, transparent); }
    .cf-chip.mal { color: var(--cf-mal); background: color-mix(in oklab, var(--cf-mal) 12%, transparent); }

    /* Datos fiscales: el nombre grande y los datos en dos columnas. */
    .cf-empresa { font-size: 1.05rem; font-weight: 700; line-height: 1.3; overflow-wrap: anywhere; }
    .cf-empresa small { display: block; margin-top: 0.1rem; font-size: 0.75rem; font-weight: 500; color: var(--cf-tenue); }
    .cf-datos { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.7rem 1rem; margin: 0; }
    .cf-datos div { min-width: 0; }
    .cf-datos .ancho { grid-column: 1 / -1; }
    .cf-datos dt { font-size: 0.66rem; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: var(--cf-tenue); }
    .cf-datos dd { margin: 0.1rem 0 0; font-size: 0.85rem; line-height: 1.4; overflow-wrap: anywhere; }
    .cf-datos dd.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace; font-variant-numeric: tabular-nums; }
    .cf-datos dd.falta { font-weight: 600; color: var(--cf-mal); }
    .cf-datos dd.nada { color: var(--cf-tenue); }
    @media (max-width: 480px) { .cf-datos { grid-template-columns: minmax(0, 1fr); } }

    /* Por bodega: un renglón por bodega, con el CAI de cada documento. */
    .cf-bodegas { display: grid; margin: 0; padding: 0; list-style: none; }
    .cf-bodega { display: grid; gap: 0.35rem; padding: 0.6rem 0; border-top: 1px solid var(--cf-borde); }
    .cf-bodega:first-child { padding-top: 0; border-top: 0; }
    .cf-bodega:last-child { padding-bottom: 0; }
    .cf-bodega > b { font-size: 0.82rem; font-weight: 700; }
    .cf-docs { display: grid; gap: 0.25rem; }
    .cf-doc { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.15rem 0.5rem; font-size: 0.78rem; line-height: 1.4; }
    .cf-doc > span:first-child { min-width: 7.5rem; color: var(--cf-tenue); }
    .cf-doc code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace; font-size: 0.76rem; font-weight: 600; }
    .cf-doc em { font-style: normal; color: var(--cf-tenue); font-variant-numeric: tabular-nums; }
    .cf-doc .ojo { font-weight: 600; color: var(--cf-ojo); }
    .cf-doc .mal { font-weight: 600; color: var(--cf-mal); }
</style>

<section class="cf">
    @if ($prueba || $sinFactura !== [] || $sinNota !== [] || $faltan !== [])
        <div class="cf-avisos">
            @if ($prueba)
                <x-filament::callout
                    color="warning"
                    icon="heroicon-o-beaker"
                    heading="Se está facturando con un CAI de prueba"
                    description="Lo que se imprime con él no vale ante el SAR. Agregue el CAI que le dio el SAR y desactive o borre el de prueba."
                />
            @endif
            @if ($sinFactura !== [])
                <x-filament::callout
                    color="danger"
                    icon="heroicon-o-no-symbol"
                    :heading="count($sinFactura) === 1 ? 'Una bodega no puede imprimir facturas' : count($sinFactura).' bodegas no pueden imprimir facturas'"
                    :description="'No tienen CAI de facturas vigente: '.$listaDe($sinFactura).'.'"
                />
            @endif
            @if ($sinNota !== [])
                <x-filament::callout
                    color="danger"
                    icon="heroicon-o-no-symbol"
                    :heading="count($sinNota) === 1 ? 'Una bodega no puede emitir notas de crédito' : count($sinNota).' bodegas no pueden emitir notas de crédito'"
                    :description="'No tienen CAI de notas de crédito vigente: '.$listaDe($sinNota).'. Las devoluciones con crédito no se pueden registrar.'"
                />
            @endif
            @if ($faltan !== [])
                <x-filament::callout
                    color="danger"
                    icon="heroicon-o-building-office-2"
                    heading="Faltan datos fiscales"
                    :description="'Sin '.$listaDe($faltan).' no se puede facturar.'"
                />
            @endif
        </div>
    @endif

    <div class="cf-tarjetas">
        <article class="cf-tarjeta">
            <div class="cf-cabeza">
                <div>
                    <h2 class="cf-titulo">Datos fiscales</h2>
                    <p class="cf-ayuda">Salen impresos en cada factura y nota de crédito.</p>
                </div>
                @if ($edita)
                    <x-filament::button size="sm" color="gray" outlined icon="heroicon-o-pencil-square" wire:click="mountAction('datosFiscales')">
                        Editar
                    </x-filament::button>
                @endif
            </div>

            @if ($conNombre)
                <div class="cf-empresa">
                    {{ $fiscales->nombreParaMostrar() }}
                    <small>Así sale, grande, arriba en la factura</small>
                </div>
            @endif

            <dl class="cf-datos">
                <div class="ancho">
                    <dt>Razón social</dt>
                    <dd @class(['falta' => blank($fiscales->razon_social)])>{{ $fiscales->razon_social ?: 'Falta' }}</dd>
                </div>
                <div>
                    <dt>RTN</dt>
                    <dd @class(['mono', 'falta' => blank($rtn)])>{{ $rtn ?: 'Falta' }}</dd>
                </div>
                <div>
                    <dt>Teléfono</dt>
                    <dd @class(['nada' => blank($fiscales->telefono)])>{{ $fiscales->telefono ?: '—' }}</dd>
                </div>
                <div class="ancho">
                    <dt>Dirección de la casa matriz</dt>
                    <dd @class(['falta' => blank($fiscales->direccion)])>{{ $fiscales->direccion ?: 'Falta' }}</dd>
                </div>
                <div class="ancho">
                    <dt>Correo</dt>
                    <dd @class(['nada' => blank($fiscales->correo)])>{{ $fiscales->correo ?: '—' }}</dd>
                </div>
            </dl>
        </article>

        <article class="cf-tarjeta">
            <div class="cf-cabeza">
                <div>
                    <h2 class="cf-titulo">Con qué CAI emite cada bodega</h2>
                    <p class="cf-ayuda">El suyo propio y, si no tiene uno vigente, el global.</p>
                </div>
                @if ($bodegas !== [])
                    @if ($sinFactura === [] && $sinNota === [])
                        <span class="cf-chip bien">Todas al día</span>
                    @else
                        <span class="cf-chip mal">{{ count(array_unique([...$sinFactura, ...$sinNota])) }} sin CAI</span>
                    @endif
                @endif
            </div>

            @if ($bodegas === [])
                <p class="cf-ayuda">No hay bodegas activas.</p>
            @else
                <ul class="cf-bodegas">
                    @foreach ($grupos as $b)
                        <li class="cf-bodega">
                            <b>{{ $todasIguales ? 'Todas las bodegas ('.count($b['nombres']).')' : implode(', ', $b['nombres']) }}</b>
                            <div class="cf-docs">
                                @foreach ($documentos as $clave => $documento)
                                    @php
                                        /** @var \App\Models\Cai|null $cai */
                                        $cai = $b[$clave];
                                        $pocos = $cai !== null && $cai->disponibles() <= max(10, (int) ($cai->totalNumeros() * 0.05));
                                        $pronto = $cai !== null && $cai->fecha_limite->lte(today()->addDays(30));
                                    @endphp
                                    <div class="cf-doc">
                                        <span>{{ $documento }}</span>
                                        @if ($cai === null)
                                            <span class="mal">Sin CAI vigente</span>
                                        @else
                                            <code>{{ $cai->prefijo() }}</code>
                                            <em>{{ $cai->bodegas->isEmpty() ? 'global' : 'propio' }}</em>
                                            <em @class(['ojo' => $pocos])>{{ number_format($cai->disponibles()) }} libres</em>
                                            <em @class(['ojo' => $pronto])>vence {{ $cai->fecha_limite->format('d/m/Y') }}</em>
                                            @if ($cai->esDePrueba())
                                                <span class="ojo">de prueba</span>
                                            @endif
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </article>
    </div>
</section>
