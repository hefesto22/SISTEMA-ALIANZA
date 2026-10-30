{{--
    Productos de la preventa como lista tipo carrito (App\Filament\Schemas\Components\LineasPedido)
    y, abajo, la barra con el total y Guardar.

    Todo lo de la lista pasa en el teléfono (Alpine): agregar, unidad o caja,
    − y +, quitar. Al servidor solo van la búsqueda, el total con promociones
    (cuando se deja de tocar) y Guardar. Los estilos van aquí mismo, sin compilar.
--}}
@php
    use App\Domain\Precios\CalculadoraPrecio;
    use App\Filament\Resources\Pedidos\Schemas\PedidoForm;
    use App\Filament\Schemas\Components\LineasPedido;

    $statePath = $getStatePath();
    $lineasIniciales = LineasPedido::normalizar($getState());
    $resumen = PedidoForm::resumenDe($lineasIniciales);
    $cuantas = count($lineasIniciales);
    $edicion = (bool) $getRecord()?->exists;

    $config = [
        'clave'     => $getKey(),
        'productos' => (object) $getProductosDeLineas(),
        'total'     => $resumen['total'],
        'ahorro'    => $resumen['ahorro'],
        'promos'    => $resumen['promociones'],
        'tasa'      => CalculadoraPrecio::tasaIsv(),
        'simbolo'   => (string) config('honduras.moneda.simbolo', 'L.'),
        'maximo'    => LineasPedido::MAXIMO_CANTIDAD,
    ];
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <style>
        /* ── Buscador ── */
        .pv-carrito { display: grid; gap: 1rem; }
        .pv-carrito .fi-input-wrp-prefix { color: var(--gray-400); }
        .pv-resultados { margin-top: 0.5rem; border-radius: 0.75rem; background: #fff; outline: 1px solid rgb(3 7 18 / 0.1); box-shadow: 0 6px 16px rgb(0 0 0 / 0.08); max-height: 22rem; overflow-y: auto; }
        .dark .pv-resultados { background: var(--gray-900); outline-color: rgb(255 255 255 / 0.12); }
        .pv-resultado { display: grid; gap: 0.1rem; width: 100%; padding: 0.7rem 0.875rem; text-align: start; border-bottom: 1px solid var(--gray-100); cursor: pointer; }
        .dark .pv-resultado { border-bottom-color: rgb(255 255 255 / 0.06); }
        .pv-resultado:last-child { border-bottom: 0; }
        .pv-resultado:hover, .pv-resultado:focus-visible { background: var(--gray-50); outline: none; }
        .dark .pv-resultado:hover, .dark .pv-resultado:focus-visible { background: rgb(255 255 255 / 0.05); }
        .pv-resultado-nombre { font-size: 0.875rem; font-weight: 600; line-height: 1.3; }
        .pv-resultado-meta { font-size: 0.75rem; color: var(--gray-500); font-variant-numeric: tabular-nums; }
        .pv-resultado-lleva { font-size: 0.72rem; font-weight: 600; color: var(--primary-600); }
        .dark .pv-resultado-meta { color: var(--gray-400); }
        .dark .pv-resultado-lleva { color: var(--primary-400); }
        .pv-aviso { padding: 0.75rem 0.875rem; font-size: 0.8rem; color: var(--gray-500); }

        /* ── Lista ── */
        .pv-lista-titulo { display: flex; align-items: center; justify-content: space-between; font-size: 0.8rem; font-weight: 600; color: var(--gray-500); }
        .pv-lista { display: grid; border-radius: 0.75rem; outline: 1px solid rgb(3 7 18 / 0.08); overflow: hidden; }
        .dark .pv-lista { outline-color: rgb(255 255 255 / 0.1); }
        .pv-linea { display: grid; gap: 0.4rem; padding: 0.75rem 0.875rem; border-bottom: 1px solid var(--gray-100); transition: background-color 0.6s ease; }
        .dark .pv-linea { border-bottom-color: rgb(255 255 255 / 0.06); }
        .pv-linea:last-child { border-bottom: 0; }
        .pv-linea.pv-resaltada { background: color-mix(in oklab, var(--primary-500) 14%, transparent); transition: none; }
        .pv-linea-arriba { display: flex; align-items: flex-start; gap: 0.5rem; }
        .pv-linea-nombre { flex: 1 1 auto; min-width: 0; font-size: 0.875rem; font-weight: 600; line-height: 1.3; overflow-wrap: anywhere; }
        .pv-linea-subtotal { flex-shrink: 0; font-size: 0.95rem; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .pv-quitar { flex-shrink: 0; display: grid; place-items: center; width: 2rem; height: 2rem; margin: -0.35rem -0.4rem 0 0; border-radius: 0.5rem; color: var(--gray-400); }
        .pv-quitar:hover { color: var(--danger-600); background: color-mix(in oklab, var(--danger-500) 10%, transparent); }
        .pv-quitar svg, .pv-paso svg { width: 1.1rem; height: 1.1rem; }
        .pv-linea-precio { font-size: 0.75rem; color: var(--gray-500); font-variant-numeric: tabular-nums; }
        .dark .pv-linea-precio { color: var(--gray-400); }
        .pv-linea-controles { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 0.75rem; }

        /* Unidad o caja. */
        .pv-seg { display: inline-flex; border-radius: 0.5rem; outline: 1px solid rgb(3 7 18 / 0.12); overflow: hidden; }
        .dark .pv-seg { outline-color: rgb(255 255 255 / 0.15); }
        .pv-seg button { padding: 0.45rem 0.7rem; font-size: 0.78rem; font-weight: 600; color: var(--gray-600); }
        .dark .pv-seg button { color: var(--gray-300); }
        .pv-seg button + button { border-inline-start: 1px solid rgb(3 7 18 / 0.12); }
        .dark .pv-seg button + button { border-inline-start-color: rgb(255 255 255 / 0.15); }
        .pv-seg button.pv-on { background: var(--primary-600); color: #fff; }

        /* − cantidad + */
        .pv-stepper { display: inline-flex; align-items: center; border-radius: 0.5rem; outline: 1px solid rgb(3 7 18 / 0.12); }
        .dark .pv-stepper { outline-color: rgb(255 255 255 / 0.15); }
        .pv-stepper:focus-within { outline: 2px solid var(--primary-600); }
        .pv-paso { display: grid; place-items: center; width: 2.4rem; height: 2.4rem; color: var(--gray-600); }
        .dark .pv-paso { color: var(--gray-300); }
        .pv-stepper input { width: 3.25rem; padding: 0; border: 0; background: transparent; text-align: center; font-size: 1rem; font-weight: 700; font-variant-numeric: tabular-nums; -moz-appearance: textfield; }
        .pv-stepper input:focus { outline: none; box-shadow: none; }
        .pv-stepper input::-webkit-outer-spin-button, .pv-stepper input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

        .pv-vacio { padding: 1.5rem 1rem; text-align: center; font-size: 0.875rem; color: var(--gray-500); border-radius: 0.75rem; outline: 1px dashed rgb(3 7 18 / 0.15); }
        .dark .pv-vacio { outline-color: rgb(255 255 255 / 0.15); }

        /* ── Barra del total ── */
        .pv-barra { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.25rem; border-radius: 0.75rem; background: #fff; box-shadow: 0 1px 2px rgb(0 0 0 / 0.05); outline: 1px solid rgb(3 7 18 / 0.05); }
        .dark .pv-barra { background: var(--gray-900); outline-color: rgb(255 255 255 / 0.1); }
        .pv-barra-total { min-width: 0; display: grid; gap: 0.1rem; }
        .pv-barra-etiqueta { font-size: 0.75rem; font-weight: 500; color: var(--gray-500); }
        .pv-barra-monto { font-size: 1.5rem; font-weight: 800; line-height: 1.15; letter-spacing: -0.01em; font-variant-numeric: tabular-nums; }
        .pv-barra-detalle { font-size: 0.75rem; color: var(--gray-500); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pv-barra-ahorro { color: var(--success-600); font-weight: 600; }
        .dark .pv-barra-etiqueta, .dark .pv-barra-detalle { color: var(--gray-400); }
        .dark .pv-barra-ahorro { color: var(--success-400); }
        .pv-barra .fi-btn { flex-shrink: 0; }

        /* Teléfono: menos relleno para que quepa más. */
        @media (max-width: 639.98px) {
            .fi-main .pv > .fi-section:not(.fi-section-not-contained):not(.fi-aside) > .fi-section-header { padding: 0.875rem 1rem; }
            .fi-main .pv > .fi-section:not(.fi-section-not-contained):not(.fi-divided) > .fi-section-content-ctn > .fi-section-content { padding: 1rem; }
            .pv-linea { padding: 0.7rem 0.75rem; }
        }

        /* Teléfono y tableta: la barra fija abajo; con el teclado abierto se esconde. */
        @media (max-width: 1023.98px) {
            .pv-barra { position: fixed; inset-inline: 0; bottom: 0; z-index: 15; border-radius: 1rem 1rem 0 0; padding: 0.75rem 1rem calc(0.75rem + env(safe-area-inset-bottom)); outline: none; border-top: 1px solid var(--gray-200); box-shadow: 0 -6px 20px rgb(0 0 0 / 0.08); transition: transform 0.2s ease; }
            .dark .pv-barra { border-top-color: rgb(255 255 255 / 0.1); }
            .fi-main:has(.pv-barra) { padding-bottom: 6.5rem; }
            .fi-main:has(:is(input:not([type=radio]):not([type=checkbox]), textarea):focus) .pv-barra { transform: translateY(110%); }
        }
    </style>

    <div
        wire:ignore
        class="pv-carrito"
        x-data="{
            lineas: $wire.$entangle(@js($statePath)),
            productos: @js($config['productos']),
            buscar: '',
            resultados: [],
            buscando: false,
            buscado: false,
            turnoBusqueda: 0,
            turnoResumen: 0,
            esperaBusqueda: null,
            esperaResumen: null,
            resaltada: null,
            total: @js($config['total']),
            ahorro: @js($config['ahorro']),
            promociones: @js($config['promos']),

            init() {
                if (! Array.isArray(this.lineas)) {
                    this.lineas = Object.values(this.lineas ?? {})
                }

                this.$watch('lineas', () => this.recalcular())
            },

            llamar(metodo, argumentos) {
                return Livewire.fireAction(this.$wire.__instance, 'callSchemaComponentMethod', [@js($config['clave']), metodo, argumentos], { async: true })
            },

            redondear(n) {
                return Math.round((n + Number.EPSILON) * 100) / 100
            },

            dinero(n) {
                return @js($config['simbolo']) + ' ' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
            },

            producto(linea) {
                return this.productos[linea.producto_id] ?? null
            },

            {{-- Igual que el servidor: precio sin ISV × cantidad, más 15% si es gravado. --}}
            subtotal(linea) {
                const p = this.producto(linea)

                if (! p) {
                    return 0
                }

                const bruto = this.redondear((linea.unidad === 'caja' ? p.precioCaja : p.precioUnidad) * Number(linea.cantidad || 0))

                return this.redondear(bruto + (p.gravado ? this.redondear(bruto * @js($config['tasa'])) : 0))
            },

            precio(linea) {
                const p = this.producto(linea)

                if (! p) {
                    return ''
                }

                return p.codigo + ' · ' + (linea.unidad === 'caja'
                    ? this.dinero(p.precioCajaIsv) + ' la caja de ' + p.caja
                    : this.dinero(p.precioUnidadIsv) + ' c/u') + (p.gravado ? '' : ' · exento')
            },

            get orden() {
                return this.lineas.map((linea, i) => i).reverse()
            },

            cuantos(productoId) {
                return this.lineas.filter((l) => Number(l.producto_id) === Number(productoId)).reduce((t, l) => t + Number(l.cantidad || 0), 0)
            },

            {{-- El total sale al instante en el teléfono; las promociones las calcula el servidor al dejar de tocar. --}}
            recalcular() {
                this.total = this.redondear(this.lineas.reduce((t, l) => t + this.subtotal(l), 0))
                clearTimeout(this.esperaResumen)

                if (this.lineas.length === 0) {
                    this.ahorro = 0
                    this.promociones = ''

                    return
                }

                this.esperaResumen = setTimeout(() => this.pedirResumen(), 600)
            },

            async pedirResumen() {
                const turno = ++this.turnoResumen

                try {
                    const r = await this.llamar('resumen', { lineas: JSON.parse(JSON.stringify(this.lineas)) })

                    if (turno !== this.turnoResumen || ! r) {
                        return
                    }

                    this.total = r.total
                    this.ahorro = r.ahorro
                    this.promociones = r.promociones
                } catch (e) {}
            },

            alEscribir() {
                clearTimeout(this.esperaBusqueda)
                const texto = this.buscar.trim()

                if (texto.length < 2 && ! /^\d+$/.test(texto)) {
                    this.resultados = []
                    this.buscando = false
                    this.buscado = false

                    return
                }

                this.buscando = true
                this.esperaBusqueda = setTimeout(async () => {
                    const turno = ++this.turnoBusqueda
                    let r = []

                    try {
                        r = await this.llamar('buscarProductos', { buscar: texto })
                    } catch (e) {}

                    if (turno !== this.turnoBusqueda) {
                        return
                    }

                    this.resultados = r ?? []
                    this.buscando = false
                    this.buscado = true
                }, 300)
            },

            {{-- Enter: si el código coincide exacto o hay un solo resultado, lo agrega. --}}
            alEnter() {
                const texto = this.buscar.trim().toUpperCase()
                const exacto = this.resultados.find((p) => String(p.codigo).toUpperCase() === texto)

                if (exacto) {
                    this.agregar(exacto)
                } else if (this.resultados.length === 1) {
                    this.agregar(this.resultados[0])
                }
            },

            cerrarBusqueda() {
                this.buscar = ''
                this.resultados = []
                this.buscado = false
                this.buscando = false
            },

            {{-- Tocar un producto: entra a la lista; si ya estaba por unidad, le suma uno. --}}
            agregar(p) {
                this.productos[p.id] = p
                const i = this.lineas.findIndex((l) => Number(l.producto_id) === Number(p.id) && l.unidad === 'unidad')

                if (i >= 0) {
                    this.lineas[i].cantidad = Math.min(@js($config['maximo']), Number(this.lineas[i].cantidad || 0) + 1)
                    this.resaltar(i)
                } else {
                    this.lineas.push({ producto_id: p.id, unidad: 'unidad', cantidad: 1 })
                    this.resaltar(this.lineas.length - 1)
                }

                this.cerrarBusqueda()
                this.$refs.buscador.blur()
            },

            resaltar(i) {
                this.resaltada = i
                setTimeout(() => {
                    if (this.resaltada === i) {
                        this.resaltada = null
                    }
                }, 900)
            },

            cambiar(i, paso) {
                const actual = parseInt(this.lineas[i].cantidad, 10) || 0
                this.lineas[i].cantidad = Math.max(1, Math.min(@js($config['maximo']), actual + paso))
            },

            fijar(i, valor) {
                const n = parseInt(valor, 10)
                this.lineas[i].cantidad = Number.isFinite(n) && n >= 1 ? Math.min(@js($config['maximo']), n) : 1
            },

            quitar(i) {
                this.lineas.splice(i, 1)
            },
        }"
    >
        {{-- Buscador --}}
        <div>
            <div class="fi-input-wrp">
                <div class="fi-input-wrp-prefix fi-input-wrp-prefix-has-content fi-inline">
                    {{ svg('heroicon-m-magnifying-glass', 'fi-icon fi-size-md') }}
                </div>

                <div class="fi-input-wrp-content-ctn">
                    <input
                        x-ref="buscador"
                        x-model="buscar"
                        x-on:input="alEscribir()"
                        x-on:keydown.enter.prevent="alEnter()"
                        x-on:keydown.escape.prevent="cerrarBusqueda()"
                        type="search"
                        enterkeyhint="search"
                        autocomplete="off"
                        placeholder="Agregar producto: código o nombre…"
                        aria-label="Buscar producto por código o nombre"
                        class="fi-input"
                    />
                </div>

                <div class="fi-input-wrp-suffix fi-inline" x-show="buscando" x-cloak>
                    {{ svg('heroicon-m-arrow-path', 'fi-icon fi-size-md animate-spin') }}
                </div>
            </div>

            <div class="pv-resultados" x-show="buscado || (buscar.trim().length === 1 && ! /^\d+$/.test(buscar.trim()))" x-cloak>
                <template x-for="p in resultados" x-bind:key="p.id">
                    <button type="button" class="pv-resultado" x-on:click="agregar(p)">
                        <span class="pv-resultado-nombre" x-text="p.descripcion"></span>
                        <span class="pv-resultado-meta" x-text="p.codigo + ' · ' + dinero(p.precioUnidadIsv) + ' c/u · caja de ' + p.caja + ' ' + dinero(p.precioCajaIsv)"></span>
                        <span class="pv-resultado-lleva" x-show="cuantos(p.id) > 0" x-text="'Ya lleva ' + cuantos(p.id) + ' · tocar suma uno'"></span>
                    </button>
                </template>

                <div class="pv-aviso" x-show="buscado && resultados.length === 0">No hay productos activos con ese código o nombre.</div>
                <div class="pv-aviso" x-show="! buscado && buscar.trim().length === 1">Escriba al menos 2 letras (o el código).</div>
            </div>
        </div>

        {{-- Lista: lo último que se agregó queda arriba --}}
        <div class="pv-vacio" x-show="lineas.length === 0" @if ($cuantas > 0) style="display: none" @endif>
            Todavía no hay productos. Búsquelos arriba y tóquelos para agregarlos.
        </div>

        <div class="pv-lista-titulo" x-show="lineas.length > 0" @if ($cuantas === 0) style="display: none" @endif>
            <span x-text="lineas.length === 1 ? '1 producto en el pedido' : lineas.length + ' productos en el pedido'">{{ $cuantas === 1 ? '1 producto en el pedido' : "{$cuantas} productos en el pedido" }}</span>
        </div>

        <div class="pv-lista" x-show="lineas.length > 0" @if ($cuantas === 0) style="display: none" @endif>
            <template x-for="i in orden" x-bind:key="i">
                <div class="pv-linea" x-bind:class="{ 'pv-resaltada': resaltada === i }">
                    <div class="pv-linea-arriba">
                        <div class="pv-linea-nombre" x-text="producto(lineas[i])?.descripcion ?? ('Producto ' + lineas[i].producto_id)"></div>
                        <div class="pv-linea-subtotal" x-text="dinero(subtotal(lineas[i]))"></div>
                        <button type="button" class="pv-quitar" x-on:click="quitar(i)" aria-label="Quitar del pedido" title="Quitar">
                            {{ svg('heroicon-m-x-mark') }}
                        </button>
                    </div>

                    <div class="pv-linea-precio" x-text="precio(lineas[i])"></div>

                    <div class="pv-linea-controles">
                        <div class="pv-seg" role="group" aria-label="Vende en">
                            <button type="button" x-bind:class="{ 'pv-on': lineas[i].unidad !== 'caja' }" x-on:click="lineas[i].unidad = 'unidad'">Unidad</button>
                            <button type="button" x-bind:class="{ 'pv-on': lineas[i].unidad === 'caja' }" x-on:click="lineas[i].unidad = 'caja'" x-text="'Caja de ' + (producto(lineas[i])?.caja ?? '')">Caja</button>
                        </div>

                        <div class="pv-stepper">
                            <button type="button" class="pv-paso" x-on:click="cambiar(i, -1)" aria-label="Uno menos">
                                {{ svg('heroicon-m-minus') }}
                            </button>
                            <input
                                type="number"
                                inputmode="numeric"
                                min="1"
                                aria-label="Cantidad"
                                x-bind:value="lineas[i].cantidad"
                                x-on:change="fijar(i, $event.target.value)"
                                x-on:focus="$event.target.select()"
                                x-on:keydown.enter.prevent="$event.target.blur()"
                            />
                            <button type="button" class="pv-paso" x-on:click="cambiar(i, 1)" aria-label="Uno más">
                                {{ svg('heroicon-m-plus') }}
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <div x-show="promociones" x-html="promociones" @if ($resumen['promociones'] === '') style="display: none" @endif>{!! $resumen['promociones'] !!}</div>

        {{-- Total y Guardar: en el teléfono queda fija abajo --}}
        <div class="pv-barra">
            <div class="pv-barra-total">
                <span class="pv-barra-etiqueta">Total estimado</span>
                <span class="pv-barra-monto" x-text="dinero(total)">{{ lempiras($resumen['total']) }}</span>
                <span class="pv-barra-detalle">
                    <span x-text="lineas.length === 0 ? 'Agregue productos' : (lineas.length === 1 ? '1 producto' : lineas.length + ' productos')">{{ $cuantas === 0 ? 'Agregue productos' : ($cuantas === 1 ? '1 producto' : "{$cuantas} productos") }}</span><span x-show="ahorro > 0" @if ($resumen['ahorro'] <= 0) style="display: none" @endif> · <span class="pv-barra-ahorro" x-text="'ahorra ' + dinero(ahorro)">ahorra {{ lempiras($resumen['ahorro']) }}</span></span>
                </span>
            </div>

            <x-filament::button
                type="submit"
                size="lg"
                icon="heroicon-m-check"
                wire:target="{{ $edicion ? 'save' : 'create' }}"
            >
                {{ $edicion ? 'Guardar cambios' : 'Guardar pedido' }}
            </x-filament::button>
        </div>
    </div>
</x-dynamic-component>
