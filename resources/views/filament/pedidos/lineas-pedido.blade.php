{{--
    Productos de la preventa como lista tipo carrito (App\Filament\Schemas\Components\LineasPedido)
    y, abajo, la barra con el total y Guardar.

    Todo lo de la lista pasa en el teléfono (Alpine): agregar, unidad o caja,
    − y +, quitar. Al servidor solo van la búsqueda, el total con promociones
    (cuando se deja de tocar), el último pedido del cliente y Guardar.

    Cada producto se ve en una fila corta (cantidad, nombre, subtotal); al
    tocarla se abre para cambiarla. El que se acaba de agregar queda abierto.
    El buscador se queda arriba al bajar por la lista. Los estilos van aquí
    mismo, sin compilar.
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
        'edicion'   => $edicion,
    ];
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <style>
        .pv-carrito > * + * { margin-top: 1rem; }

        /* ── Buscador: se queda arriba al bajar por la lista ── */
        .pv-buscador { position: sticky; top: var(--topbar-height, 4rem); z-index: 6; margin: -0.5rem -0.5rem 0; padding: 0.5rem; border-radius: 0 0 0.875rem 0.875rem; background: #fff; }
        .dark .pv-buscador { background: var(--gray-900); }
        .pv-carrito .fi-input-wrp-prefix { color: var(--gray-400); }
        .pv-resultados { margin-top: 0.5rem; border-radius: 0.75rem; background: #fff; outline: 1px solid rgb(3 7 18 / 0.1); box-shadow: 0 8px 20px rgb(0 0 0 / 0.12); max-height: min(22rem, 55vh); overflow-y: auto; overscroll-behavior: contain; }
        .dark .pv-resultados { background: var(--gray-800); outline-color: rgb(255 255 255 / 0.12); }
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

        /* ── Último pedido del cliente ── */
        .pv-ultimo { display: grid; gap: 0.5rem; padding: 0.875rem 1rem; border-radius: 0.75rem; background: color-mix(in oklab, var(--primary-500) 8%, transparent); outline: 1px solid color-mix(in oklab, var(--primary-500) 35%, transparent); }
        .pv-ultimo-titulo { font-size: 0.875rem; font-weight: 600; }
        .pv-ultimo-detalle { font-size: 0.78rem; color: var(--gray-500); }
        .dark .pv-ultimo-detalle { color: var(--gray-400); }
        .pv-ultimo button { justify-self: start; padding: 0.5rem 0.875rem; border-radius: 0.5rem; font-size: 0.85rem; font-weight: 600; color: #fff; background: var(--primary-600); }

        /* ── Lista ── */
        .pv-lista-titulo { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; font-size: 0.8rem; font-weight: 600; color: var(--gray-500); }
        .pv-lista-titulo button { font-weight: 600; color: var(--primary-600); }
        .dark .pv-lista-titulo button { color: var(--primary-400); }
        .pv-lista { display: grid; border-radius: 0.75rem; outline: 1px solid rgb(3 7 18 / 0.08); overflow: hidden; }
        .dark .pv-lista { outline-color: rgb(255 255 255 / 0.1); }
        .pv-linea { border-bottom: 1px solid var(--gray-100); transition: background-color 0.6s ease; }
        .dark .pv-linea { border-bottom-color: rgb(255 255 255 / 0.06); }
        .pv-linea:last-child { border-bottom: 0; }
        .pv-linea.pv-resaltada { background: color-mix(in oklab, var(--primary-500) 14%, transparent); transition: none; }
        .pv-linea.pv-abierta { background: color-mix(in oklab, var(--primary-500) 5%, transparent); }

        /* Fila corta: cantidad, nombre y subtotal. Al tocarla se abre. */
        .pv-corta { display: flex; align-items: center; gap: 0.625rem; width: 100%; padding: 0.6rem 0.875rem; text-align: start; cursor: pointer; }
        /* Cantidad bien visible: "3 UN" (unidades) o "2 CJ" (cajas, en color lleno para que no se confundan). */
        .pv-cant { flex-shrink: 0; min-width: 3.6rem; padding: 0.3rem 0.45rem; border-radius: 0.5rem; text-align: center; font-size: 0.85rem; font-weight: 800; letter-spacing: 0.02em; line-height: 1.2; white-space: nowrap; font-variant-numeric: tabular-nums; color: var(--primary-700); background: color-mix(in oklab, var(--primary-500) 14%, transparent); outline: 1px solid color-mix(in oklab, var(--primary-500) 45%, transparent); }
        .dark .pv-cant { color: var(--primary-200); }
        .pv-cant.pv-cant-caja, .dark .pv-cant.pv-cant-caja { color: #fff; background: var(--primary-600); outline-color: var(--primary-600); }
        .pv-corta-nombre { flex: 1 1 auto; min-width: 0; font-size: 0.85rem; font-weight: 600; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .pv-linea-subtotal { flex-shrink: 0; font-size: 0.9rem; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }

        /* Fila abierta. */
        .pv-detalle { display: grid; gap: 0.45rem; padding: 0.7rem 0.875rem 0.8rem; }
        .pv-linea-arriba { display: flex; align-items: flex-start; gap: 0.5rem; cursor: pointer; }
        .pv-linea-nombre { flex: 1 1 auto; min-width: 0; font-size: 0.875rem; font-weight: 600; line-height: 1.3; overflow-wrap: anywhere; }
        .pv-quitar { flex-shrink: 0; display: grid; place-items: center; width: 2rem; height: 2rem; margin: -0.35rem -0.4rem 0 0; border-radius: 0.5rem; color: var(--gray-400); }
        .pv-quitar:hover { color: var(--danger-600); background: color-mix(in oklab, var(--danger-500) 10%, transparent); }
        .pv-quitar svg, .pv-paso svg { width: 1.1rem; height: 1.1rem; }
        .pv-linea-precio { font-size: 0.75rem; color: var(--gray-500); font-variant-numeric: tabular-nums; }
        .dark .pv-linea-precio { color: var(--gray-400); }
        .pv-linea-controles { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 0.75rem; }
        .pv-listo { margin-inline-start: auto; font-size: 0.8rem; font-weight: 600; color: var(--primary-600); padding: 0.4rem 0.25rem; }
        .dark .pv-listo { color: var(--primary-400); }

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
            .pv-corta { padding: 0.6rem 0.75rem; }
            .pv-detalle { padding: 0.7rem 0.75rem 0.8rem; }
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
            turnoUltimo: 0,
            esperaBusqueda: null,
            esperaResumen: null,
            resaltada: null,
            abierta: null,
            ultimo: null,
            total: @js($config['total']),
            ahorro: @js($config['ahorro']),
            promociones: @js($config['promos']),

            init() {
                if (! Array.isArray(this.lineas)) {
                    this.lineas = Object.values(this.lineas ?? {})
                }

                this.$watch('lineas', () => this.recalcular())

                {{-- Al crear: si el cliente ya tiene pedidos, ofrecer los mismos productos. --}}
                if (! @js($config['edicion'])) {
                    this.$wire.$watch('data.cliente_id', (id) => this.pedirUltimo(id))
                    this.pedirUltimo(this.$wire.get('data.cliente_id'))
                }
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

            nombre(linea) {
                return this.producto(linea)?.descripcion ?? ('Producto ' + linea.producto_id)
            },

            {{-- Igual que el servidor: precio sin ISV × cantidad (a 2 decimales), más el 15% si es gravado. --}}
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

            cantidadCorta(linea) {
                return linea.cantidad + (linea.unidad === 'caja' ? ' CJ' : ' UN')
            },

            {{-- En el buscador: lo que ya lleva de ese producto, por unidad y por caja. --}}
            lleva(productoId) {
                const de = (unidad) => this.lineas
                    .filter((l) => Number(l.producto_id) === Number(productoId) && (l.unidad === 'caja') === (unidad === 'caja'))
                    .reduce((t, l) => t + Number(l.cantidad || 0), 0)
                const partes = []

                if (de('unidad') > 0) {
                    partes.push(de('unidad') + ' UN')
                }

                if (de('caja') > 0) {
                    partes.push(de('caja') + ' CJ')
                }

                return partes.join(' · ')
            },

            {{-- La caja solo se ofrece si trae más de 1 (o si la línea ya venía en caja). --}}
            tieneCaja(linea) {
                return linea.unidad === 'caja' || (this.producto(linea)?.caja ?? 1) > 1
            },

            get orden() {
                return this.lineas.map((linea, i) => i).reverse()
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

            async pedirUltimo(id) {
                const turno = ++this.turnoUltimo
                this.ultimo = null

                if (! id) {
                    return
                }

                try {
                    const r = await this.llamar('ultimoPedido', { clienteId: Number(id) })

                    if (turno === this.turnoUltimo && r && r.lineas.length > 0) {
                        this.ultimo = r
                    }
                } catch (e) {}
            },

            repetirUltimo() {
                if (! this.ultimo) {
                    return
                }

                Object.assign(this.productos, this.ultimo.productos)

                for (const l of this.ultimo.lineas) {
                    const i = this.lineas.findIndex((x) => Number(x.producto_id) === Number(l.producto_id) && x.unidad === l.unidad)

                    if (i >= 0) {
                        this.lineas[i].cantidad = Math.min(@js($config['maximo']), Number(this.lineas[i].cantidad) + Number(l.cantidad))
                    } else {
                        this.lineas.push({ producto_id: l.producto_id, unidad: l.unidad, cantidad: l.cantidad })
                    }
                }

                this.ultimo = null
                this.abierta = null
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

            {{-- Tocar un producto: entra a la lista y queda abierto; si ya estaba por unidad, le suma uno. --}}
            agregar(p) {
                this.productos[p.id] = p
                let i = this.lineas.findIndex((l) => Number(l.producto_id) === Number(p.id) && l.unidad === 'unidad')

                if (i >= 0) {
                    this.lineas[i].cantidad = Math.min(@js($config['maximo']), Number(this.lineas[i].cantidad || 0) + 1)
                } else {
                    this.lineas.push({ producto_id: p.id, unidad: 'unidad', cantidad: 1 })
                    i = this.lineas.length - 1
                }

                this.abierta = i
                this.resaltar(i)
                this.cerrarBusqueda()
                this.$refs.buscador.blur()
            },

            abrir(i) {
                this.abierta = this.abierta === i ? null : i
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

                if (this.abierta === i) {
                    this.abierta = null
                } else if (this.abierta !== null && this.abierta > i) {
                    this.abierta--
                }
            },
        }"
    >
        {{-- Buscador (se queda arriba al bajar por la lista) --}}
        <div class="pv-buscador">
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
                        <span class="pv-resultado-meta" x-text="p.codigo + ' · ' + dinero(p.precioUnidadIsv) + ' c/u' + (p.caja > 1 ? ' · caja de ' + p.caja + ' ' + dinero(p.precioCajaIsv) : '')"></span>
                        <span class="pv-resultado-lleva" x-show="lleva(p.id) !== ''" x-text="'Ya lleva ' + lleva(p.id) + ' · tocar suma 1 UN'"></span>
                    </button>
                </template>

                <div class="pv-aviso" x-show="buscado && resultados.length === 0">No hay productos activos con ese código o nombre.</div>
                <div class="pv-aviso" x-show="! buscado && buscar.trim().length === 1">Escriba al menos 2 letras (o el código).</div>
            </div>
        </div>

        {{-- Último pedido de este cliente (al crear, con la lista vacía) --}}
        <div class="pv-ultimo" x-show="ultimo && lineas.length === 0" x-cloak>
            <div>
                <div class="pv-ultimo-titulo" x-text="'Último pedido de este cliente: ' + (ultimo?.numero ?? '') + ' del ' + (ultimo?.fecha ?? '')"></div>
                <div class="pv-ultimo-detalle" x-text="(ultimo?.lineas.length ?? 0) + ((ultimo?.lineas.length ?? 0) === 1 ? ' producto' : ' productos') + ', con los precios de hoy' + (ultimo?.fuera ? ' (' + ultimo.fuera + ' ya no se venden)' : '') + '.'"></div>
            </div>
            <button type="button" x-on:click="repetirUltimo()">Agregar los mismos productos</button>
        </div>

        {{-- Lista: lo último que se agregó queda arriba --}}
        <div class="pv-vacio" x-show="lineas.length === 0" @if ($cuantas > 0) style="display: none" @endif>
            Todavía no hay productos. Búsquelos arriba y tóquelos para agregarlos.
        </div>

        <div class="pv-lista-titulo" x-show="lineas.length > 0" @if ($cuantas === 0) style="display: none" @endif>
            <span x-text="lineas.length === 1 ? '1 producto en el pedido' : lineas.length + ' productos en el pedido'">{{ $cuantas === 1 ? '1 producto en el pedido' : "{$cuantas} productos en el pedido" }}</span>
            <span x-show="abierta === null" class="pv-aviso" style="padding: 0">Toque uno para cambiarlo</span>
        </div>

        <div class="pv-lista" x-show="lineas.length > 0" @if ($cuantas === 0) style="display: none" @endif>
            <template x-for="i in orden" x-bind:key="i">
                <div class="pv-linea" x-bind:class="{ 'pv-resaltada': resaltada === i, 'pv-abierta': abierta === i }">
                    {{-- Corta --}}
                    <button type="button" class="pv-corta" x-show="abierta !== i" x-on:click="abrir(i)">
                        <span class="pv-cant" x-bind:class="{ 'pv-cant-caja': lineas[i].unidad === 'caja' }" x-text="cantidadCorta(lineas[i])"></span>
                        <span class="pv-corta-nombre" x-text="nombre(lineas[i])"></span>
                        <span class="pv-linea-subtotal" x-text="dinero(subtotal(lineas[i]))"></span>
                    </button>

                    {{-- Abierta --}}
                    <div class="pv-detalle" x-show="abierta === i">
                        <div class="pv-linea-arriba" x-on:click="abrir(i)">
                            <div class="pv-linea-nombre" x-text="nombre(lineas[i])"></div>
                            <div class="pv-linea-subtotal" x-text="dinero(subtotal(lineas[i]))"></div>
                            <button type="button" class="pv-quitar" x-on:click.stop="quitar(i)" aria-label="Quitar del pedido" title="Quitar">
                                {{ svg('heroicon-m-x-mark') }}
                            </button>
                        </div>

                        <div class="pv-linea-precio" x-text="precio(lineas[i])"></div>

                        <div class="pv-linea-controles">
                            <div class="pv-seg" role="group" aria-label="Vende en" x-show="tieneCaja(lineas[i])">
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

                            <button type="button" class="pv-listo" x-on:click="abierta = null">Listo</button>
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
