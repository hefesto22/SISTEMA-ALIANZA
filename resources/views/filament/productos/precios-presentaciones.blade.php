{{--
    Precios de venta de un producto en "Actualizar precio"
    (App\Filament\Schemas\Components\PreciosPresentaciones).

    Arriba, para leer: costo promedio de las compras, última compra y
    existencia; "Corregir costo" abre un campo solo para cuando está mal.
    Abajo, una fila por presentación: costo promedio (con y sin ISV), % de
    utilidad, precio (con ISV si es gravado), cuánto gana y si el precio es
    "Con %" (sale del costo con ese % y lo mueven las compras) o "Fijo" (lo
    pone la empresa). Debajo de cada una, en palabras, qué pasa con ese
    precio. Escribir un % la deja "Con %"; escribir otro precio, "Fijo"; el
    campo que sale de la cuenta se ve más apagado.

    Las cuentas son las de CalculadoraPrecio (4 decimales sin ISV, 2 en
    pantalla) y pasan aquí mientras se escribe; al servidor solo va el
    estado al guardar. Los estilos van aquí mismo, sin compilar.
--}}
@php
    $statePath = $getStatePath();
    $datos = $field->getDatos();
    $conIsv = ($datos['gravado'] ?? false) ? ' con ISV' : '';
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @once
        <style>
            .ap { --ap-tenue: var(--gray-500); --ap-borde: rgb(3 7 18 / 0.08); --ap-borde-campo: rgb(3 7 18 / 0.14); --ap-linea: var(--gray-100); display: grid; gap: 0.875rem; font-size: 0.875rem; container-type: inline-size; }
            .dark .ap { --ap-tenue: var(--gray-400); --ap-borde: rgb(255 255 255 / 0.1); --ap-borde-campo: rgb(255 255 255 / 0.16); --ap-linea: rgb(255 255 255 / 0.06); }

            /* ── Resumen ── */
            .ap-resumen { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem 2.25rem; padding: 0.75rem 1rem; border-radius: 0.75rem; background: var(--gray-50); box-shadow: 0 0 0 1px var(--ap-borde); }
            .dark .ap-resumen { background: rgb(255 255 255 / 0.03); }
            .ap-dato { display: grid; gap: 0.1rem; min-width: 0; }
            .ap-eyebrow { font-size: 0.68rem; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ap-tenue); }
            .ap-dato strong { font-size: 1.05rem; font-weight: 700; font-variant-numeric: tabular-nums; }
            .ap-dato small { font-size: 0.72rem; color: var(--ap-tenue); font-variant-numeric: tabular-nums; }
            .ap-enlace { margin-inline-start: auto; font-size: 0.8rem; font-weight: 600; color: var(--primary-600); cursor: pointer; }
            .dark .ap-enlace { color: var(--primary-400); }
            .ap-enlace:hover { text-decoration: underline; }

            /* ── Corregir costo ── */
            .ap-correccion { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 0.75rem; padding: 0.6rem 0.9rem; border-radius: 0.75rem; font-size: 0.8rem; background: color-mix(in oklab, var(--warning-500) 9%, transparent); box-shadow: 0 0 0 1px color-mix(in oklab, var(--warning-500) 35%, transparent); }
            .ap-correccion .ap-enlace { margin-inline-start: auto; }
            .ap-select { height: 2.25rem; padding: 0 2rem 0 0.6rem; border-radius: 0.5rem; border: 0; font: inherit; font-weight: 600; background-color: #fff; box-shadow: 0 0 0 1px var(--ap-borde-campo); color: inherit; }
            .dark .ap-select { background-color: var(--gray-900); }

            /* ── Tabla ── */
            .ap-tabla { border-radius: 0.75rem; box-shadow: 0 0 0 1px var(--ap-borde); overflow: hidden; }
            /* Ancho (modal grande): todo en una línea y la explicación debajo. */
            .ap-cabeza, .ap-fila { display: grid; grid-template-columns: minmax(5rem, 1fr) 9rem 6.25rem 9rem 7.5rem 10.5rem; grid-template-areas: "pres costo margen precio gana modo" "explica explica explica explica explica explica"; gap: 0.4rem 0.75rem; align-items: center; padding: 0.6rem 0.9rem; }
            .ap-cabeza { grid-template-areas: "pres costo margen precio gana modo"; padding-block: 0.45rem; font-size: 0.68rem; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ap-tenue); background: var(--gray-50); }
            .dark .ap-cabeza { background: rgb(255 255 255 / 0.03); }
            .ap-cabeza > * { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .ap-cabeza > :nth-child(2), .ap-cabeza > :nth-child(3), .ap-cabeza > :nth-child(4), .ap-cabeza > :nth-child(5) { text-align: end; }
            .ap-cabeza > :nth-child(6) { text-align: center; }
            .ap-pres { grid-area: pres; }
            .ap-costo { grid-area: costo; }
            .ap-fila > .ap-campo:not(.ap-campo-dinero) { grid-area: margen; }
            .ap-fila > .ap-campo-dinero { grid-area: precio; }
            .ap-gana { grid-area: gana; }
            .ap-segmento { grid-area: modo; }
            .ap-explica { grid-area: explica; }
            .ap-fila + .ap-fila { border-top: 1px solid var(--ap-linea); }
            .ap-fila-pierde { background: color-mix(in oklab, var(--danger-500) 7%, transparent); }

            .ap-pres { min-width: 0; }
            .ap-pres strong { display: block; font-size: 0.95rem; font-weight: 700; line-height: 1.3; }
            .ap-pres span { display: block; font-size: 0.75rem; color: var(--ap-tenue); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-variant-numeric: tabular-nums; }
            .ap-costo { display: grid; gap: 0.05rem; min-width: 0; line-height: 1.3; }
            .ap-costo p { display: flex; justify-content: flex-end; align-items: baseline; gap: 0.35rem; margin: 0; white-space: nowrap; font-variant-numeric: tabular-nums; }
            .ap-costo small { font-size: 0.68rem; color: var(--ap-tenue); }
            .ap-costo strong { font-weight: 600; }
            .ap-costo span { font-size: 0.8rem; color: var(--ap-tenue); }

            .ap-campo { display: flex; align-items: center; gap: 0.35rem; height: 2.25rem; padding: 0 0.6rem; border-radius: 0.5rem; background: #fff; box-shadow: 0 0 0 1px var(--ap-borde-campo); transition: box-shadow 0.15s ease; cursor: text; }
            .dark .ap-campo { background: rgb(255 255 255 / 0.05); }
            .ap-campo:focus-within { box-shadow: 0 0 0 2px var(--primary-600); }
            .dark .ap-campo:focus-within { box-shadow: 0 0 0 2px var(--primary-500); }
            .ap-campo input { flex: 1; min-width: 0; width: 100%; padding: 0; border: 0; background: transparent; font: inherit; font-variant-numeric: tabular-nums; text-align: end; color: inherit; outline: none; box-shadow: none; }
            .ap-campo > span { font-size: 0.8rem; color: var(--ap-tenue); }
            .ap-campo-dinero input { font-size: 0.95rem; font-weight: 700; }
            .ap-campo-error, .ap-campo-error:focus-within { box-shadow: 0 0 0 2px var(--danger-500); }
            /* El que sale de la cuenta (no el que manda): más apagado, pero se puede escribir. */
            .ap-campo-calculado { background: transparent; box-shadow: inset 0 0 0 1px var(--ap-borde); }
            .dark .ap-campo-calculado { background: transparent; }
            .ap-campo-calculado input { color: var(--ap-tenue); font-weight: 600; }
            .ap-correccion .ap-campo { width: 9rem; }

            .ap-gana { text-align: end; line-height: 1.25; min-width: 0; }
            .ap-gana strong { display: block; font-weight: 700; font-variant-numeric: tabular-nums; }
            .ap-gana span { display: block; font-size: 0.72rem; color: var(--ap-tenue); font-variant-numeric: tabular-nums; white-space: nowrap; }
            .ap-ok { color: var(--success-700); }
            .dark .ap-ok { color: var(--success-400); }
            .ap-mal { color: var(--danger-600); }
            .dark .ap-mal { color: var(--danger-400); }
            .ap-gana .ap-alerta { color: var(--warning-700); font-weight: 600; }
            .dark .ap-gana .ap-alerta { color: var(--warning-400); }

            /* Con % | Fijo: dos opciones, se ve cuál está puesta. */
            .ap-segmento { display: grid; grid-template-columns: 1fr 1fr; height: 2.25rem; padding: 0.15rem; border-radius: 0.55rem; box-shadow: 0 0 0 1px var(--ap-borde-campo); }
            .ap-segmento button { display: inline-flex; align-items: center; justify-content: center; gap: 0.3rem; border-radius: 0.4rem; font-size: 0.78rem; font-weight: 600; color: var(--ap-tenue); cursor: pointer; }
            .ap-segmento button:hover { color: var(--gray-950); }
            .dark .ap-segmento button:hover { color: #fff; }
            .ap-segmento svg { width: 0.95rem; height: 0.95rem; flex-shrink: 0; }
            .ap-segmento .ap-segmento-con, .ap-segmento .ap-segmento-con:hover { color: var(--success-700); background: color-mix(in oklab, var(--success-500) 15%, transparent); }
            .dark .ap-segmento .ap-segmento-con, .dark .ap-segmento .ap-segmento-con:hover { color: var(--success-300); }
            .ap-segmento .ap-segmento-fijo, .ap-segmento .ap-segmento-fijo:hover { color: var(--warning-700); background: color-mix(in oklab, var(--warning-500) 18%, transparent); }
            .dark .ap-segmento .ap-segmento-fijo, .dark .ap-segmento .ap-segmento-fijo:hover { color: var(--warning-300); }

            /* Qué pasa con ese precio, en palabras. */
            .ap-explica { margin: 0; font-size: 0.75rem; line-height: 1.35; color: var(--ap-tenue); }
            .ap-explica strong { font-weight: 600; color: var(--success-700); }
            .dark .ap-explica strong { color: var(--success-400); }
            .ap-explica.ap-explica-fijo strong { color: var(--warning-700); }
            .dark .ap-explica.ap-explica-fijo strong { color: var(--warning-400); }

            .ap-ayuda { margin: 0; font-size: 0.75rem; color: var(--ap-tenue); }

            /* Mediano: "Con % | Fijo" baja junto a la explicación. */
            @container (max-width: 54rem) {
                .ap-cabeza, .ap-fila { grid-template-columns: minmax(9.5rem, 1fr) 9rem 6.25rem 9rem 7.5rem; grid-template-areas: "pres costo margen precio gana" "modo explica explica explica explica"; }
                .ap-cabeza { grid-template-areas: "pres costo margen precio gana"; }
                .ap-cabeza > :nth-child(6) { display: none; }
            }

            /* Angosto (celular): en tarjetas. */
            @container (max-width: 46.5rem) {
                .ap-cabeza { display: none; }
                .ap-fila { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); grid-template-areas: "pres pres" "costo gana" "margen precio" "modo modo" "explica explica"; }
                .ap-gana { text-align: start; }
                .ap-costo p { justify-content: flex-start; }
                .ap-enlace { margin-inline-start: 0; }
            }
        </style>
    @endonce

    <div
        wire:ignore
        class="ap"
        x-data="{
            estado: $wire.$entangle(@js($statePath)),
            datos: @js($datos),
            entrada: {},
            regla: {},
            costoPor: null,
            costoTexto: '',

            init() {
                if (! this.estado || typeof this.estado !== 'object') {
                    this.estado = { corregir: false, costo_base: this.datos.costoHoy ?? 0, filas: [] }
                }

                if (! Array.isArray(this.estado.filas)) {
                    this.estado.filas = Object.values(this.estado.filas ?? {})
                }

                for (const fila of this.estado.filas) {
                    this.regla[fila.id] = fila.modo === 'fijo' ? this.p(fila).regla : (fila.margen ?? this.p(fila).regla)
                    this.entrada[fila.id] = {
                        margen: this.textoNumero(fila.margen),
                        precio: Number(fila.precio_base) > 0 ? this.fijo2(this.pantalla(fila.precio_base)) : '',
                    }
                }

                this.costoPor = this.estado.filas[0]?.id ?? null
                this.costoTexto = this.fijo2(this.costoEnPantalla(this.costoPor))
            },

            {{-- ── Números (las mismas cuentas que CalculadoraPrecio) ── --}}
            num(valor) {
                const numero = parseFloat(String(valor ?? '').replace(/[^0-9.,-]/g, '').replace(',', '.'))

                return Number.isFinite(numero) ? numero : null
            },
            r2(numero) { return Math.round((numero + Number.EPSILON) * 100) / 100 },
            r4(numero) { return Math.round((numero + Number.EPSILON) * 10000) / 10000 },
            r6(numero) { return Math.round((numero + Number.EPSILON) * 1000000) / 1000000 },
            factor() { return this.datos.gravado ? 1 + this.datos.tasa : 1 },
            conIsv(monto) { return this.r4(monto * this.factor()) },
            pantalla(sinIsv) { return this.r2(this.conIsv(this.r4(Number(sinIsv) || 0))) },
            fijo2(numero) { return (Number(numero) || 0).toFixed(2) },
            textoNumero(numero) { return numero === null || numero === undefined || numero === '' ? '' : String(this.r2(Number(numero))) },
            dinero(numero) { return this.datos.simbolo + ' ' + (Number(numero) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
            dinero4(numero) { return this.datos.simbolo + ' ' + (Number(numero) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 }) },

            {{-- ── Cada presentación ── --}}
            p(fila) { return this.datos.presentaciones[fila.id] ?? { sigla: '?', nombre: '', contenido: 1, precioHoy: 0, regla: 0 } },
            costoBase() { return Number(this.estado.costo_base) || 0 },
            costoDe(fila) { return this.r4(this.costoBase() * this.p(fila).contenido) },
            sugerido(fila, margen) {
                const costo = this.costoDe(fila)
                const m = Number(margen)

                if (! (costo > 0) || margen === null || margen === '' || ! Number.isFinite(m) || m < 0 || m >= 100) {
                    return null
                }

                return this.r4(costo / (1 - this.r4(m / 100)))
            },
            margenReal(fila) {
                const costo = this.costoDe(fila)
                const precio = this.r4(Number(fila.precio_base) || 0)

                return costo > 0 && precio > 0 ? this.r2(this.r4(1 - costo / precio) * 100) : null
            },
            gana(fila) {
                const precio = this.r4(Number(fila.precio_base) || 0)
                const costo = this.costoDe(fila)

                return precio > 0 && costo > 0 ? this.r2(this.conIsv(precio) - this.conIsv(costo)) : null
            },
            textoGana(fila) {
                const gana = this.gana(fila)

                if (gana === null) {
                    return Number(fila.precio_base) > 0 ? 'Sin costo' : 'Sin precio'
                }

                return gana < 0 ? 'Pierde ' + this.dinero(-gana) : 'Gana ' + this.dinero(gana)
            },
            claseGana(fila) {
                const gana = this.gana(fila)

                return gana === null ? '' : (gana < 0 ? 'ap-mal' : 'ap-ok')
            },
            cambio(fila) {
                const hoy = this.p(fila).precioHoy

                return hoy > 0 && Number(fila.precio_base) > 0 && Math.abs(this.pantalla(fila.precio_base) - this.pantalla(hoy)) >= 0.005
            },
            sospechoso(fila) {
                const hoy = this.p(fila).precioHoy
                const precio = this.r4(Number(fila.precio_base) || 0)

                return hoy > 0 && precio > 0 && Math.abs(precio - hoy) / hoy > this.datos.sospechoso
            },
            errorMargen(fila) {
                if (fila.modo === 'fijo') {
                    return false
                }

                const m = this.num(this.entrada[fila.id]?.margen)

                return m === null || m < 0 || m >= 100
            },
            errorPrecio(fila) {
                return fila.modo === 'fijo' && ! (Number(fila.precio_base) > 0)
            },

            {{-- ── Al escribir ── --}}
            alMargen(fila) {
                const m = this.num(this.entrada[fila.id].margen)

                if (m === null) {
                    return
                }

                fila.modo = 'calculado'
                fila.margen = this.r2(m)
                this.regla[fila.id] = fila.margen

                const sugerido = this.sugerido(fila, fila.margen)

                if (sugerido !== null) {
                    fila.precio_base = sugerido
                    this.entrada[fila.id].precio = this.fijo2(this.pantalla(sugerido))
                }
            },
            alPrecio(fila) {
                const venta = this.num(this.entrada[fila.id].precio)

                if (venta === null || venta <= 0) {
                    return
                }

                fila.precio_base = this.r6(venta / this.factor())

                {{-- El mismo que da su %: sigue Con %. Otro: lo pone la empresa, queda fijo. --}}
                const sugerido = this.sugerido(fila, this.regla[fila.id])

                if (sugerido !== null && Math.abs(venta - this.pantalla(sugerido)) < 0.005) {
                    fila.modo = 'calculado'
                    fila.margen = this.regla[fila.id]
                    fila.precio_base = sugerido
                } else {
                    fila.modo = 'fijo'
                    fila.margen = this.margenReal(fila)
                }

                this.entrada[fila.id].margen = this.textoNumero(fila.margen)
            },
            ponerModo(fila, modo) {
                if (fila.modo !== modo) {
                    this.alternar(fila)
                }
            },
            explicacion(fila) {
                const fijo = fila.modo === 'fijo'
                const precio = Number(fila.precio_base) > 0 ? this.dinero(this.pantalla(fila.precio_base)) : null

                if (! (this.costoDe(fila) > 0)) {
                    return fijo
                        ? 'el precio se queda en ' + (precio ?? 'lo que escriba') + ' aunque lleguen compras.'
                        : 'todavía no hay costo: el precio saldrá de la primera compra con ' + this.textoNumero(fila.margen) + ' %.'
                }

                if (! fijo) {
                    return 'el precio sale del costo con ' + this.textoNumero(fila.margen) + ' % de utilidad. Si una compra cambia el costo, el precio se ajusta solo y sigue dejando ese %.'
                }

                const sugerido = this.sugerido(fila, this.regla[fila.id])
                const real = this.textoNumero(fila.margen)
                let texto = 'el precio se queda en ' + (precio ?? '—') + ' aunque cambie el costo'

                if (real === '') {
                    texto += '.'
                } else if (Number(fila.margen) < 0) {
                    texto += '; hoy se vende con pérdida (' + real + ' %).'
                } else {
                    texto += '; hoy deja ' + real + ' % de utilidad.'
                }

                if (sugerido !== null && precio !== null && Math.abs(this.pantalla(sugerido) - this.pantalla(fila.precio_base)) >= 0.005) {
                    texto += ' Con ' + this.textoNumero(this.regla[fila.id]) + ' % sería ' + this.dinero(this.pantalla(sugerido)) + '.'
                }

                return texto
            },
            formatearPrecio(fila) {
                const venta = this.num(this.entrada[fila.id].precio)

                if (venta !== null && venta > 0) {
                    this.entrada[fila.id].precio = this.fijo2(venta)
                }
            },
            alternar(fila) {
                if (fila.modo === 'fijo') {
                    fila.modo = 'calculado'
                    fila.margen = this.regla[fila.id]

                    const sugerido = this.sugerido(fila, fila.margen)

                    if (sugerido !== null) {
                        fila.precio_base = sugerido
                        this.entrada[fila.id].precio = this.fijo2(this.pantalla(sugerido))
                    }
                } else {
                    fila.modo = 'fijo'
                    fila.margen = this.margenReal(fila)
                }

                this.entrada[fila.id].margen = this.textoNumero(fila.margen)
            },

            {{-- ── Corregir costo ── --}}
            costoEnPantalla(id) {
                const presentacion = this.datos.presentaciones[id]

                return presentacion ? this.r2(this.conIsv(this.r4(this.costoBase() * presentacion.contenido))) : 0
            },
            abrirCorreccion() {
                this.estado.corregir = true
                this.costoPor = this.estado.filas[0]?.id ?? null
                this.costoTexto = this.fijo2(this.costoEnPantalla(this.costoPor))
                this.$nextTick(() => this.$refs.costo?.select())
            },
            quitarCorreccion() {
                this.estado.corregir = false
                this.estado.costo_base = this.datos.costoHoy
                this.costoTexto = this.fijo2(this.costoEnPantalla(this.costoPor))
                this.recalcular()
            },
            alCosto() {
                const costo = this.num(this.costoTexto)
                const presentacion = this.datos.presentaciones[this.costoPor]

                if (costo === null || costo < 0 || ! presentacion) {
                    return
                }

                this.estado.costo_base = this.r6(this.r6(costo / this.factor()) / presentacion.contenido)
                this.recalcular()
            },
            alCostoPor() {
                this.costoTexto = this.fijo2(this.costoEnPantalla(this.costoPor))
            },
            corregido() {
                return this.estado.corregir && Math.abs(this.r4(this.costoBase()) - this.datos.costoHoy) >= 0.00005
            },
            recalcular() {
                for (const fila of this.estado.filas) {
                    if (fila.modo === 'fijo') {
                        fila.margen = this.margenReal(fila)
                    } else {
                        const sugerido = this.sugerido(fila, fila.margen)

                        if (sugerido !== null) {
                            fila.precio_base = sugerido
                            this.entrada[fila.id].precio = this.fijo2(this.pantalla(sugerido))
                        }
                    }

                    this.entrada[fila.id].margen = this.textoNumero(fila.margen)
                }
            },
        }"
    >
        {{-- ── Resumen: lo que dejan las compras ── --}}
        <div class="ap-resumen">
            <div class="ap-dato">
                <span class="ap-eyebrow">Costo promedio por {{ $datos['base'] ?? 'UN' }}</span>
                <strong x-text="datos.costoHoy > 0 ? dinero(pantalla(datos.costoHoy)) : 'Sin costo'"></strong>
                <small x-text="datos.costoHoy > 0 ? (datos.gravado ? 'con ISV · sin ISV ' + dinero4(datos.costoHoy) : 'de las compras (exento)') : 'lo pone la primera compra'"></small>
            </div>

            <div class="ap-dato" x-show="corregido()" x-cloak>
                <span class="ap-eyebrow">Corregido a</span>
                <strong class="ap-mal" x-text="dinero(pantalla(costoBase()))"></strong>
                <small>se guarda en lugar del promedio</small>
            </div>

            <div class="ap-dato">
                <span class="ap-eyebrow">Última compra</span>
                <template x-if="datos.ultima">
                    <div class="ap-dato">
                        <strong x-text="dinero(datos.ultima.monto) + ' la ' + datos.ultima.sigla"></strong>
                        <small x-text="datos.ultima.fecha + ' · ' + datos.ultima.numero"></small>
                    </div>
                </template>
                <template x-if="! datos.ultima">
                    <div class="ap-dato">
                        <strong>—</strong>
                        <small>todavía no hay compras</small>
                    </div>
                </template>
            </div>

            <div class="ap-dato">
                <span class="ap-eyebrow">Existencia</span>
                <strong x-text="datos.existencia"></strong>
                <small>todas las bodegas</small>
            </div>

            <button type="button" class="ap-enlace" x-show="! estado.corregir" x-on:click="abrirCorreccion()">Corregir costo</button>
        </div>

        {{-- ── Corregir costo (solo si está mal) ── --}}
        <div class="ap-correccion" x-show="estado.corregir" x-cloak>
            <span>Costo correcto{{ $conIsv }} de</span>
            <select class="ap-select" x-model.number="costoPor" x-on:change="alCostoPor()" aria-label="Presentación del costo">
                <template x-for="fila in estado.filas" :key="fila.id">
                    <option :value="fila.id" x-text="p(fila).sigla"></option>
                </template>
            </select>
            <label class="ap-campo ap-campo-dinero">
                <span>{{ $datos['simbolo'] ?? 'L.' }}</span>
                <input type="text" inputmode="decimal" x-ref="costo" x-model="costoTexto" x-on:input="alCosto()" aria-label="Costo correcto">
            </label>
            <span x-text="'= ' + dinero4(conIsv(costoBase())) + ' por ' + @js($datos['base'] ?? 'UN')"></span>
            <button type="button" class="ap-enlace" x-on:click="quitarCorreccion()">Quitar corrección</button>
        </div>

        {{-- ── Precio de cada presentación ── --}}
        <div class="ap-tabla" role="table" aria-label="Precio de venta de cada presentación">
            <div class="ap-cabeza" role="row">
                <span role="columnheader">Presentación</span>
                <span role="columnheader">Costo promedio</span>
                <span role="columnheader">Utilidad</span>
                <span role="columnheader">Precio{{ $conIsv }}</span>
                <span role="columnheader">Ganancia</span>
                <span role="columnheader">El precio es</span>
            </div>

            <template x-for="fila in estado.filas" :key="fila.id">
                <div class="ap-fila" role="row" :class="{ 'ap-fila-pierde': (gana(fila) ?? 0) < 0 }">
                    <div class="ap-pres" role="cell">
                        <strong x-text="p(fila).sigla"></strong>
                        <span x-text="p(fila).nombre"></span>
                    </div>

                    <div class="ap-costo" role="cell">
                        <p>
                            <small x-text="costoDe(fila) > 0 ? (datos.gravado ? 'con ISV' : 'exento') : 'sin costo'"></small>
                            <strong x-text="costoDe(fila) > 0 ? dinero(pantalla(costoDe(fila))) : '—'"></strong>
                        </p>
                        <p x-show="datos.gravado && costoDe(fila) > 0">
                            <small>sin ISV</small>
                            <span x-text="dinero(costoDe(fila))"></span>
                        </p>
                    </div>

                    <label class="ap-campo" role="cell" :class="{ 'ap-campo-error': errorMargen(fila), 'ap-campo-calculado': fila.modo === 'fijo' }">
                        <input type="text" inputmode="decimal" x-model="entrada[fila.id].margen" x-on:input="alMargen(fila)" :aria-label="'Utilidad de ' + p(fila).sigla">
                        <span>%</span>
                    </label>

                    <label class="ap-campo ap-campo-dinero" role="cell" :class="{ 'ap-campo-error': errorPrecio(fila), 'ap-campo-calculado': fila.modo !== 'fijo' }">
                        <span>{{ $datos['simbolo'] ?? 'L.' }}</span>
                        <input type="text" inputmode="decimal" x-model="entrada[fila.id].precio" x-on:input="alPrecio(fila)" x-on:blur="formatearPrecio(fila)" :aria-label="'Precio de ' + p(fila).sigla">
                    </label>

                    <div class="ap-gana" role="cell">
                        <strong :class="claseGana(fila)" x-text="textoGana(fila)"></strong>
                        <span x-show="cambio(fila)" :class="{ 'ap-alerta': sospechoso(fila) }" x-text="(sospechoso(fila) ? 'Cambio grande · antes ' : 'antes ') + dinero(pantalla(p(fila).precioHoy))"></span>
                    </div>

                    <div class="ap-segmento" role="cell">
                        <button type="button" :class="{ 'ap-segmento-con': fila.modo !== 'fijo' }" x-on:click="ponerModo(fila, 'calculado')" title="Sale del costo con el %: lo mueven las compras">
                            {{ svg('heroicon-o-lock-open') }}
                            <span>Con %</span>
                        </button>
                        <button type="button" :class="{ 'ap-segmento-fijo': fila.modo === 'fijo' }" x-on:click="ponerModo(fila, 'fijo')" title="Lo pone usted: las compras no lo mueven">
                            {{ svg('heroicon-o-lock-closed') }}
                            <span>Fijo</span>
                        </button>
                    </div>

                    <p class="ap-explica" :class="{ 'ap-explica-fijo': fila.modo === 'fijo' }">
                        <strong x-text="fila.modo === 'fijo' ? 'Fijo:' : 'Con %:'"></strong>
                        <span x-text="explicacion(fila)"></span>
                    </p>
                </div>
            </template>
        </div>

        <p class="ap-ayuda">Escriba el % o el precio: el que escriba manda y el otro sale de la cuenta. Aplica en todas las bodegas y queda en el historial.</p>
    </div>
</x-dynamic-component>
