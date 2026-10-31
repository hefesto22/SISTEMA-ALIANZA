{{--
    Validar y Corregir: cuánto sale de cada producto (App\Filament\Schemas\Components\SalidaDePedido).

    Pensado para pedidos largos (30 productos o más): cada producto es una
    fila corta con lo que sale ("2 CJ", "3 UN"), el nombre, lo que pidió y, a
    la derecha, si cambió ("−1 CJ", "+3 UN", "No sale") y cuánto queda en la
    bodega. Al tocarla se abre: cuánto hay en la bodega, cuánto saca este
    pedido y cuánto quedaría, y los contadores de CAJAS y UNIDADES con − y +
    (se puede mandar menos, más o en la otra unidad: unidades sueltas si una
    caja vino dañada). Arriba, filtros: todos, con cambios, no alcanza. Abajo,
    un resumen de lo que no va completo y de lo que va de más.

    El estado son las líneas del pedido más, sin id, la unidad que cada
    producto no trae (LineasPedido::datos); aquí se juntan por producto.
    Todo pasa en el teléfono (Alpine); al servidor solo va al confirmar. Los
    estilos van aquí mismo, sin compilar.
--}}
@php
    $statePath = $getStatePath();
    $filas = is_array($getState()) ? array_values($getState()) : [];
    $clave = md5((string) json_encode(array_map(fn (mixed $fila): mixed => is_array($fila) ? [$fila['id'] ?? null, $fila['producto_id'] ?? null, $fila['es_caja'] ?? null] : null, $filas)));
    $productos = count(array_unique(array_map(fn (mixed $fila): mixed => is_array($fila) ? ($fila['producto_id'] ?? null) : null, $filas)));
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @once
        <style>
            .sp { --sp-tenue: var(--gray-500); --sp-linea: var(--gray-100); --sp-borde: rgb(3 7 18 / 0.08); --sp-suave: var(--gray-50); display: grid; gap: 0.6rem; font-size: 0.875rem; }
            .dark .sp { --sp-tenue: var(--gray-400); --sp-linea: rgb(255 255 255 / 0.06); --sp-borde: rgb(255 255 255 / 0.1); --sp-suave: rgb(255 255 255 / 0.03); }

            /* ── Arriba: cuántos y filtros ── */
            .sp-resumen { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; font-size: 0.8rem; font-weight: 600; color: var(--sp-tenue); }
            .sp-resumen-ok { color: var(--success-600); }
            .sp-resumen-falta { color: var(--warning-600); }
            .dark .sp-resumen-ok { color: var(--success-400); }
            .dark .sp-resumen-falta { color: var(--warning-400); }
            .sp-filtros { display: flex; flex-wrap: wrap; gap: 0.4rem; }
            .sp-filtro { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.3rem 0.65rem; border-radius: 999px; font-size: 0.78rem; font-weight: 600; color: var(--gray-600); outline: 1px solid var(--sp-borde); }
            .dark .sp-filtro { color: var(--gray-300); }
            .sp-filtro b { font-weight: 700; font-variant-numeric: tabular-nums; opacity: 0.75; }
            .sp-filtro.sp-activo { color: #fff; background: var(--primary-600); outline-color: var(--primary-600); }
            .sp-filtro.sp-activo b { opacity: 1; }
            .sp-vacio { padding: 1.25rem 1rem; text-align: center; font-size: 0.85rem; color: var(--sp-tenue); }

            /* ── Lista ── */
            .sp-lista { display: grid; margin: 0; padding: 0; list-style: none; border-radius: 0.75rem; outline: 1px solid var(--sp-borde); overflow: hidden; }
            .sp-fila { border-bottom: 1px solid var(--sp-linea); transition: background-color 0.2s ease; }
            .sp-fila:last-child { border-bottom: 0; }
            .sp-fila.sp-fila-nada { background: color-mix(in oklab, var(--danger-500) 6%, transparent); }
            .sp-fila.sp-fila-parcial { background: color-mix(in oklab, var(--warning-500) 5%, transparent); }
            .sp-fila.sp-fila-mas { background: color-mix(in oklab, var(--info-500) 6%, transparent); }
            .sp-fila.sp-abierta { background: color-mix(in oklab, var(--primary-500) 6%, transparent); }

            /* Fila corta: lo que sale, nombre, cambio y lo que queda. Al tocarla se abre. */
            .sp-corta { display: flex; align-items: center; gap: 0.625rem; width: 100%; padding: 0.6rem 0.875rem; text-align: start; cursor: pointer; }
            .sp-corta:focus-visible { outline: 2px solid var(--primary-600); outline-offset: -2px; }
            .sp-cants { flex-shrink: 0; display: grid; gap: 0.2rem; min-width: 3.6rem; }
            .sp-cant { padding: 0.25rem 0.4rem; border-radius: 0.5rem; text-align: center; font-size: 0.8rem; font-weight: 800; letter-spacing: 0.02em; line-height: 1.2; white-space: nowrap; font-variant-numeric: tabular-nums; color: var(--primary-700); background: color-mix(in oklab, var(--primary-500) 14%, transparent); outline: 1px solid color-mix(in oklab, var(--primary-500) 45%, transparent); }
            .dark .sp-cant { color: var(--primary-200); }
            .sp-cant.sp-cant-caja, .dark .sp-cant.sp-cant-caja { color: #fff; background: var(--primary-600); outline-color: var(--primary-600); }
            .sp-cant.sp-cant-cero, .dark .sp-cant.sp-cant-cero { color: var(--sp-tenue); background: transparent; outline: 1px dashed var(--sp-borde); }
            .sp-corta-texto { flex: 1 1 auto; min-width: 0; display: grid; gap: 0.1rem; }
            .sp-corta-nombre { font-size: 0.85rem; font-weight: 600; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
            .sp-fila-nada .sp-corta-nombre { text-decoration: line-through; color: var(--sp-tenue); }
            .sp-corta-meta { font-size: 0.72rem; color: var(--sp-tenue); font-variant-numeric: tabular-nums; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .sp-corta-lado { flex-shrink: 0; display: grid; justify-items: end; gap: 0.2rem; text-align: end; }
            .sp-chip { padding: 0.1rem 0.45rem; border-radius: 0.375rem; font-size: 0.7rem; font-weight: 700; white-space: nowrap; font-variant-numeric: tabular-nums; color: var(--warning-700); background: color-mix(in oklab, var(--warning-500) 14%, transparent); }
            .dark .sp-chip { color: var(--warning-300); }
            .sp-chip.sp-chip-mas { color: var(--info-700); background: color-mix(in oklab, var(--info-500) 14%, transparent); }
            .dark .sp-chip.sp-chip-mas { color: var(--info-300); }
            .sp-chip.sp-chip-nada { color: var(--danger-700); background: color-mix(in oklab, var(--danger-500) 14%, transparent); }
            .dark .sp-chip.sp-chip-nada { color: var(--danger-300); }
            .sp-corta-quedan { font-size: 0.7rem; color: var(--sp-tenue); white-space: nowrap; font-variant-numeric: tabular-nums; }
            .sp-corta-quedan.sp-neg { font-weight: 700; color: var(--danger-600); }
            .dark .sp-corta-quedan.sp-neg { color: var(--danger-400); }
            .sp-flecha { flex-shrink: 0; width: 1rem; height: 1rem; color: var(--sp-tenue); transition: transform 0.15s ease; }
            .sp-abierta .sp-flecha { transform: rotate(180deg); }

            /* Fila abierta. */
            .sp-detalle { display: grid; gap: 0.65rem; padding: 0 0.875rem 0.875rem; }
            .sp-meta { display: flex; flex-wrap: wrap; gap: 0.1rem 0.65rem; font-size: 0.78rem; color: var(--sp-tenue); }
            .sp-meta strong { font-weight: 700; color: var(--gray-700); }
            .dark .sp-meta strong { color: var(--gray-200); }

            /* En bodega → este pedido → quedan */
            .sp-bodega { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); border-radius: 0.5rem; background: var(--sp-suave); outline: 1px solid var(--sp-borde); }
            .sp-bodega.sp-bodega-sola { grid-template-columns: minmax(0, 1fr); }
            .sp-dato { min-width: 0; display: grid; gap: 0.05rem; padding: 0.35rem 0.55rem; }
            .sp-dato + .sp-dato { border-inline-start: 1px solid var(--sp-borde); }
            .sp-dato span { font-size: 0.62rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; color: var(--sp-tenue); }
            .sp-dato strong { font-size: 0.8rem; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .sp-dato.sp-sale strong { color: var(--primary-600); }
            .dark .sp-dato.sp-sale strong { color: var(--primary-400); }
            .sp-dato.sp-regresa strong { color: var(--success-600); }
            .dark .sp-dato.sp-regresa strong { color: var(--success-400); }
            .sp-dato.sp-negativo strong { color: var(--danger-600); }
            .dark .sp-dato.sp-negativo strong { color: var(--danger-400); }
            .sp-dato.sp-justo strong { color: var(--warning-600); }
            .dark .sp-dato.sp-justo strong { color: var(--warning-400); }

            .sp-estado { font-size: 0.78rem; font-weight: 700; color: var(--warning-600); }
            .dark .sp-estado { color: var(--warning-400); }
            .sp-estado.sp-estado-nada { color: var(--danger-600); }
            .dark .sp-estado.sp-estado-nada { color: var(--danger-400); }
            .sp-estado.sp-estado-mas { color: var(--info-600); }
            .dark .sp-estado.sp-estado-mas { color: var(--info-400); }
            .sp-no-alcanza { font-size: 0.75rem; font-weight: 600; color: var(--danger-600); }
            .dark .sp-no-alcanza { color: var(--danger-400); }

            /* Cajas y unidades, cada una con su − y + (como en la preventa). */
            .sp-controles { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 0.6rem 1rem; }
            .sp-cantidad { display: grid; gap: 0.25rem; }
            .sp-cantidad-etiqueta { display: flex; align-items: baseline; gap: 0.4rem; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase; color: var(--sp-tenue); }
            .sp-cantidad-etiqueta em { font-style: normal; font-weight: 600; text-transform: none; letter-spacing: 0; }
            .sp-stepper { display: inline-flex; align-items: center; border-radius: 0.5rem; background: #fff; outline: 1px solid rgb(3 7 18 / 0.12); }
            .dark .sp-stepper { background: rgb(255 255 255 / 0.03); outline-color: rgb(255 255 255 / 0.15); }
            .sp-stepper:focus-within { outline: 2px solid var(--primary-600); }
            .sp-stepper.sp-stepper-caja { outline-color: color-mix(in oklab, var(--primary-500) 55%, transparent); }
            .sp-paso { display: grid; place-items: center; width: 2.5rem; height: 2.5rem; color: var(--gray-600); }
            .dark .sp-paso { color: var(--gray-300); }
            .sp-paso:disabled { opacity: 0.3; cursor: not-allowed; }
            .sp-paso svg { width: 1.1rem; height: 1.1rem; }
            .sp-stepper input { width: 3.25rem; padding: 0; border: 0; background: transparent; text-align: center; font-size: 1rem; font-weight: 700; font-variant-numeric: tabular-nums; -moz-appearance: textfield; }
            .sp-stepper input:focus { outline: none; box-shadow: none; }
            .sp-stepper input::-webkit-outer-spin-button, .sp-stepper input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

            .sp-acciones { margin-inline-start: auto; display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 0.4rem 0.75rem; }
            .sp-boton { min-width: 5.25rem; padding: 0.5rem 0.75rem; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 600; white-space: nowrap; }
            .sp-no { color: var(--danger-600); outline: 1px solid color-mix(in oklab, var(--danger-500) 40%, transparent); }
            .sp-no:hover { background: color-mix(in oklab, var(--danger-500) 10%, transparent); }
            .dark .sp-no { color: var(--danger-400); }
            .sp-si { color: var(--success-700); background: color-mix(in oklab, var(--success-500) 12%, transparent); outline: 1px solid color-mix(in oklab, var(--success-500) 45%, transparent); }
            .dark .sp-si { color: var(--success-400); }
            .sp-enlace { font-size: 0.78rem; font-weight: 600; color: var(--primary-600); }
            .sp-enlace.sp-subrayado { text-decoration: underline; text-underline-offset: 2px; }
            .dark .sp-enlace { color: var(--primary-400); }

            /* Resumen de lo que no va completo y de lo que va de más, antes de confirmar. */
            .sp-incompletas { display: grid; gap: 0.3rem; padding: 0.7rem 0.875rem; border-radius: 0.75rem; font-size: 0.8rem; line-height: 1.4; color: var(--warning-800); background: color-mix(in oklab, var(--warning-500) 10%, transparent); outline: 1px solid color-mix(in oklab, var(--warning-500) 40%, transparent); }
            .dark .sp-incompletas { color: var(--warning-300); }
            .sp-incompletas > strong { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; }
            .sp-incompletas ul { display: grid; gap: 0.2rem; margin: 0; padding: 0; }
            .sp-incompletas li { list-style: none; overflow-wrap: anywhere; }
            .sp-incompletas b { font-weight: 700; }
            .sp-incompletas.sp-de-mas { color: var(--info-800); background: color-mix(in oklab, var(--info-500) 10%, transparent); outline-color: color-mix(in oklab, var(--info-500) 40%, transparent); }
            .dark .sp-incompletas.sp-de-mas { color: var(--info-300); }

            /* Teléfono: un poco menos de relleno. */
            @media (max-width: 639.98px) {
                .sp-corta { padding: 0.55rem 0.75rem; gap: 0.5rem; }
                .sp-detalle { padding: 0 0.75rem 0.8rem; }
            }
        </style>
    @endonce

    <div
        wire:ignore
        wire:key="salida-{{ $clave }}"
        class="sp"
        x-data="{
            filas: $wire.$entangle(@js($statePath)),
            paraValidar: @js($esParaValidar()),
            maximo: @js(\App\Domain\Pedidos\AjustarLineas::MAXIMO),
            abierta: null,
            filtro: 'todos',

            init() {
                if (! Array.isArray(this.filas)) {
                    this.filas = Object.values(this.filas ?? {})
                }

                {{-- Un solo producto: ya abierto. --}}
                const grupos = this.grupos()

                if (grupos.length === 1) {
                    this.abierta = grupos[0].id
                }
            },

            n(valor) {
                const numero = parseInt(valor, 10)

                return Number.isFinite(numero) ? numero : 0
            },

            {{-- De 0 en adelante: menos de lo pedido si no alcanzó, más si el cliente pidió más. --}}
            poner(fila, valor) {
                fila.sale = Math.min(Math.max(this.n(valor), 0), this.maximo)
            },

            mover(fila, paso) {
                this.poner(fila, this.n(fila.sale) + paso)
            },

            {{-- Un producto: su caja y su unidad (la que no venía en el pedido, sin id). --}}
            grupos() {
                const orden = []
                const porProducto = {}

                for (const fila of this.filas) {
                    const id = this.n(fila.producto_id)

                    if (! porProducto[id]) {
                        porProducto[id] = { id, info: fila, caja: null, unidad: null }
                        orden.push(porProducto[id])
                    }

                    porProducto[id][fila.es_caja ? 'caja' : 'unidad'] = fila
                }

                return orden
            },

            gruposVisibles() {
                if (this.filtro === 'cambios') return this.grupos().filter((grupo) => this.difiere(grupo))
                if (this.filtro === 'alcance') return this.grupos().filter((grupo) => this.noAlcanza(grupo))

                return this.grupos()
            },

            abrir(grupo) {
                this.abierta = this.abierta === grupo.id ? null : grupo.id
            },

            filasDe(grupo) {
                return [grupo.caja, grupo.unidad].filter(Boolean)
            },

            {{-- "2 CJ + 5 UN", igual que Existencia::enCajas. --}}
            enCajas(unidades, porCaja) {
                unidades = this.n(unidades)
                porCaja = Math.max(1, this.n(porCaja))

                if (unidades === 0) {
                    return '0'
                }

                const signo = unidades < 0 ? '-' : ''
                const resto = Math.abs(unidades)

                if (porCaja <= 1) {
                    return signo + resto + ' UN'
                }

                const partes = []
                const cajas = Math.floor(resto / porCaja)
                const sueltas = resto % porCaja

                if (cajas > 0) partes.push(cajas + ' CJ')
                if (sueltas > 0) partes.push(sueltas + ' UN')

                return signo + partes.join(' + ')
            },

            sigla(fila) {
                return fila.es_caja ? 'CJ' : 'UN'
            },

            {{-- "1 caja", "3 cajas", "1 unidad", "5 unidades". --}}
            palabras(cantidad, fila) {
                cantidad = this.n(cantidad)

                return cantidad + ' ' + (fila.es_caja ? (cantidad === 1 ? 'caja' : 'cajas') : (cantidad === 1 ? 'unidad' : 'unidades'))
            },

            {{-- "1 caja y 3 unidades". --}}
            pidio(grupo) {
                return this.filasDe(grupo)
                    .filter((fila) => this.n(fila.cantidad) > 0)
                    .map((fila) => this.palabras(fila.cantidad, fila))
                    .join(' y ')
            },

            {{-- "2 CJ + 3 UN" (en la fila corta). --}}
            pidioCorto(grupo) {
                return this.filasDe(grupo)
                    .filter((fila) => this.n(fila.cantidad) > 0)
                    .map((fila) => this.n(fila.cantidad) + ' ' + this.sigla(fila))
                    .join(' + ')
            },

            total(grupo) {
                return this.filasDe(grupo).reduce((suma, fila) => suma + this.n(fila.sale), 0)
            },

            difiere(grupo) {
                return this.filasDe(grupo).some((fila) => this.n(fila.sale) !== this.n(fila.cantidad))
            },

            noHay(grupo) {
                this.filasDe(grupo).forEach((fila) => fila.sale = 0)
            },

            comoSePidio(grupo) {
                this.filasDe(grupo).forEach((fila) => fila.sale = this.n(fila.cantidad))
            },

            {{-- A la derecha de la fila corta: "−1 CJ", "+3 UN", "No sale"; vacío si sale como se pidió. --}}
            cambioCorto(grupo) {
                if (! this.difiere(grupo)) return ''
                if (this.total(grupo) === 0) return 'No sale'

                return this.filasDe(grupo)
                    .filter((fila) => this.n(fila.sale) !== this.n(fila.cantidad))
                    .map((fila) => {
                        const diferencia = this.n(fila.sale) - this.n(fila.cantidad)

                        return (diferencia > 0 ? '+' : '−') + Math.abs(diferencia) + ' ' + this.sigla(fila)
                    })
                    .join(' ')
            },

            claseCambio(grupo) {
                if (this.total(grupo) === 0) return 'sp-chip-nada'

                return this.filasDe(grupo).some((fila) => this.n(fila.sale) < this.n(fila.cantidad)) ? '' : 'sp-chip-mas'
            },

            {{-- Unidades que saca este pedido del producto (cajas y unidades). --}}
            saleDelProducto(grupo) {
                return this.filasDe(grupo).reduce((suma, fila) => suma + this.n(fila.sale) * Math.max(1, this.n(fila.por_unidad)), 0)
            },

            {{-- Lo que cambia en la bodega: al validar sale todo; al corregir, la diferencia con lo que ya salió. --}}
            cambio(grupo) {
                return this.saleDelProducto(grupo) - (this.paraValidar ? 0 : this.n(grupo.info.salio_unidades))
            },

            quedan(grupo) {
                return this.n(grupo.info.hay_unidades) - (grupo.info.mueve ? this.cambio(grupo) : 0)
            },

            noAlcanza(grupo) {
                return grupo.info.mueve && this.quedan(grupo) < 0
            },

            {{-- Como PedidoItem: "No sale: pidió 2 cajas", "No va completo: falta 1 caja de 3", "Va de más: …"; vacío si va justo. --}}
            estado(fila) {
                const sale = this.n(fila.sale)
                const pidio = this.n(fila.cantidad)

                if (pidio === 0) {
                    return sale > 0 ? 'Va de más: ' + this.palabras(sale, fila) + ' (no lo pidió en ' + (fila.es_caja ? 'cajas' : 'unidades') + ')' : ''
                }

                if (sale === 0) {
                    return 'No sale: pidió ' + this.palabras(pidio, fila)
                }

                if (sale > pidio) {
                    return 'Va de más: ' + this.palabras(sale - pidio, fila) + ' más de lo pedido (pidió ' + pidio + ')'
                }

                if (sale === pidio) {
                    return ''
                }

                const falta = pidio - sale

                return 'No va completo: ' + (falta === 1 ? 'falta ' : 'faltan ') + this.palabras(falta, fila) + ' de ' + pidio
            },

            claseEstado(fila) {
                const sale = this.n(fila.sale)

                return sale > this.n(fila.cantidad) ? 'sp-estado-mas' : (sale === 0 ? 'sp-estado-nada' : '')
            },

            {{-- Para el resumen de abajo: sin "No va completo:" / "Va de más:" adelante. --}}
            estadoCorto(fila) {
                return this.estado(fila).replace(/^(No va completo|Va de más): /, '').replace(/^No sale/, 'no sale')
            },

            incompletas() {
                return this.filas.filter((fila) => this.n(fila.cantidad) > 0 && this.n(fila.sale) < this.n(fila.cantidad))
            },

            deMas() {
                return this.filas.filter((fila) => this.n(fila.sale) > this.n(fila.cantidad))
            },

            claseGrupo(grupo) {
                if (this.abierta === grupo.id) return 'sp-abierta'
                if (this.total(grupo) === 0) return 'sp-fila-nada'
                if (this.filasDe(grupo).some((fila) => this.n(fila.cantidad) > 0 && this.n(fila.sale) < this.n(fila.cantidad))) return 'sp-fila-parcial'
                if (this.filasDe(grupo).some((fila) => this.n(fila.sale) > this.n(fila.cantidad))) return 'sp-fila-mas'

                return ''
            },

            {{-- Arriba a la derecha. --}}
            textoResumen() {
                const faltan = this.incompletas().length
                const mas = this.deMas().length
                const partes = []

                if (faltan > 0) partes.push(faltan === 1 ? '1 no va completo' : faltan + ' no van completos')
                if (mas > 0) partes.push(mas === 1 ? '1 va de más' : mas + ' van de más')

                return partes.length === 0 ? 'Sale como se pidió' : partes.join(' · ')
            },
        }"
    >
        <div class="sp-resumen">
            <span x-text="grupos().length === 1 ? '1 producto' : grupos().length + ' productos'">{{ $productos === 1 ? '1 producto' : $productos.' productos' }}</span>
            <span
                x-bind:class="incompletas().length === 0 && deMas().length === 0 ? 'sp-resumen-ok' : 'sp-resumen-falta'"
                x-text="textoResumen()"
            ></span>
        </div>

        {{-- Con muchos productos: ver solo los que cambiaron o los que no alcanzan. --}}
        <div class="sp-filtros" x-show="grupos().length > 1">
            <button type="button" class="sp-filtro" x-bind:class="{ 'sp-activo': filtro === 'todos' }" x-on:click="filtro = 'todos'">Todos <b x-text="grupos().length"></b></button>
            <button type="button" class="sp-filtro" x-bind:class="{ 'sp-activo': filtro === 'cambios' }" x-on:click="filtro = 'cambios'">Con cambios <b x-text="grupos().filter((grupo) => difiere(grupo)).length"></b></button>
            <button type="button" class="sp-filtro" x-show="filtro === 'alcance' || grupos().some((grupo) => noAlcanza(grupo))" x-bind:class="{ 'sp-activo': filtro === 'alcance' }" x-on:click="filtro = 'alcance'">No alcanza <b x-text="grupos().filter((grupo) => noAlcanza(grupo)).length"></b></button>
        </div>

        <ul class="sp-lista">
            <template x-for="grupo in gruposVisibles()" x-bind:key="grupo.id">
                <li class="sp-fila" x-bind:class="claseGrupo(grupo)">
                    {{-- Fila corta --}}
                    <button type="button" class="sp-corta" x-on:click="abrir(grupo)" x-bind:aria-expanded="abierta === grupo.id">
                        <span class="sp-cants">
                            <template x-for="fila in filasDe(grupo).filter((fila) => n(fila.sale) > 0)" x-bind:key="'cant-' + sigla(fila)">
                                <span class="sp-cant" x-bind:class="{ 'sp-cant-caja': fila.es_caja }" x-text="n(fila.sale) + ' ' + sigla(fila)"></span>
                            </template>
                            <span class="sp-cant sp-cant-cero" x-show="total(grupo) === 0">0</span>
                        </span>

                        <span class="sp-corta-texto">
                            <span class="sp-corta-nombre" x-text="grupo.info.descripcion ?? grupo.info.producto"></span>
                            <span class="sp-corta-meta" x-text="(grupo.info.codigo ? grupo.info.codigo + ' · ' : '') + 'pidió ' + pidioCorto(grupo)"></span>
                        </span>

                        <span class="sp-corta-lado">
                            <span class="sp-chip" x-show="cambioCorto(grupo) !== ''" x-bind:class="claseCambio(grupo)" x-text="cambioCorto(grupo)"></span>
                            <span
                                class="sp-corta-quedan"
                                x-show="grupo.info.mueve"
                                x-bind:class="{ 'sp-neg': noAlcanza(grupo) }"
                                x-text="noAlcanza(grupo) ? 'no alcanza −' + enCajas(-quedan(grupo), grupo.info.por_caja) : 'quedan ' + enCajas(quedan(grupo), grupo.info.por_caja)"
                            ></span>
                        </span>

                        <svg class="sp-flecha" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                    </button>

                    {{-- Fila abierta --}}
                    <div class="sp-detalle" x-show="abierta === grupo.id">
                        <div class="sp-meta">
                            <span>Pidió <strong x-text="pidio(grupo)"></strong></span>
                        </div>

                        {{-- Del producto en la bodega del pedido. --}}
                        <div class="sp-bodega" x-bind:class="{ 'sp-bodega-sola': ! grupo.info.mueve }">
                            <div class="sp-dato">
                                <span>En bodega</span>
                                <strong x-text="enCajas(grupo.info.hay_unidades, grupo.info.por_caja)"></strong>
                            </div>
                            <div class="sp-dato" x-show="grupo.info.mueve" x-bind:class="cambio(grupo) < 0 ? 'sp-regresa' : 'sp-sale'">
                                <span x-text="paraValidar ? 'Este pedido' : (cambio(grupo) < 0 ? 'Regresan' : (cambio(grupo) > 0 ? 'Salen más' : 'Sin cambio'))"></span>
                                <strong x-text="(cambio(grupo) > 0 ? '−' : (cambio(grupo) < 0 ? '+' : '')) + enCajas(Math.abs(cambio(grupo)), grupo.info.por_caja)"></strong>
                            </div>
                            <div class="sp-dato" x-show="grupo.info.mueve" x-bind:class="{ 'sp-negativo': quedan(grupo) < 0, 'sp-justo': quedan(grupo) === 0 }">
                                <span>Quedan</span>
                                <strong x-text="enCajas(quedan(grupo), grupo.info.por_caja)"></strong>
                            </div>
                        </div>

                        <div class="sp-no-alcanza" x-show="noAlcanza(grupo)" x-text="'No alcanza: faltan ' + enCajas(-quedan(grupo), grupo.info.por_caja) + ' en la bodega'"></div>

                        <template x-for="fila in filasDe(grupo)" x-bind:key="'estado-' + sigla(fila)">
                            <div class="sp-estado" x-bind:class="claseEstado(fila)" x-show="estado(fila) !== ''" x-text="estado(fila)"></div>
                        </template>

                        <div class="sp-controles">
                            <template x-for="fila in filasDe(grupo)" x-bind:key="'cantidad-' + sigla(fila)">
                                <div class="sp-cantidad">
                                    <span class="sp-cantidad-etiqueta">
                                        <span x-text="fila.es_caja ? 'Cajas de ' + fila.por_caja : 'Unidades'"></span>
                                        <em x-text="n(fila.cantidad) > 0 ? 'pidió ' + n(fila.cantidad) : 'no pidió'"></em>
                                    </span>
                                    <div class="sp-stepper" x-bind:class="{ 'sp-stepper-caja': fila.es_caja }">
                                        <button type="button" class="sp-paso" x-bind:aria-label="fila.es_caja ? 'Una caja menos' : 'Una unidad menos'" x-on:click="mover(fila, -1)" x-bind:disabled="n(fila.sale) <= 0">{{ svg('heroicon-m-minus') }}</button>
                                        <input
                                            type="number"
                                            inputmode="numeric"
                                            min="0"
                                            x-bind:max="maximo"
                                            x-bind:aria-label="fila.es_caja ? 'Cajas que salen' : 'Unidades que salen'"
                                            x-bind:value="fila.sale"
                                            x-on:focus="$event.target.select()"
                                            x-on:change="poner(fila, $event.target.value); $event.target.value = fila.sale"
                                            x-on:keydown.enter.prevent="poner(fila, $event.target.value); $event.target.value = fila.sale; $event.target.blur()"
                                        >
                                        <button type="button" class="sp-paso" x-bind:aria-label="fila.es_caja ? 'Una caja más' : 'Una unidad más'" x-on:click="mover(fila, 1)" x-bind:disabled="n(fila.sale) >= maximo">{{ svg('heroicon-m-plus') }}</button>
                                    </div>
                                </div>
                            </template>

                            <div class="sp-acciones">
                                <button type="button" class="sp-enlace sp-subrayado" x-show="total(grupo) > 0 && difiere(grupo)" x-on:click="comoSePidio(grupo)">Volver a lo pedido</button>
                                <button type="button" class="sp-boton sp-no" x-show="total(grupo) > 0" x-on:click="noHay(grupo)">No hay</button>
                                <button type="button" class="sp-boton sp-si" x-show="total(grupo) === 0" x-on:click="comoSePidio(grupo)">Sí hay</button>
                                <button type="button" class="sp-enlace" x-show="grupos().length > 1" x-on:click="abierta = null">Listo</button>
                            </div>
                        </div>
                    </div>
                </li>
            </template>

            <li class="sp-vacio" x-show="gruposVisibles().length === 0" x-text="filtro === 'alcance' ? 'Todo alcanza en la bodega.' : 'Todo sale como se pidió.'"></li>
        </ul>

        <div class="sp-incompletas" x-show="incompletas().length > 0" x-cloak>
            <strong>No va completo</strong>
            <ul>
                <template x-for="fila in incompletas()" x-bind:key="'falta-' + fila.producto_id + '-' + sigla(fila)">
                    <li><b x-text="(fila.es_caja ? 'Caja' : 'Unidad') + ' · ' + (fila.descripcion ?? fila.producto)"></b> — <span x-text="estadoCorto(fila)"></span></li>
                </template>
            </ul>
        </div>

        <div class="sp-incompletas sp-de-mas" x-show="deMas().length > 0" x-cloak>
            <strong>Va de más</strong>
            <ul>
                <template x-for="fila in deMas()" x-bind:key="'mas-' + fila.producto_id + '-' + sigla(fila)">
                    <li><b x-text="(fila.es_caja ? 'Caja' : 'Unidad') + ' · ' + (fila.descripcion ?? fila.producto)"></b> — <span x-text="estadoCorto(fila)"></span></li>
                </template>
            </ul>
        </div>
    </div>
</x-dynamic-component>
