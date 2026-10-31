{{--
    Productos de una compra como lista tipo carrito (App\Filament\Schemas\Components\LineasCompra)
    y, abajo, la barra con el total y "Registrar compra".

    Arriba el buscador con los productos del proveedor escogido (al tocarlo
    sin escribir salen los primeros). Al tocar un producto entra con el
    costo de hoy ya puesto, en caja si trae más de 1. Cada fila: caja o
    unidad, cantidad con − y +, costo como viene en la factura (con ISV si
    es gravado) y el total; si el costo se aleja mucho del de hoy, avisa.

    Si se cambia el proveedor, se quitan los productos que eran de otro.
    Todo pasa en el teléfono; al servidor solo van la búsqueda y Registrar.
    Los estilos van aquí mismo, sin compilar.
--}}
@php
    use App\Domain\Precios\CalculadoraPrecio;
    use App\Filament\Schemas\Components\LineasCompra;

    $statePath = $getStatePath();
    $config = [
        'clave'     => $getKey(),
        'productos' => (object) $getProductosDeLineas(),
        'tasa'      => CalculadoraPrecio::tasaIsv(),
        'simbolo'   => (string) config('honduras.moneda.simbolo', 'L.'),
        'maximo'    => LineasCompra::MAXIMO_CANTIDAD,
        'proveedor' => $field->resolveRelativeStatePath('proveedor_id'),
    ];
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @once
        <style>
            .lc { --lc-tenue: var(--gray-500); --lc-linea: var(--gray-100); --lc-borde: rgb(3 7 18 / 0.08); display: grid; gap: 1rem; font-size: 0.875rem; }
            .dark .lc { --lc-tenue: var(--gray-400); --lc-linea: rgb(255 255 255 / 0.06); --lc-borde: rgb(255 255 255 / 0.1); }

            /* ── Buscador ── */
            .lc-buscador { position: relative; }
            .lc-buscador .fi-input-wrp-prefix { color: var(--gray-400); }
            .lc-resultados { position: absolute; inset-inline: 0; top: calc(100% + 0.4rem); z-index: 20; border-radius: 0.75rem; background: #fff; outline: 1px solid rgb(3 7 18 / 0.1); box-shadow: 0 10px 24px rgb(0 0 0 / 0.16); max-height: min(22rem, 55vh); overflow-y: auto; overscroll-behavior: contain; }
            .dark .lc-resultados { background: var(--gray-800); outline-color: rgb(255 255 255 / 0.12); }
            .lc-resultado { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; column-gap: 0.75rem; width: 100%; padding: 0.65rem 0.875rem; text-align: start; border-bottom: 1px solid var(--lc-linea); cursor: pointer; }
            .lc-resultado:last-child { border-bottom: 0; }
            .lc-resultado:hover, .lc-resultado:focus-visible { background: var(--gray-50); outline: none; }
            .dark .lc-resultado:hover, .dark .lc-resultado:focus-visible { background: rgb(255 255 255 / 0.05); }
            .lc-resultado-nombre { display: block; font-size: 0.85rem; font-weight: 600; line-height: 1.3; }
            .lc-resultado-meta { display: block; font-size: 0.72rem; color: var(--lc-tenue); font-variant-numeric: tabular-nums; }
            .lc-resultado-costo { display: grid; justify-items: end; font-size: 0.72rem; color: var(--lc-tenue); line-height: 1.25; white-space: nowrap; }
            .lc-resultado-costo strong { font-size: 0.85rem; font-weight: 700; color: var(--gray-700); font-variant-numeric: tabular-nums; }
            .dark .lc-resultado-costo strong { color: var(--gray-200); }
            .lc-nota { padding: 0.75rem 0.875rem; font-size: 0.8rem; color: var(--lc-tenue); }
            .lc-aviso-proveedor { padding: 0.6rem 0.75rem; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 600; color: var(--warning-700); background: color-mix(in oklab, var(--warning-500) 12%, transparent); }
            .dark .lc-aviso-proveedor { color: var(--warning-300); }

            /* ── Lista ── */
            .lc-titulo { font-size: 0.8rem; font-weight: 600; color: var(--lc-tenue); }
            .lc-lista { display: grid; border-radius: 0.75rem; outline: 1px solid var(--lc-borde); overflow: hidden; }
            .lc-linea { display: grid; gap: 0.6rem; padding: 0.8rem 0.875rem; border-bottom: 1px solid var(--lc-linea); transition: background-color 0.6s ease; }
            .lc-linea:last-child { border-bottom: 0; }
            .lc-linea.lc-resaltada { background: color-mix(in oklab, var(--primary-500) 12%, transparent); transition: none; }
            .lc-arriba { display: flex; align-items: flex-start; gap: 0.5rem; }
            .lc-producto { flex: 1 1 auto; min-width: 0; display: grid; gap: 0.15rem; }
            .lc-nombre { font-size: 0.875rem; font-weight: 600; line-height: 1.3; overflow-wrap: anywhere; }
            .lc-meta { font-size: 0.75rem; color: var(--lc-tenue); font-variant-numeric: tabular-nums; }
            .lc-quitar { flex-shrink: 0; display: grid; place-items: center; width: 2rem; height: 2rem; margin: -0.35rem -0.4rem 0 0; border-radius: 0.5rem; color: var(--gray-400); }
            .lc-quitar:hover { color: var(--danger-600); background: color-mix(in oklab, var(--danger-500) 10%, transparent); }
            .lc-quitar svg, .lc-paso svg { width: 1.1rem; height: 1.1rem; }

            .lc-controles { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 0.6rem 1rem; }
            .lc-campo { display: grid; gap: 0.25rem; }
            .lc-etiqueta { font-size: 0.7rem; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase; color: var(--lc-tenue); }

            /* Caja | Unidad */
            .lc-segmento { display: inline-flex; padding: 0.2rem; border-radius: 0.5rem; background: var(--gray-100); }
            .dark .lc-segmento { background: rgb(255 255 255 / 0.06); }
            .lc-segmento button { padding: 0.45rem 0.75rem; border-radius: 0.375rem; font-size: 0.8rem; font-weight: 600; color: var(--gray-600); }
            .dark .lc-segmento button { color: var(--gray-300); }
            .lc-segmento button.lc-activo { color: #fff; background: var(--primary-600); }

            /* − cantidad + */
            .lc-stepper { display: inline-flex; align-items: center; border-radius: 0.5rem; outline: 1px solid rgb(3 7 18 / 0.12); }
            .dark .lc-stepper { outline-color: rgb(255 255 255 / 0.15); }
            .lc-stepper:focus-within { outline: 2px solid var(--primary-600); }
            .lc-paso { display: grid; place-items: center; width: 2.4rem; height: 2.4rem; color: var(--gray-600); }
            .dark .lc-paso { color: var(--gray-300); }
            .lc-paso:disabled { opacity: 0.3; cursor: not-allowed; }
            .lc-stepper input { width: 3.5rem; padding: 0; border: 0; background: transparent; text-align: center; font-size: 1rem; font-weight: 700; font-variant-numeric: tabular-nums; -moz-appearance: textfield; }
            .lc-stepper input:focus, .lc-costo input:focus { outline: none; box-shadow: none; }
            .lc-stepper input::-webkit-outer-spin-button, .lc-stepper input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

            /* L. costo */
            .lc-costo { display: inline-flex; align-items: center; height: 2.4rem; border-radius: 0.5rem; outline: 1px solid rgb(3 7 18 / 0.12); }
            .dark .lc-costo { outline-color: rgb(255 255 255 / 0.15); }
            .lc-costo:focus-within { outline: 2px solid var(--primary-600); }
            .lc-costo.lc-falta { outline: 2px solid var(--danger-500); }
            .lc-costo span { padding-inline-start: 0.6rem; font-size: 0.85rem; color: var(--lc-tenue); }
            .lc-costo input { width: 7rem; padding: 0 0.6rem 0 0.35rem; border: 0; background: transparent; font-size: 1rem; font-weight: 700; font-variant-numeric: tabular-nums; }
            .lc-hoy { font-size: 0.72rem; color: var(--lc-tenue); font-variant-numeric: tabular-nums; }
            .lc-total { margin-inline-start: auto; justify-items: end; }
            .lc-total strong { font-size: 1rem; font-weight: 800; font-variant-numeric: tabular-nums; white-space: nowrap; line-height: 2.4rem; }
            .lc-aviso-costo { font-size: 0.78rem; font-weight: 600; color: var(--warning-600); }
            .dark .lc-aviso-costo { color: var(--warning-400); }

            .lc-vacio { padding: 1.5rem 1rem; text-align: center; font-size: 0.875rem; color: var(--lc-tenue); border-radius: 0.75rem; outline: 1px dashed rgb(3 7 18 / 0.15); }
            .dark .lc-vacio { outline-color: rgb(255 255 255 / 0.15); }

            /* ── Barra del total ── */
            .lc-barra { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.25rem; border-radius: 0.75rem; background: #fff; box-shadow: 0 1px 2px rgb(0 0 0 / 0.05); outline: 1px solid rgb(3 7 18 / 0.05); }
            .dark .lc-barra { background: var(--gray-900); outline-color: rgb(255 255 255 / 0.1); }
            .lc-barra-total { min-width: 0; display: grid; gap: 0.1rem; }
            .lc-barra-etiqueta { font-size: 0.75rem; font-weight: 500; color: var(--lc-tenue); }
            .lc-barra-monto { font-size: 1.5rem; font-weight: 800; line-height: 1.15; letter-spacing: -0.01em; font-variant-numeric: tabular-nums; }
            .lc-barra-detalle { font-size: 0.75rem; color: var(--lc-tenue); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .lc-barra .fi-btn { flex-shrink: 0; }

            /* Teléfono: menos relleno en las secciones. */
            @media (max-width: 639.98px) {
                .fi-main .pv > .fi-section:not(.fi-section-not-contained):not(.fi-aside) > .fi-section-header { padding: 0.875rem 1rem; }
                .fi-main .pv > .fi-section:not(.fi-section-not-contained):not(.fi-divided) > .fi-section-content-ctn > .fi-section-content { padding: 1rem; }
                .lc-linea { padding: 0.75rem; }
                .lc-costo input { width: 6rem; }
            }

            /* Teléfono y tableta: la barra fija abajo; con el teclado abierto se esconde. */
            @media (max-width: 1023.98px) {
                .lc-barra { position: fixed; inset-inline: 0; bottom: 0; z-index: 15; border-radius: 1rem 1rem 0 0; padding: 0.75rem 1rem calc(0.75rem + env(safe-area-inset-bottom)); outline: none; border-top: 1px solid var(--gray-200); box-shadow: 0 -6px 20px rgb(0 0 0 / 0.08); transition: transform 0.2s ease; }
                .dark .lc-barra { border-top-color: rgb(255 255 255 / 0.1); }
                .fi-main:has(.lc-barra) { padding-bottom: 6.5rem; }
                .fi-main:has(:is(input:not([type=radio]):not([type=checkbox]), textarea):focus) .lc-barra { transform: translateY(110%); }
            }
        </style>
    @endonce

    <div
        wire:ignore
        class="lc"
        x-data="{
            lineas: $wire.$entangle(@js($statePath)),
            productos: @js($config['productos']),
            proveedor: $wire.get(@js($config['proveedor'])),
            buscar: '',
            resultados: [],
            abierto: false,
            buscando: false,
            buscado: false,
            turno: 0,
            espera: null,
            resaltada: null,
            avisoProveedor: '',

            init() {
                if (! Array.isArray(this.lineas)) {
                    this.lineas = Object.values(this.lineas ?? {})
                }

                this.$wire.$watch(@js($config['proveedor']), (id) => this.cambioProveedor(id))
            },

            llamar(metodo, argumentos) {
                return Livewire.fireAction(this.$wire.__instance, 'callSchemaComponentMethod', [@js($config['clave']), metodo, argumentos], { async: true })
            },

            n(valor) {
                const numero = parseInt(valor, 10)

                return Number.isFinite(numero) ? numero : 0
            },

            decimal(valor) {
                const numero = parseFloat(String(valor ?? '').replace(/[^0-9.,-]/g, '').replace(',', '.'))

                return Number.isFinite(numero) ? numero : 0
            },

            redondear(numero) {
                return Math.round((numero + Number.EPSILON) * 100) / 100
            },

            dinero(numero) {
                return @js($config['simbolo']) + ' ' + Number(numero || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
            },

            {{-- ── Proveedor ── --}}
            cambioProveedor(id) {
                this.proveedor = id
                this.cerrarBusqueda()

                const antes = this.lineas.length
                this.lineas = this.lineas.filter((linea) => Number(this.productos[linea.producto_id]?.proveedor_id) === Number(id))

                const quitados = antes - this.lineas.length
                this.avisoProveedor = quitados === 0 ? '' : (quitados === 1 ? 'Se quitó 1 producto: era' : 'Se quitaron ' + quitados + ' productos: eran') + ' de otro proveedor.'
            },

            {{-- ── Buscador ── --}}
            alEnfocar() {
                if (this.proveedor && this.buscar.trim() === '' && ! this.buscado) {
                    this.pedir('')
                }

                this.abierto = true
            },

            alEscribir() {
                clearTimeout(this.espera)
                this.abierto = true
                this.buscando = true
                this.espera = setTimeout(() => this.pedir(this.buscar.trim()), 300)
            },

            async pedir(texto) {
                const turno = ++this.turno
                let encontrados = []

                this.buscando = true

                try {
                    encontrados = await this.llamar('buscarProductos', { buscar: texto })
                } catch (e) {}

                if (turno !== this.turno) {
                    return
                }

                this.resultados = encontrados ?? []
                this.buscando = false
                this.buscado = true
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
                this.abierto = false
                this.buscado = false
                this.buscando = false
            },

            {{-- ── Líneas ── --}}
            hoy(linea) {
                const p = this.productos[linea.producto_id]

                return Number(linea.unidad === 'caja' ? p?.costoCaja : p?.costoUnidad) || 0
            },

            {{-- Tocar un producto: entra con el costo de hoy (en caja si trae más de 1). Si ya estaba en esa unidad, le suma 1. --}}
            agregar(p) {
                this.productos[p.id] = p

                const unidad = Number(p.caja) > 1 ? 'caja' : 'unidad'
                let i = this.lineas.findIndex((linea) => Number(linea.producto_id) === Number(p.id) && linea.unidad === unidad)

                if (i >= 0) {
                    this.lineas[i].cantidad = Math.min(this.n(this.lineas[i].cantidad) + 1, @js($config['maximo']))
                } else {
                    const linea = { producto_id: Number(p.id), unidad, cantidad: 1, costo: '' }
                    const costo = this.hoy(linea)
                    linea.costo = costo > 0 ? costo : ''
                    this.lineas.push(linea)
                    i = this.lineas.length - 1
                }

                this.avisoProveedor = ''
                this.cerrarBusqueda()
                this.$refs.buscador.blur()
                this.resaltar(i)
            },

            resaltar(i) {
                this.resaltada = i
                setTimeout(() => {
                    if (this.resaltada === i) {
                        this.resaltada = null
                    }
                }, 900)
            },

            quitar(i) {
                this.lineas.splice(i, 1)
            },

            {{-- Al pasar de caja a unidad (o al revés), si el costo era el de hoy se cambia al de hoy de la otra. --}}
            cambiarUnidad(linea, unidad) {
                if (linea.unidad === unidad) {
                    return
                }

                const antes = this.hoy(linea)
                const igual = linea.costo === '' || Math.abs(this.decimal(linea.costo) - antes) < 0.005
                linea.unidad = unidad

                if (igual) {
                    const ahora = this.hoy(linea)
                    linea.costo = ahora > 0 ? ahora : ''
                }
            },

            fijarCantidad(linea, valor) {
                linea.cantidad = Math.min(Math.max(this.n(valor), 1), @js($config['maximo']))
            },

            mover(linea, paso) {
                this.fijarCantidad(linea, this.n(linea.cantidad) + paso)
            },

            fijarCosto(linea, valor) {
                const costo = Math.round(this.decimal(valor) * 10000) / 10000
                linea.costo = costo > 0 ? costo : ''
            },

            {{-- 560 se ve 560.00; si trae más decimales (0.3456), se dejan. --}}
            mostrarCosto(costo) {
                if (costo === '' || costo === null || costo === undefined) {
                    return ''
                }

                const numero = Number(costo)

                return Math.abs(Math.round(numero * 100) - numero * 100) < 0.000001 ? numero.toFixed(2) : String(numero)
            },

            totalLinea(linea) {
                return this.redondear(this.n(linea.cantidad) * this.decimal(linea.costo))
            },

            total() {
                return this.redondear(this.lineas.reduce((suma, linea) => suma + this.totalLinea(linea), 0))
            },

            {{-- El ISV que trae la factura: sale del total de lo gravado. --}}
            isv() {
                const tasa = @js($config['tasa'])

                return this.redondear(this.lineas
                    .filter((linea) => this.productos[linea.producto_id]?.gravado)
                    .reduce((suma, linea) => suma + this.totalLinea(linea) - this.redondear(this.totalLinea(linea) / (1 + tasa)), 0))
            },

            {{-- Error de dedo: si el costo se aleja 25 % o más del de hoy. --}}
            avisoCosto(linea) {
                const hoy = this.hoy(linea)
                const costo = this.decimal(linea.costo)

                if (hoy <= 0 || costo <= 0) {
                    return ''
                }

                const diferencia = (costo - hoy) / hoy

                return Math.abs(diferencia) >= 0.25
                    ? 'Ojo: ' + Math.round(Math.abs(diferencia) * 100) + '% ' + (diferencia > 0 ? 'más caro' : 'más barato') + ' que hoy (' + this.dinero(hoy) + '). ¿Está bien escrito?'
                    : ''
            },

            meta(linea) {
                const p = this.productos[linea.producto_id]

                if (! p) {
                    return ''
                }

                return p.codigo + (Number(p.caja) > 1 ? ' · caja de ' + p.caja : '') + (p.gravado ? '' : ' · exento')
            },
        }"
    >
        {{-- Buscador --}}
        <div class="lc-buscador" x-on:click.outside="abierto = false">
            <div class="fi-input-wrp" x-bind:class="{ 'fi-disabled': ! proveedor }">
                <div class="fi-input-wrp-prefix fi-input-wrp-prefix-has-content fi-inline">
                    {{ svg('heroicon-m-magnifying-glass', 'fi-icon fi-size-md') }}
                </div>

                <div class="fi-input-wrp-content-ctn">
                    <input
                        x-ref="buscador"
                        x-model="buscar"
                        x-on:focus="alEnfocar()"
                        x-on:input="alEscribir()"
                        x-on:keydown.enter.prevent="alEnter()"
                        x-on:keydown.escape.prevent="cerrarBusqueda()"
                        x-bind:disabled="! proveedor"
                        x-bind:placeholder="proveedor ? 'Código o nombre del producto' : 'Escoja primero el proveedor'"
                        type="search"
                        enterkeyhint="search"
                        autocomplete="off"
                        aria-label="Buscar producto por código o nombre"
                        class="fi-input"
                    />
                </div>

                <div class="fi-input-wrp-suffix fi-inline" x-show="buscando" x-cloak>
                    {{ svg('heroicon-m-arrow-path', 'fi-icon fi-size-md animate-spin') }}
                </div>
            </div>

            <div class="lc-resultados" x-show="abierto && buscado" x-cloak>
                <template x-for="p in resultados" x-bind:key="p.id">
                    <button type="button" class="lc-resultado" x-on:click="agregar(p)">
                        <span>
                            <span class="lc-resultado-nombre" x-text="p.descripcion"></span>
                            <span class="lc-resultado-meta" x-text="p.codigo + (Number(p.caja) > 1 ? ' · caja de ' + p.caja : '') + (p.gravado ? '' : ' · exento')"></span>
                        </span>
                        <span class="lc-resultado-costo">
                            <span>costo hoy</span>
                            <strong x-text="Number(p.costoCaja || p.costoUnidad) > 0 ? dinero(Number(p.caja) > 1 ? p.costoCaja : p.costoUnidad) : '—'"></strong>
                            <span x-text="Number(p.caja) > 1 ? 'la caja' : 'la unidad'"></span>
                        </span>
                    </button>
                </template>

                <div class="lc-nota" x-show="resultados.length === 0">No hay productos activos de este proveedor con ese código o nombre.</div>
            </div>
        </div>

        <div class="lc-aviso-proveedor" x-show="avisoProveedor !== ''" x-cloak x-text="avisoProveedor"></div>

        {{-- Lista --}}
        <div class="lc-titulo" x-show="lineas.length > 0" x-text="lineas.length === 1 ? '1 producto en la compra' : lineas.length + ' productos en la compra'"></div>

        <div class="lc-lista" x-show="lineas.length > 0">
            <template x-for="(linea, i) in lineas" x-bind:key="linea.producto_id + '-' + linea.unidad + '-' + i">
                <div class="lc-linea" x-bind:class="{ 'lc-resaltada': resaltada === i }" x-effect="if (resaltada === i) $nextTick(() => $el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }))">
                    <div class="lc-arriba">
                        <div class="lc-producto">
                            <div class="lc-nombre" x-text="productos[linea.producto_id]?.descripcion ?? ('Producto ' + linea.producto_id)"></div>
                            <div class="lc-meta" x-text="meta(linea)"></div>
                        </div>
                        <button type="button" class="lc-quitar" x-on:click="quitar(i)" aria-label="Quitar de la compra" title="Quitar">
                            {{ svg('heroicon-m-x-mark') }}
                        </button>
                    </div>

                    <div class="lc-controles">
                        <div class="lc-campo" x-show="Number(productos[linea.producto_id]?.caja) > 1 || linea.unidad === 'caja'">
                            <span class="lc-etiqueta">Viene en</span>
                            <div class="lc-segmento">
                                <button type="button" x-bind:class="{ 'lc-activo': linea.unidad === 'caja' }" x-on:click="cambiarUnidad(linea, 'caja')">Caja</button>
                                <button type="button" x-bind:class="{ 'lc-activo': linea.unidad === 'unidad' }" x-on:click="cambiarUnidad(linea, 'unidad')">Unidad</button>
                            </div>
                        </div>

                        <div class="lc-campo">
                            <span class="lc-etiqueta" x-text="linea.unidad === 'caja' ? 'Cajas' : 'Unidades'"></span>
                            <div class="lc-stepper">
                                <button type="button" class="lc-paso" x-on:click="mover(linea, -1)" x-bind:disabled="n(linea.cantidad) <= 1" aria-label="Uno menos">{{ svg('heroicon-m-minus') }}</button>
                                <input
                                    type="number"
                                    inputmode="numeric"
                                    min="1"
                                    aria-label="Cantidad"
                                    x-bind:value="linea.cantidad"
                                    x-on:focus="$event.target.select()"
                                    x-on:change="fijarCantidad(linea, $event.target.value); $event.target.value = linea.cantidad"
                                    x-on:keydown.enter.prevent="$event.target.blur()"
                                >
                                <button type="button" class="lc-paso" x-on:click="mover(linea, 1)" aria-label="Uno más">{{ svg('heroicon-m-plus') }}</button>
                            </div>
                        </div>

                        <div class="lc-campo">
                            <span class="lc-etiqueta" x-text="'Costo ' + (linea.unidad === 'caja' ? 'caja' : 'unidad') + (productos[linea.producto_id]?.gravado ? ' con ISV' : '')"></span>
                            <div class="lc-costo" x-bind:class="{ 'lc-falta': linea.costo === '' }">
                                <span>{{ $config['simbolo'] }}</span>
                                <input
                                    type="text"
                                    inputmode="decimal"
                                    placeholder="0.00"
                                    aria-label="Costo como viene en la factura"
                                    x-bind:value="mostrarCosto(linea.costo)"
                                    x-on:focus="$event.target.select()"
                                    x-on:change="fijarCosto(linea, $event.target.value); $event.target.value = mostrarCosto(linea.costo)"
                                    x-on:keydown.enter.prevent="$event.target.blur()"
                                >
                            </div>
                            <span class="lc-hoy" x-text="hoy(linea) > 0 ? 'Hoy ' + dinero(hoy(linea)) : 'Sin costo anterior'"></span>
                        </div>

                        <div class="lc-campo lc-total">
                            <span class="lc-etiqueta">Total</span>
                            <strong x-text="dinero(totalLinea(linea))"></strong>
                        </div>
                    </div>

                    <div class="lc-aviso-costo" x-show="avisoCosto(linea) !== ''" x-text="avisoCosto(linea)"></div>
                </div>
            </template>
        </div>

        <div class="lc-vacio" x-show="lineas.length === 0" x-text="proveedor ? 'Todavía no hay productos. Búsquelos arriba y tóquelos para agregarlos.' : 'Escoja el proveedor y después busque sus productos arriba.'"></div>

        {{-- Total y Registrar: en el teléfono queda fija abajo --}}
        <div class="lc-barra">
            <div class="lc-barra-total">
                <span class="lc-barra-etiqueta">Total de la compra</span>
                <span class="lc-barra-monto" x-text="dinero(total())">{{ lempiras(0) }}</span>
                <span class="lc-barra-detalle" x-text="(lineas.length === 1 ? '1 producto' : lineas.length + ' productos') + (isv() > 0 ? ' · ISV ' + dinero(isv()) : '')"></span>
            </div>

            <x-filament::button type="submit" size="lg" icon="heroicon-m-check" wire:target="create">
                Registrar compra
            </x-filament::button>
        </div>
    </div>
</x-dynamic-component>
