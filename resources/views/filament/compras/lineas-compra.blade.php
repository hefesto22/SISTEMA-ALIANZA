{{--
    Productos de una compra como lista tipo carrito (App\Filament\Schemas\Components\LineasCompra)
    y, abajo, la barra con el total y "Registrar compra".

    Arriba el buscador con los productos del proveedor escogido (al tocarlo
    sin escribir salen los primeros). Al tocar un producto entra en su
    presentación más grande y con el costo vacío: se escribe el de la
    factura. Cada fila: en qué presentación viene (CJ12, CJ8, UN…), cantidad
    con − y +, costo como viene en la factura (con ISV si es gravado) y el
    total. Abajo, cómo queda con la compra: "Costo CJ12: está a L X →
    quedará a L Y" y el precio de venta igual (el fijo se queda), con la
    misma cuenta que RegistrarCompra (todas las líneas del producto, en
    orden). Si el costo se aleja mucho del de hoy, avisa.

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
        'metodo'    => LineasCompra::metodoCosto(),
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

            /* CJ12 | CJ8 | UN */
            .lc-segmento { display: inline-flex; flex-wrap: wrap; padding: 0.2rem; border-radius: 0.5rem; background: var(--gray-100); }
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

            /* Está a → quedará a */
            .lc-queda { display: grid; gap: 0.2rem; padding: 0.5rem 0.65rem; border-radius: 0.5rem; font-size: 0.78rem; color: var(--lc-tenue); background: var(--gray-50); font-variant-numeric: tabular-nums; }
            .dark .lc-queda { background: rgb(255 255 255 / 0.03); }
            .lc-queda div { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.15rem 0.4rem; }
            .lc-queda b { font-weight: 700; color: var(--gray-700); }
            .dark .lc-queda b { color: var(--gray-200); }
            .lc-queda strong { font-weight: 800; color: var(--gray-950); }
            .dark .lc-queda strong { color: #fff; }
            .lc-queda .lc-sube { color: var(--danger-600); }
            .lc-queda .lc-baja { color: var(--success-600); }
            .dark .lc-queda .lc-sube { color: var(--danger-400); }
            .dark .lc-queda .lc-baja { color: var(--success-400); }

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
            presentacion(linea) {
                return (this.productos[linea.producto_id]?.presentaciones ?? []).find((pr) => Number(pr.id) === Number(linea.presentacion_id)) ?? null
            },

            {{-- Costo de hoy de la presentación de la línea, con ISV si es gravado. --}}
            hoy(linea) {
                return Number(this.presentacion(linea)?.costoHoy) || 0
            },

            {{-- "Cajas de 12", "Unidades". --}}
            titulo(pr) {
                const plural = String(pr?.plural ?? 'unidades')

                return plural.charAt(0).toUpperCase() + plural.slice(1)
            },

            {{-- Tocar un producto: entra en su presentación más grande con el costo vacío (se escribe el de la factura). Si ya estaba en esa presentación, le suma 1. --}}
            agregar(p) {
                const pr = (p.presentaciones ?? [])[0]

                if (! pr) {
                    return
                }

                this.productos[p.id] = p

                let i = this.lineas.findIndex((linea) => Number(linea.producto_id) === Number(p.id) && Number(linea.presentacion_id) === Number(pr.id))

                if (i >= 0) {
                    this.lineas[i].cantidad = Math.min(this.n(this.lineas[i].cantidad) + 1, @js($config['maximo']))
                } else {
                    this.lineas.push({ producto_id: Number(p.id), presentacion_id: Number(pr.id), cantidad: 1, costo: '' })
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

            {{-- Otra presentación: el costo escrito era de la otra, así que se vuelve a escribir. --}}
            cambiarPresentacion(linea, pr) {
                if (Number(linea.presentacion_id) === Number(pr.id)) {
                    return
                }

                linea.presentacion_id = Number(pr.id)
                linea.costo = ''
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

            r4(numero) {
                return Math.round((numero + Number.EPSILON) * 10000) / 10000
            },

            conIsv(monto, gravado) {
                return this.r4(gravado ? monto * (1 + @js($config['tasa'])) : monto)
            },

            {{-- Costo por unidad sin ISV con que queda el producto después de TODAS sus líneas de esta compra, en orden, como RegistrarCompra (promedio con lo que hay en todas las bodegas, o el último). null si todavía no hay costo escrito. --}}
            costoFinal(id) {
                const p = this.productos[id]

                if (! p) {
                    return null
                }

                let existencia = Number(p.existencia) || 0
                let costo = Number(p.costoUnidad) || 0
                let alguna = false

                for (const linea of this.lineas.filter((l) => Number(l.producto_id) === Number(id))) {
                    const pr = this.presentacion(linea)
                    const cantidad = this.n(linea.cantidad)
                    const pagado = this.decimal(linea.costo)

                    if (! pr || cantidad < 1 || pagado <= 0) {
                        continue
                    }

                    const contenido = Math.max(1, Number(pr.contenido))
                    const unidades = cantidad * contenido
                    const deCompra = this.r4(pagado / (p.gravado ? 1 + @js($config['tasa']) : 1) / contenido)

                    costo = @js($config['metodo']) === 'ultimo' || existencia <= 0
                        ? deCompra
                        : this.r4((existencia * costo + unidades * deCompra) / (existencia + unidades))
                    existencia += unidades
                    alguna = true
                }

                return alguna ? costo : null
            },

            {{-- Cómo quedan el costo y el precio de la presentación de la línea con la compra (con ISV si es gravado). --}}
            queda(linea) {
                const p = this.productos[linea.producto_id]
                const pr = this.presentacion(linea)
                const final = this.costoFinal(linea.producto_id)

                if (! p || ! pr || final === null) {
                    return null
                }

                const contenido = Math.max(1, Number(pr.contenido))
                const costo = this.redondear(this.conIsv(final * contenido, p.gravado))
                const fijo = pr.fijo && Number(pr.precioHoy) > 0
                const precio = fijo
                    ? Number(pr.precioHoy)
                    : this.redondear(this.conIsv(this.r4(this.r4(final * contenido) / (1 - Number(p.margen))), p.gravado))

                return { costo, precio, fijo }
            },

            {{-- "está a L 867.10 → quedará a L 880.00"; sin costo escrito, solo "está a". --}}
            cambioTexto(antes, despues, sinAntes) {
                const desde = antes > 0 ? 'está a ' + this.dinero(antes) : sinAntes

                if (despues === null) {
                    return desde
                }

                return desde + ' → quedará a '
            },

            claseCambio(antes, despues) {
                if (despues === null || antes <= 0 || Math.abs(despues - antes) < 0.005) {
                    return ''
                }

                return despues > antes ? 'lc-sube' : 'lc-baja'
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

                return p.codigo + (p.gravado ? '' : ' · exento')
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
                            <span class="lc-resultado-meta" x-text="p.codigo + ' · ' + (p.presentaciones ?? []).map((pr) => pr.sigla).join(' · ') + (p.gravado ? '' : ' · exento')"></span>
                        </span>
                        <span class="lc-resultado-costo">
                            <span>costo hoy</span>
                            <strong x-text="Number(p.presentaciones?.[0]?.costoHoy) > 0 ? dinero(p.presentaciones[0].costoHoy) : '—'"></strong>
                            <span x-text="p.presentaciones?.[0]?.sigla ?? ''"></span>
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
            <template x-for="(linea, i) in lineas" x-bind:key="linea.producto_id + '-' + linea.presentacion_id + '-' + i">
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
                        <div class="lc-campo" x-show="(productos[linea.producto_id]?.presentaciones ?? []).length > 1">
                            <span class="lc-etiqueta">Viene en</span>
                            <div class="lc-segmento">
                                <template x-for="pr in (productos[linea.producto_id]?.presentaciones ?? [])" x-bind:key="'pres-' + pr.id">
                                    <button type="button" x-bind:class="{ 'lc-activo': Number(linea.presentacion_id) === Number(pr.id) }" x-bind:title="pr.nombre" x-on:click="cambiarPresentacion(linea, pr)" x-text="pr.sigla"></button>
                                </template>
                            </div>
                        </div>

                        <div class="lc-campo">
                            <span class="lc-etiqueta" x-text="titulo(presentacion(linea))"></span>
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
                            <span class="lc-etiqueta" x-text="'Costo ' + (presentacion(linea)?.sigla ?? '') + (productos[linea.producto_id]?.gravado ? ' con ISV' : '')"></span>
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
                            <span class="lc-hoy" x-show="linea.costo === ''" x-text="'Escriba el de la factura'"></span>
                        </div>

                        <div class="lc-campo lc-total">
                            <span class="lc-etiqueta">Total</span>
                            <strong x-text="dinero(totalLinea(linea))"></strong>
                        </div>
                    </div>

                    {{-- Cómo queda con la compra --}}
                    <div class="lc-queda" x-show="presentacion(linea)">
                        <div>
                            <b x-text="'Costo ' + (presentacion(linea)?.sigla ?? '') + ':'"></b>
                            <span x-text="cambioTexto(hoy(linea), queda(linea)?.costo ?? null, 'sin costo todavía')"></span>
                            <strong x-show="queda(linea)" x-bind:class="claseCambio(hoy(linea), queda(linea)?.costo ?? null)" x-text="dinero(queda(linea)?.costo ?? 0)"></strong>
                        </div>
                        <div>
                            <b x-text="'Precio de venta ' + (presentacion(linea)?.sigla ?? '') + ':'"></b>
                            <span x-text="cambioTexto(Number(presentacion(linea)?.precioHoy ?? 0), queda(linea)?.precio ?? null, 'sin precio todavía')"></span>
                            <strong x-show="queda(linea)" x-bind:class="claseCambio(Number(presentacion(linea)?.precioHoy ?? 0), queda(linea)?.precio ?? null)" x-text="dinero(queda(linea)?.precio ?? 0)"></strong>
                            <span x-show="queda(linea)?.fijo" x-cloak>(fijo, no cambia)</span>
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
