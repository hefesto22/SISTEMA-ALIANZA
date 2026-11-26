{{--
    Escoger las facturas que lleva el camión (campo "facturas" de
    App\Filament\Resources\Cargas\Schemas\CargaForm).

    - Arriba, fija al bajar: cuánto lleva (monto grande, cuántas facturas y de
      qué vendedores), "Quitar todas" y Guardar.
    - Buscador (número, cliente, vendedor o municipio, sin importar tildes),
      filtro por ruta y vendedor, "Impresas desde", "Solo las marcadas" y "Marcar las
      N que se ven".
    - Las facturas por día (la más reciente primero) y, dentro, una tarjeta por
      ruta y vendedor ("Ruta 22 · ALVARO"; sin ruta, al final). La casilla del
      día o de la tarjeta marca o quita todas las que se ven de ese grupo; toda
      la fila marca la factura.
    - Si no hay facturas desde ese día pero quedaron sin cargar de días atrás,
      lo dice y ofrece verlas.

    Marcar, buscar y filtrar pasa en el navegador (Alpine); al servidor solo va
    "Impresas desde" (trae otra lista) y Guardar. Si la lista cambia, cambia
    la clave y se arma de nuevo.
    Datos: CargaForm::paraEscoger().
--}}
@php
    /** @var string $clave */
    $statePath = $getStatePath();
    $rutaDesde = \Illuminate\Support\Str::beforeLast($statePath, '.').'.desde';
    $diaCorto = fn (?string $fecha): string => $fecha !== null ? \Illuminate\Support\Carbon::parse($fecha)->format('d/m') : '';
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <style>
        .cg-escoger { --cg-borde: var(--gray-200); --cg-suave: var(--gray-50); --cg-tenue: var(--gray-500); --cg-fondo: #fff; --cg-marcada: var(--primary-50); --cg-marcada-borde: var(--primary-200); display: grid; gap: 1rem; font-variant-numeric: tabular-nums; }
        .dark .cg-escoger { --cg-borde: rgb(255 255 255 / 0.1); --cg-suave: rgb(255 255 255 / 0.03); --cg-tenue: var(--gray-400); --cg-fondo: var(--gray-900); --cg-marcada: color-mix(in oklab, var(--primary-500) 14%, transparent); --cg-marcada-borde: color-mix(in oklab, var(--primary-400) 35%, transparent); }

        .cg-barra { position: sticky; top: var(--topbar-height, 4rem); z-index: 8; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem 1.5rem; padding: 0.85rem 1.1rem; border-radius: 0.85rem; background: var(--cg-fondo); box-shadow: 0 6px 18px -10px rgb(0 0 0 / 0.25); outline: 1px solid var(--cg-borde); }
        .cg-barra-total { min-width: 0; display: grid; gap: 0.1rem; }
        .cg-barra-etiqueta { font-size: 0.72rem; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: var(--cg-tenue); }
        .cg-barra-monto { font-size: 1.6rem; font-weight: 800; line-height: 1.1; letter-spacing: -0.01em; }
        .cg-barra-detalle { font-size: 0.8rem; color: var(--cg-tenue); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 52rem; }
        .cg-barra-acciones { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }

        .cg-filtros { display: flex; flex-wrap: wrap; align-items: end; gap: 0.75rem; }
        .cg-filtro { display: grid; gap: 0.3rem; min-width: 0; }
        .cg-filtro > span { font-size: 0.8rem; font-weight: 500; color: var(--cg-tenue); }
        .cg-filtro-buscar { flex: 1 1 18rem; }
        .cg-filtro-vendedor { flex: 0 1 19rem; }
        .cg-filtro-desde { flex: 0 0 11rem; }
        .cg-solo { display: inline-flex; align-items: center; gap: 0.5rem; height: 2.25rem; font-size: 0.875rem; white-space: nowrap; cursor: pointer; }

        .cg-dia { display: grid; gap: 0.6rem; }
        .cg-dia-cab { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 0.25rem 1rem; padding-bottom: 0.4rem; border-bottom: 1px solid var(--cg-borde); }
        .cg-dia-titulo { display: inline-flex; align-items: center; gap: 0.6rem; font-size: 0.95rem; font-weight: 700; cursor: pointer; }
        .cg-dia-resumen { font-size: 0.8rem; color: var(--cg-tenue); }

        .cg-vendedores { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 25rem), 1fr)); gap: 0.75rem; align-items: start; }
        .cg-vendedor { border: 1px solid var(--cg-borde); border-radius: 0.75rem; overflow: hidden; background: var(--cg-fondo); }
        .cg-vendedor-cab { display: flex; align-items: center; gap: 0.6rem; padding: 0.6rem 0.85rem; background: var(--cg-suave); border-bottom: 1px solid var(--cg-borde); cursor: pointer; }
        .cg-vendedor-nombre { flex: 1; min-width: 0; display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; font-weight: 700; }
        .cg-vendedor-nombre > span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .cg-ruta { flex: none; padding: 0.1rem 0.45rem; border-radius: 0.375rem; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.02em; white-space: nowrap; color: var(--primary-700); background: var(--primary-50); outline: 1px solid var(--primary-200); }
        .dark .cg-ruta { color: var(--primary-300); background: color-mix(in oklab, var(--primary-500) 15%, transparent); outline-color: color-mix(in oklab, var(--primary-400) 30%, transparent); }
        .cg-ruta.cg-sin-ruta { color: var(--cg-tenue); background: transparent; outline-color: var(--cg-borde); }
        .cg-vendedor-resumen { font-size: 0.75rem; color: var(--cg-tenue); white-space: nowrap; }

        .cg-filas { margin: 0; padding: 0; list-style: none; }
        .cg-filas li + li .cg-fila { border-top: 1px solid var(--cg-borde); }
        .cg-fila { display: grid; grid-template-columns: auto 3.25rem minmax(0, 1fr) auto; align-items: center; gap: 0.7rem; padding: 0.5rem 0.85rem; font-size: 0.85rem; cursor: pointer; transition: background-color 0.12s; }
        .cg-fila:hover { background: var(--cg-suave); }
        .cg-fila.cg-marcada { background: var(--cg-marcada); box-shadow: inset 3px 0 0 var(--primary-500); }
        .cg-num { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8rem; font-weight: 600; color: var(--cg-tenue); }
        .cg-marcada .cg-num { color: var(--primary-600); }
        .dark .cg-marcada .cg-num { color: var(--primary-400); }
        .cg-cliente { min-width: 0; display: grid; }
        .cg-cliente b { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .cg-cliente small { font-size: 0.75rem; color: var(--cg-tenue); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .cg-total { font-weight: 600; white-space: nowrap; }

        .cg-vacio, .cg-atras { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem 1rem; padding: 1rem 1.1rem; border: 1px dashed var(--cg-borde); border-radius: 0.75rem; font-size: 0.875rem; color: var(--cg-tenue); }
        .cg-vacio strong { display: block; margin-bottom: 0.15rem; font-size: 0.95rem; color: var(--gray-950); }
        .dark .cg-vacio strong { color: #fff; }
        .cg-atras { border-style: solid; background: var(--cg-suave); }

        @media (max-width: 639.98px) {
            .cg-barra-monto { font-size: 1.35rem; }
            .cg-filtro-vendedor, .cg-filtro-desde { flex: 1 1 100%; }
            .cg-fila { grid-template-columns: auto minmax(0, 1fr) auto; }
            .cg-fila .cg-num { display: none; }
        }
    </style>

    @if ($bodega === null)
        <div class="cg-escoger">
            <div class="cg-vacio">
                <div>
                    <strong>Escoja la bodega</strong>
                    Arriba, en "La salida": aquí salen sus facturas impresas para marcar las que lleva el camión.
                </div>
            </div>
        </div>
    @else
        <div
            class="cg-escoger"
            wire:key="cg-escoger-{{ $clave }}"
            wire:ignore
            x-data="{
                state: $wire.$entangle(@js($statePath)),
                facturas: @js((object) $mapa),
                grupos: @js((object) $grupos),
                buscar: '',
                vendedor: '',
                soloMarcadas: false,

                init() {
                    let lista = Array.isArray(this.state) ? this.state : Object.values(this.state ?? {})
                    // Solo las que están en la lista (si se cambió el día, las de fuera se quitan).
                    let limpia = lista.map(String).filter((id) => id in this.facturas)

                    if (limpia.length !== lista.length || lista.some((id) => typeof id !== 'string')) {
                        this.state = limpia
                    }
                },

                ids() {
                    return (Array.isArray(this.state) ? this.state : Object.values(this.state ?? {})).map(String)
                },

                esta(id) {
                    return this.ids().includes(String(id))
                },

                normal(texto) {
                    return String(texto ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim()
                },

                ve(id) {
                    let factura = this.facturas[id]

                    if (! factura) return false
                    if (this.vendedor !== '' && factura.v !== this.vendedor) return false
                    if (this.soloMarcadas && ! this.esta(id)) return false

                    let palabras = this.normal(this.buscar).split(/\s+/).filter((p) => p !== '')

                    return palabras.every((p) => factura.b.includes(p))
                },

                visibles() {
                    return Object.keys(this.facturas).filter((id) => this.ve(id))
                },

                visiblesDe(grupo) {
                    return (this.grupos[grupo] ?? []).filter((id) => this.ve(id))
                },

                hayEn(grupo) {
                    return this.visiblesDe(grupo).length > 0
                },

                marcadasEn(grupo) {
                    return (this.grupos[grupo] ?? []).filter((id) => this.esta(id)).length
                },

                grupoCompleto(grupo) {
                    let visibles = this.visiblesDe(grupo)

                    return visibles.length > 0 && visibles.every((id) => this.esta(id))
                },

                grupoParcial(grupo) {
                    let visibles = this.visiblesDe(grupo)
                    let marcadas = visibles.filter((id) => this.esta(id)).length

                    return marcadas > 0 && marcadas < visibles.length
                },

                alternar(id) {
                    id = String(id)
                    this.state = this.esta(id) ? this.ids().filter((otra) => otra !== id) : [...this.ids(), id]
                },

                alternarGrupo(grupo) {
                    let visibles = this.visiblesDe(grupo)

                    this.state = this.grupoCompleto(grupo)
                        ? this.ids().filter((id) => ! visibles.includes(id))
                        : [...new Set([...this.ids(), ...visibles])]
                },

                porMarcar() {
                    return this.visibles().filter((id) => ! this.esta(id)).length
                },

                marcarVisibles() {
                    this.state = [...new Set([...this.ids(), ...this.visibles()])]
                },

                quitarTodas() {
                    this.state = []
                    this.soloMarcadas = false
                },

                limpiarFiltros() {
                    this.buscar = ''
                    this.vendedor = ''
                    this.soloMarcadas = false
                },

                marcadas() {
                    return this.ids().filter((id) => id in this.facturas)
                },

                total() {
                    return this.marcadas().reduce((suma, id) => suma + Number(this.facturas[id].t), 0)
                },

                detalle() {
                    let marcadas = this.marcadas()

                    if (marcadas.length === 0) return 'Marque abajo las facturas que van en el camión'

                    let porVendedor = {}

                    marcadas.forEach((id) => {
                        let nombre = this.facturas[id].v
                        porVendedor[nombre] = (porVendedor[nombre] ?? 0) + 1
                    })

                    let vendedores = Object.entries(porVendedor).map(([nombre, cuantas]) => nombre + ' ' + cuantas).join(', ')

                    return (marcadas.length === 1 ? '1 factura' : marcadas.length + ' facturas') + ' · ' + vendedores
                },

                lempiras(monto) {
                    return 'L. ' + Number(monto).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                },
            }"
        >
            {{-- Cuánto lleva: se queda arriba al bajar por la lista. --}}
            <div class="cg-barra">
                <div class="cg-barra-total">
                    <span class="cg-barra-etiqueta">Sale con</span>
                    <span class="cg-barra-monto" x-text="lempiras(total())">L. 0.00</span>
                    <span class="cg-barra-detalle" x-text="detalle()"></span>
                </div>
                <div class="cg-barra-acciones">
                    <x-filament::button type="button" color="gray" size="sm" x-show="marcadas().length > 0" x-cloak x-on:click="quitarTodas()">
                        Quitar todas
                    </x-filament::button>
                    <x-filament::button type="submit" icon="heroicon-o-truck">
                        Guardar carga
                    </x-filament::button>
                </div>
            </div>

            {{-- Buscar y filtrar. --}}
            <div class="cg-filtros">
                <label class="cg-filtro cg-filtro-buscar">
                    <span>Buscar</span>
                    <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                        <x-filament::input type="search" x-model="buscar" placeholder="Número, cliente, vendedor o municipio" autocomplete="off" />
                    </x-filament::input.wrapper>
                </label>

                @if (count($vendedores) > 1)
                    <label class="cg-filtro cg-filtro-vendedor">
                        <span>Ruta y vendedor</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select x-model="vendedor">
                                <option value="">Todos ({{ count($mapa) }})</option>
                                @foreach ($vendedores as $nombre => $cuantas)
                                    <option value="{{ $nombre }}">{{ $nombre }} ({{ $cuantas }})</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                @endif

                <label class="cg-filtro cg-filtro-desde">
                    <span>Impresas desde</span>
                    <x-filament::input.wrapper>
                        <x-filament::input type="date" value="{{ $desde }}" max="{{ $hasta }}" x-on:change="if ($event.target.value) $wire.set('{{ $rutaDesde }}', $event.target.value)" />
                    </x-filament::input.wrapper>
                </label>

                <label class="cg-solo">
                    <x-filament::input.checkbox x-model="soloMarcadas" />
                    Solo las marcadas
                </label>

                @if ($mapa !== [])
                    <x-filament::button type="button" color="gray" outlined x-on:click="marcarVisibles()" x-bind:disabled="porMarcar() === 0">
                        <span x-text="porMarcar() === 0 ? 'Todas las que se ven ya están marcadas' : (porMarcar() === 1 ? 'Marcar la que se ve' : 'Marcar las ' + porMarcar() + ' que se ven')">Marcar las que se ven</span>
                    </x-filament::button>
                @endif
            </div>

            @if ($dias === [])
                <div class="cg-vacio">
                    <div>
                        <strong>No hay facturas impresas sin cargar desde el {{ $diaCorto($desde) }}</strong>
                        @if ($anteriores['cuantas'] > 0)
                            Quedaron {{ $anteriores['cuantas'] === 1 ? '1 factura' : $anteriores['cuantas'].' facturas' }} sin cargar de días atrás; la más reciente es del {{ $diaCorto($anteriores['ultimo']) }}.
                        @else
                            Las de estos días ya van en una carga o ya se entregaron.
                        @endif
                    </div>
                    @if ($anteriores['cuantas'] > 0)
                        <div class="cg-barra-acciones">
                            @if ($anteriores['desde'] !== $anteriores['ultimo'])
                                <x-filament::button type="button" color="gray" x-on:click="$wire.set('{{ $rutaDesde }}', '{{ $anteriores['desde'] }}')">
                                    Ver todas, desde el {{ $diaCorto($anteriores['desde']) }}
                                </x-filament::button>
                            @endif
                            <x-filament::button type="button" icon="heroicon-m-calendar-days" x-on:click="$wire.set('{{ $rutaDesde }}', '{{ $anteriores['ultimo'] }}')">
                                Ver las del {{ $diaCorto($anteriores['ultimo']) }}
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            @else
                @foreach ($dias as $dia)
                    <section class="cg-dia" x-show="hayEn('{{ $dia['clave'] }}')">
                        <header class="cg-dia-cab">
                            <label class="cg-dia-titulo">
                                <x-filament::input.checkbox
                                    x-bind:checked="grupoCompleto('{{ $dia['clave'] }}')"
                                    x-effect="$el.indeterminate = grupoParcial('{{ $dia['clave'] }}')"
                                    x-on:change="alternarGrupo('{{ $dia['clave'] }}')"
                                />
                                {{ $dia['titulo'] }}
                            </label>
                            <span class="cg-dia-resumen">
                                {{ $dia['cuantas'] === 1 ? '1 factura' : $dia['cuantas'].' facturas' }} · {{ lempiras($dia['total']) }}
                            </span>
                        </header>

                        <div class="cg-vendedores">
                            @foreach ($dia['vendedores'] as $bloque)
                                <div class="cg-vendedor" x-show="hayEn('{{ $bloque['clave'] }}')">
                                    <label class="cg-vendedor-cab">
                                        <x-filament::input.checkbox
                                            x-bind:checked="grupoCompleto('{{ $bloque['clave'] }}')"
                                            x-effect="$el.indeterminate = grupoParcial('{{ $bloque['clave'] }}')"
                                            x-on:change="alternarGrupo('{{ $bloque['clave'] }}')"
                                        />
                                        <span class="cg-vendedor-nombre">
                                            <span @class(['cg-ruta', 'cg-sin-ruta' => $bloque['ruta'] === null])>{{ $bloque['ruta'] ?? 'Sin ruta' }}</span>
                                            <span>{{ $bloque['nombre'] }}</span>
                                        </span>
                                        <span class="cg-vendedor-resumen">
                                            <span x-text="marcadasEn('{{ $bloque['clave'] }}') + ' de ' + {{ (int) $bloque['cuantas'] }}">0 de {{ $bloque['cuantas'] }}</span>
                                            · {{ lempiras($bloque['total']) }}
                                        </span>
                                    </label>

                                    <ul class="cg-filas">
                                        @foreach ($bloque['facturas'] as $f)
                                            <li x-show="ve('{{ $f['id'] }}')">
                                                <label class="cg-fila" x-bind:class="{ 'cg-marcada': esta('{{ $f['id'] }}') }">
                                                    <x-filament::input.checkbox x-bind:checked="esta('{{ $f['id'] }}')" x-on:change="alternar('{{ $f['id'] }}')" />
                                                    <span class="cg-num">{{ $f['numero'] }}</span>
                                                    <span class="cg-cliente">
                                                        <b>{{ $f['cliente'] }}</b>
                                                        @if (filled($f['municipio']))
                                                            <small>{{ $f['municipio'] }}</small>
                                                        @endif
                                                    </span>
                                                    <span class="cg-total">{{ lempiras($f['total']) }}</span>
                                                </label>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach

                <div class="cg-vacio" x-show="visibles().length === 0" x-cloak>
                    <div>
                        <strong>Ninguna factura coincide</strong>
                        Cambie la búsqueda o la ruta.
                    </div>
                    <x-filament::button type="button" color="gray" x-on:click="limpiarFiltros()">
                        Quitar filtros
                    </x-filament::button>
                </div>

                @if ($anteriores['cuantas'] > 0)
                    <div class="cg-atras">
                        <span>
                            Quedaron {{ $anteriores['cuantas'] === 1 ? '1 factura' : $anteriores['cuantas'].' facturas' }} sin cargar de antes del {{ $diaCorto($desde) }}.
                        </span>
                        <x-filament::button type="button" color="gray" size="sm" icon="heroicon-m-calendar-days" x-on:click="$wire.set('{{ $rutaDesde }}', '{{ $anteriores['desde'] }}')">
                            Ver desde el {{ $diaCorto($anteriores['desde']) }}
                        </x-filament::button>
                    </div>
                @endif
            @endif
        </div>
    @endif
</x-dynamic-component>
