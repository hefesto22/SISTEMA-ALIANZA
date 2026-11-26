{{--
    Por imprimir, Impreso y Cargado de Pedidos (App\Filament\Resources\Pedidos\Pages\ListPedidos):
    arriba el cambio de vista (Por día / Uno por uno); por día, un cuadro por día y
    bodega (App\Domain\Facturacion\DiaDeFacturacion), del más reciente al más viejo.
      - Por imprimir: Ver (las tarjetas de ese día) · Imprimir las N (modal con la vista previa).
      - Impreso (los días con facturas por cargar): Ver · Resumen del día (la hoja
        para cuadrar) · Cargar camión (las facturas de ese día que todavía se pueden
        cargar; ListPedidos::cargarDiasAction). Cada cuadro dice cuántas van cargadas
        y cuántas faltan, y trae una casilla: se marcan varios días de una bodega y
        la barra de abajo carga el camión con todos.
      - Cargado (los días con facturas que ya van en una carga): Ver · Resumen del día.
    Por día la tabla no se dibuja y con ella no salen los modales de las
    acciones: aquí se ponen (x-filament-actions::modals).
    Datos: ListPedidos::datosPorDia().
--}}
@php
    /** @var list<\App\Domain\Facturacion\DiaDeFacturacion> $dias */
    $dias ??= [];
    $porDia ??= true;
    $impresos ??= false;
    $hayMas ??= false;
    $veTodo ??= false;
    $imprime ??= false;
    $resumen ??= false;
    $cargar ??= false;
    $cargados ??= false;
@endphp

<style>
    /* Mismos tonos que las tarjetas de pedidos: en oscuro el texto tenue usa gray-400. */
    .pd { --pd-tenue: var(--gray-500); --pd-borde: var(--gray-200); --pd-fondo: #fff; display: grid; gap: 1rem; min-width: 0; }
    .dark .pd { --pd-tenue: var(--gray-400); --pd-borde: rgb(255 255 255 / 0.1); --pd-fondo: var(--gray-900); }
    .pd-arriba { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem 1rem; }
    .pd-vistas { display: inline-flex; gap: 0.25rem; padding: 0.25rem; border-radius: 0.75rem; border: 1px solid var(--pd-borde); background: var(--pd-fondo); }
    .pd-vista { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.4rem 0.85rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 600; color: var(--pd-tenue); }
    .pd-vista:hover { color: inherit; }
    .pd-vista.activa { color: var(--primary-600); background: color-mix(in oklab, var(--primary-500) 12%, transparent); }
    .dark .pd-vista.activa { color: var(--primary-400); }
    .pd-icono { width: 1.1rem; height: 1.1rem; }
    .pd-ayuda { margin: 0; font-size: 0.875rem; color: var(--pd-tenue); }
    .pd-cuadros { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr)); }
    .pd-cuadro { min-width: 0; display: grid; align-content: start; gap: 0.85rem; padding: 1.1rem 1.25rem; border-radius: 0.75rem; border: 1px solid var(--pd-borde); background: var(--pd-fondo); }
    .pd-cuadro.hoy { border-color: color-mix(in oklab, var(--primary-500) 55%, transparent); }
    .pd-cabeza { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem; min-width: 0; }
    .pd-dia { font-size: 1.05rem; font-weight: 700; line-height: 1.3; }
    .pd-fecha { font-size: 0.8rem; color: var(--pd-tenue); font-variant-numeric: tabular-nums; }
    .pd-bodega { min-width: 0; max-width: 60%; font-size: 0.72rem; font-weight: 600; padding: 0.15rem 0.5rem; border-radius: 0.375rem; color: var(--primary-600); border: 1px solid color-mix(in oklab, var(--primary-500) 40%, transparent); background: color-mix(in oklab, var(--primary-500) 10%, transparent); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dark .pd-bodega { color: var(--primary-400); }
    .pd-cifras { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 0.25rem 0.75rem; }
    .pd-cuantas strong { font-size: 1.75rem; font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
    .pd-cuantas span { margin-inline-start: 0.3rem; font-size: 0.85rem; color: var(--pd-tenue); }
    .pd-total { font-size: 1.15rem; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .pd-datos { margin: 0; padding: 0.75rem 0 0; list-style: none; display: grid; gap: 0.3rem; font-size: 0.85rem; border-top: 1px solid var(--pd-borde); }
    .pd-datos li { display: flex; justify-content: space-between; gap: 0.75rem; }
    .pd-datos span { color: var(--pd-tenue); }
    .pd-datos b { font-weight: 600; text-align: end; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
    .pd-datos b.mal { color: var(--danger-600); }
    .dark .pd-datos b.mal { color: var(--danger-400); }
    .pd-botones { display: flex; flex-wrap: wrap; gap: 0.5rem; }
    .pd-vacio { padding: 3rem 1rem; text-align: center; color: var(--pd-tenue); border: 1px dashed var(--pd-borde); border-radius: 0.75rem; }
    .pd-mas { display: flex; justify-content: center; }
    /* Escoger días para cargar el camión. */
    .pd-titulo { display: flex; align-items: flex-start; gap: 0.6rem; min-width: 0; cursor: pointer; }
    .pd-titulo:has(.pd-escoger:disabled) { cursor: default; }
    .pd-titulo .pd-dia, .pd-titulo .pd-fecha { display: block; }
    .pd-escoger { margin-top: 0.3rem; flex: none; }
    .pd-cuadro:has(.pd-escoger:checked) { border-color: var(--primary-500); box-shadow: 0 0 0 1px var(--primary-500); }
    .pd-datos b.pend { color: var(--primary-600); }
    .dark .pd-datos b.pend { color: var(--primary-400); }
    .pd-barra { position: sticky; bottom: 1rem; z-index: 10; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem 1rem; padding: 0.75rem 1rem; border-radius: 0.75rem; border: 1px solid color-mix(in oklab, var(--primary-500) 50%, transparent); background: var(--pd-fondo); box-shadow: 0 12px 28px -12px rgb(0 0 0 / 0.45); }
    .pd-barra-texto { display: grid; gap: 0.1rem; min-width: 0; }
    .pd-barra-texto b { font-size: 0.95rem; font-weight: 700; }
    .pd-barra-texto span { font-size: 0.8rem; color: var(--pd-tenue); overflow-wrap: anywhere; }
</style>

<div
    class="pd"
    @if ($porDia) wire:poll.30s @endif
    @if ($cargar)
        x-data="{
            escogidos: [],
            marcados() {
                const escogidos = this.escogidos;

                return [...this.$root.querySelectorAll('input.pd-escoger')].filter((i) => escogidos.includes(i.value));
            },
            otra(bodega) {
                return this.escogidos.length > 0 && this.escogidos[0].split('|')[1] !== String(bodega);
            },
            resumen() {
                const dias = this.escogidos.length;
                const facturas = this.marcados().reduce((suma, i) => suma + Number(i.dataset.cuantas), 0);

                return (dias === 1 ? '1 día' : dias + ' días') + ' · ' + (facturas === 1 ? '1 factura' : facturas.toLocaleString('es-HN') + ' facturas') + ' para cargar';
            },
            detalle() {
                const marcados = this.marcados().sort((a, b) => a.value.localeCompare(b.value));

                return [marcados[0]?.dataset.bodega, marcados.map((i) => i.dataset.corto).join(', ')].filter(Boolean).join(' · ');
            },
            cargar() {
                this.$wire.mountAction('cargarDias', { dias: this.escogidos.map((v) => v.split('|')[0]), bodega: Number(this.escogidos[0].split('|')[1]) });
            },
        }"
    @endif
>
    <div class="pd-arriba">
        <div class="pd-vistas" role="group" aria-label="Cómo ver los pedidos">
            <button type="button" wire:click="$set('vista', null)" @class(['pd-vista', 'activa' => $porDia]) aria-pressed="{{ $porDia ? 'true' : 'false' }}">
                {{ svg('heroicon-o-calendar-days', 'pd-icono') }}
                Por día
            </button>
            <button type="button" wire:click="$set('vista', 'lista')" @class(['pd-vista', 'activa' => ! $porDia]) aria-pressed="{{ $porDia ? 'false' : 'true' }}">
                {{ svg('heroicon-o-squares-2x2', 'pd-icono') }}
                Uno por uno
            </button>
        </div>
        @if ($porDia)
            <p class="pd-ayuda">
                {{ match (true) {
                    $cargados => 'Los días que ya tienen facturas en una carga del camión (o ya entregadas), por el día en que se emitieron.',
                    $impresos => 'Los días que tienen facturas por cargar en el camión, por el día en que se emitieron: cuántas, por cuánto y con qué números.'.($cargar ? ' Marque uno o varios días para cargar el camión de un solo.' : ''),
                    default => 'Los pedidos listos para imprimir, por el día del pedido: imprima todas las de un día de un solo.',
                } }}
            </p>
        @endif
    </div>

    @if ($porDia)
        @if ($dias === [])
            <div class="pd-vacio">{{ match (true) {
                $cargados => 'Todavía no hay facturas en una carga del camión.',
                $impresos => 'No hay facturas por cargar: todas las impresas ya van en una carga.',
                default => 'No hay pedidos por imprimir.',
            } }}</div>
        @else
            <div class="pd-cuadros">
                @foreach ($dias as $d)
                    @php
                        $argumentos = \Illuminate\Support\Js::from(['dia' => $d->dia->format('Y-m-d'), 'bodega' => $d->bodegaId]);
                        $valor = $d->dia->format('Y-m-d').'|'.$d->bodegaId;
                        $pendientes = $d->sinCargar;
                    @endphp
                    <article @class(['pd-cuadro', 'hoy' => $d->dia->isToday()]) wire:key="cuadro-{{ $d->clave() }}">
                        <header class="pd-cabeza">
                            @if ($cargar)
                                <label class="pd-titulo">
                                    <input
                                        type="checkbox"
                                        class="fi-checkbox-input pd-escoger"
                                        value="{{ $valor }}"
                                        x-model="escogidos"
                                        data-cuantas="{{ $pendientes }}"
                                        data-corto="{{ $d->dia->format('d/m') }}"
                                        data-bodega="{{ $veTodo ? $d->bodega : '' }}"
                                        @if ($pendientes === 0)
                                            disabled
                                            title="Ya no quedan facturas de este día para cargar"
                                        @else
                                            x-bind:disabled="otra({{ $d->bodegaId }})"
                                            x-bind:title="otra({{ $d->bodegaId }}) ? 'Una carga lleva facturas de una sola bodega' : 'Escoger este día para cargar el camión'"
                                        @endif
                                    >
                                    <span>
                                        <span class="pd-dia">{{ $d->nombre() }}</span>
                                        <span class="pd-fecha">{{ $d->dia->format('d/m/Y') }}</span>
                                    </span>
                                </label>
                            @else
                                <div>
                                    <div class="pd-dia">{{ $d->nombre() }}</div>
                                    <div class="pd-fecha">{{ $d->dia->format('d/m/Y') }}</div>
                                </div>
                            @endif
                            @if ($veTodo)
                                <span class="pd-bodega" title="{{ $d->bodega }}">{{ $d->bodega }}</span>
                            @endif
                        </header>

                        <div class="pd-cifras">
                            <div class="pd-cuantas">
                                <strong>{{ number_format($d->cuantas) }}</strong>
                                <span>{{ $impresos ? ($d->cuantas === 1 ? 'factura' : 'facturas') : 'por imprimir' }}</span>
                            </div>
                            <div class="pd-total">{{ lempiras($d->total) }}</div>
                        </div>

                        <ul class="pd-datos">
                            @if ($impresos)
                                <li title="Del {{ $d->desde }} al {{ $d->hasta }}"><span>Números</span><b>{{ $d->numeros() }}</b></li>
                                <li><span>Anuladas</span><b @class(['mal' => $d->anuladas > 0])>{{ number_format($d->anuladas) }}</b></li>
                                <li><span>Copias</span><b>{{ number_format($d->copias) }}</b></li>
                                <li title="Facturas de este día que ya van en una carga, ya se entregaron o su día ya salió en su ruta"><span>Cargadas</span><b>{{ number_format($d->cargadas()) }}</b></li>
                                <li title="Facturas de este día que todavía no van en una carga"><span>Sin cargar</span><b @class(['pend' => $pendientes > 0])>{{ number_format($pendientes) }}</b></li>
                                @if ($d->sustituciones > 0)
                                    <li title="Facturas de este día que sustituyen a una anulada (de este día o de antes)"><span>Sustituyen a anuladas</span><b>{{ number_format($d->sustituciones) }}</b></li>
                                @endif
                            @else
                                <li><span>Vendedores</span><b>{{ number_format($d->vendedores) }}</b></li>
                                @if ($d->reimpresiones > 0)
                                    <li><span>Por reimprimir (corregidos)</span><b>{{ number_format($d->reimpresiones) }}</b></li>
                                @endif
                            @endif
                        </ul>

                        <footer class="pd-botones">
                            <x-filament::button size="sm" color="gray" outlined icon="heroicon-o-eye" wire:click="verDia('{{ $d->dia->format('Y-m-d') }}', {{ $d->bodegaId }})">
                                Ver
                            </x-filament::button>
                            @if ($impresos)
                                @if ($resumen)
                                    <x-filament::button size="sm" icon="heroicon-o-document-text" wire:click="mountAction('resumenDia', {{ $argumentos }})">
                                        Resumen del día
                                    </x-filament::button>
                                @endif
                                @if ($cargar && $pendientes > 0)
                                    <x-filament::button size="sm" color="gray" outlined icon="heroicon-o-truck" wire:click="mountAction('cargarDias', {{ \Illuminate\Support\Js::from(['dias' => [$d->dia->format('Y-m-d')], 'bodega' => $d->bodegaId]) }})">
                                        Cargar camión
                                    </x-filament::button>
                                @endif
                            @elseif ($imprime)
                                <x-filament::button size="sm" icon="heroicon-o-printer" wire:click="mountAction('imprimirDia', {{ $argumentos }})">
                                    {{ $d->cuantas === 1 ? 'Imprimir' : 'Imprimir las '.number_format($d->cuantas) }}
                                </x-filament::button>
                            @endif
                        </footer>
                    </article>
                @endforeach
            </div>

            @if ($cargar)
                <div class="pd-barra" x-show="escogidos.length > 0" x-cloak x-transition.opacity>
                    <div class="pd-barra-texto">
                        <b x-text="resumen()"></b>
                        <span x-text="detalle()"></span>
                    </div>
                    <div class="pd-botones">
                        <x-filament::button size="sm" color="gray" outlined x-on:click="escogidos = []">
                            Quitar
                        </x-filament::button>
                        <x-filament::button size="sm" icon="heroicon-o-truck" x-on:click="cargar()">
                            Cargar camión
                        </x-filament::button>
                    </div>
                </div>
            @endif

            @if ($hayMas)
                <div class="pd-mas">
                    <x-filament::button color="gray" outlined icon="heroicon-o-chevron-down" wire:click="masDias">
                        Ver más días
                    </x-filament::button>
                </div>
            @endif
        @endif

        {{-- Por día no se dibuja la tabla, que es la que trae los modales de las acciones (Imprimir, Resumen del día). --}}
        <x-filament-actions::modals />
    @endif
</div>
