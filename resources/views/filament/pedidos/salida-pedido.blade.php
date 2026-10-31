{{--
    Validar y Corregir: cuánto sale de cada línea (App\Filament\Schemas\Components\SalidaDePedido).

    Una fila por línea: si es CAJA o UNIDAD, el nombre, lo que pidió y, del
    producto, cuánto hay en la bodega, cuánto saca este pedido y cuánto
    quedaría (se recalcula al tocar). A un lado − Sale + (puede ser menos o
    más de lo pedido) y "No hay" (la deja en 0) o "Sí hay" (la regresa a lo
    pedido). Lo que no va completo ("falta 1 caja de 3") o va de más ("1
    unidad más de lo pedido") se dice en la fila y en un resumen abajo.

    Todo pasa en el teléfono (Alpine); al servidor solo va al confirmar. Los
    estilos van aquí mismo, sin compilar.
--}}
@php
    $statePath = $getStatePath();
    $filas = is_array($getState()) ? array_values($getState()) : [];
    $clave = md5((string) json_encode(array_column($filas, 'id')));
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @once
        <style>
            .sp { --sp-tenue: var(--gray-500); --sp-linea: var(--gray-100); --sp-borde: rgb(3 7 18 / 0.08); --sp-suave: var(--gray-50); display: grid; gap: 0.5rem; font-size: 0.875rem; }
            .dark .sp { --sp-tenue: var(--gray-400); --sp-linea: rgb(255 255 255 / 0.06); --sp-borde: rgb(255 255 255 / 0.1); --sp-suave: rgb(255 255 255 / 0.03); }

            .sp-resumen { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; font-size: 0.8rem; font-weight: 600; color: var(--sp-tenue); }
            .sp-resumen-ok { color: var(--success-600); }
            .sp-resumen-falta { color: var(--warning-600); }
            .dark .sp-resumen-ok { color: var(--success-400); }
            .dark .sp-resumen-falta { color: var(--warning-400); }

            .sp-lista { display: grid; margin: 0; padding: 0; list-style: none; border-radius: 0.75rem; outline: 1px solid var(--sp-borde); overflow: hidden; }
            .sp-fila { display: grid; grid-template-columns: minmax(0, 1fr); gap: 0.6rem; padding: 0.75rem 0.875rem; border-bottom: 1px solid var(--sp-linea); transition: background-color 0.2s ease; }
            .sp-fila:last-child { border-bottom: 0; }
            .sp-fila.sp-fila-nada { background: color-mix(in oklab, var(--danger-500) 6%, transparent); }
            .sp-fila.sp-fila-parcial { background: color-mix(in oklab, var(--warning-500) 5%, transparent); }
            .sp-fila.sp-fila-mas { background: color-mix(in oklab, var(--info-500) 6%, transparent); }
            /* Pantalla grande: el texto a la izquierda y Sale a la derecha, en un renglón. */
            @media (min-width: 640px) {
                .sp-fila { grid-template-columns: minmax(0, 1fr) auto; align-items: center; column-gap: 1rem; }
            }

            .sp-texto { min-width: 0; display: grid; gap: 0.3rem; }
            .sp-cabeza { display: flex; align-items: flex-start; gap: 0.5rem; }
            /* CAJA ×48 (lleno) o UNIDAD (borde): que no se confundan. */
            .sp-unidad { flex-shrink: 0; margin-top: 0.05rem; padding: 0.1rem 0.4rem; border-radius: 0.375rem; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.03em; line-height: 1.4; white-space: nowrap; color: var(--primary-700); outline: 1px solid color-mix(in oklab, var(--primary-500) 50%, transparent); background: color-mix(in oklab, var(--primary-500) 10%, transparent); }
            .dark .sp-unidad { color: var(--primary-200); }
            .sp-unidad.sp-unidad-caja, .dark .sp-unidad.sp-unidad-caja { color: #fff; background: var(--primary-600); outline-color: var(--primary-600); }
            .sp-nombre { min-width: 0; font-weight: 600; line-height: 1.3; overflow-wrap: anywhere; }
            .sp-fila-nada .sp-nombre { text-decoration: line-through; color: var(--sp-tenue); }
            .sp-meta { display: flex; flex-wrap: wrap; gap: 0.1rem 0.65rem; font-size: 0.78rem; color: var(--sp-tenue); font-variant-numeric: tabular-nums; }
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
            .sp-fila-nada .sp-estado { color: var(--danger-600); }
            .dark .sp-fila-nada .sp-estado { color: var(--danger-400); }
            .sp-fila-mas .sp-estado { color: var(--info-600); }
            .dark .sp-fila-mas .sp-estado { color: var(--info-400); }
            .sp-volver { margin-inline-start: 0.4rem; font-size: 0.75rem; font-weight: 600; color: var(--primary-600); text-decoration: underline; text-underline-offset: 2px; }
            .dark .sp-volver { color: var(--primary-400); }
            .sp-no-alcanza { font-size: 0.75rem; font-weight: 600; color: var(--danger-600); }
            .dark .sp-no-alcanza { color: var(--danger-400); }

            /* − Sale + */
            .sp-control { display: flex; align-items: center; gap: 0.5rem; }
            .sp-stepper { display: inline-flex; align-items: center; border-radius: 0.5rem; background: #fff; outline: 1px solid rgb(3 7 18 / 0.12); }
            .dark .sp-stepper { background: rgb(255 255 255 / 0.03); outline-color: rgb(255 255 255 / 0.15); }
            .sp-stepper:focus-within { outline: 2px solid var(--primary-600); }
            .sp-paso { display: grid; place-items: center; width: 2.5rem; height: 2.5rem; color: var(--gray-600); }
            .dark .sp-paso { color: var(--gray-300); }
            .sp-paso:disabled { opacity: 0.3; cursor: not-allowed; }
            .sp-paso svg { width: 1.1rem; height: 1.1rem; }
            .sp-stepper input { width: 3.25rem; padding: 0; border: 0; background: transparent; text-align: center; font-size: 1rem; font-weight: 700; font-variant-numeric: tabular-nums; -moz-appearance: textfield; }
            .sp-stepper input:focus { outline: none; box-shadow: none; }
            .sp-stepper input::-webkit-outer-spin-button, .sp-stepper input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
            .sp-sigla { min-width: 3.5rem; font-size: 0.75rem; font-weight: 700; color: var(--sp-tenue); }

            .sp-boton { margin-inline-start: auto; min-width: 5.25rem; padding: 0.5rem 0.75rem; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 600; white-space: nowrap; }
            .sp-no { color: var(--danger-600); outline: 1px solid color-mix(in oklab, var(--danger-500) 40%, transparent); }
            .sp-no:hover { background: color-mix(in oklab, var(--danger-500) 10%, transparent); }
            .dark .sp-no { color: var(--danger-400); }
            .sp-si { color: var(--success-700); background: color-mix(in oklab, var(--success-500) 12%, transparent); outline: 1px solid color-mix(in oklab, var(--success-500) 45%, transparent); }
            .dark .sp-si { color: var(--success-400); }
            @media (min-width: 640px) {
                .sp-boton { margin-inline-start: 0.25rem; }
            }

            /* Resumen de lo que no va completo, antes de confirmar. */
            .sp-incompletas { display: grid; gap: 0.3rem; padding: 0.7rem 0.875rem; border-radius: 0.75rem; font-size: 0.8rem; line-height: 1.4; color: var(--warning-800); background: color-mix(in oklab, var(--warning-500) 10%, transparent); outline: 1px solid color-mix(in oklab, var(--warning-500) 40%, transparent); }
            .dark .sp-incompletas { color: var(--warning-300); }
            .sp-incompletas > strong { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; }
            .sp-incompletas li { list-style: none; overflow-wrap: anywhere; }
            .sp-incompletas ul { display: grid; gap: 0.2rem; margin: 0; padding: 0; }
            .sp-incompletas b { font-weight: 700; }
            .sp-incompletas.sp-de-mas { color: var(--info-800); background: color-mix(in oklab, var(--info-500) 10%, transparent); outline-color: color-mix(in oklab, var(--info-500) 40%, transparent); }
            .dark .sp-incompletas.sp-de-mas { color: var(--info-300); }
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

            init() {
                if (! Array.isArray(this.filas)) {
                    this.filas = Object.values(this.filas ?? {})
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

            {{-- "1 caja", "3 cajas", "1 unidad", "5 unidades". --}}
            palabras(cantidad, fila) {
                cantidad = this.n(cantidad)

                return cantidad + ' ' + (fila.es_caja ? (cantidad === 1 ? 'caja' : 'cajas') : (cantidad === 1 ? 'unidad' : 'unidades'))
            },

            {{-- Unidades que saca este pedido de ese producto (si viene en caja y en unidad, las dos). --}}
            saleDelProducto(fila) {
                return this.filas
                    .filter((otra) => this.n(otra.producto_id) === this.n(fila.producto_id))
                    .reduce((total, otra) => total + this.n(otra.sale) * Math.max(1, this.n(otra.por_unidad)), 0)
            },

            {{-- Lo que cambia en la bodega: al validar sale todo; al corregir, la diferencia con lo que ya salió. --}}
            cambio(fila) {
                return this.saleDelProducto(fila) - (this.paraValidar ? 0 : this.n(fila.salio_unidades))
            },

            quedan(fila) {
                return this.n(fila.hay_unidades) - (fila.mueve ? this.cambio(fila) : 0)
            },

            {{-- Como PedidoItem: "No sale: pidió 2 cajas", "No va completo: falta 1 caja de 3", "Va de más: 1 unidad más de lo pedido (pidió 2)"; vacío si va justo. --}}
            estado(fila) {
                const sale = this.n(fila.sale)
                const pidio = this.n(fila.cantidad)

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

            {{-- Para el resumen de abajo: sin "No va completo:" / "Va de más:" adelante. --}}
            estadoCorto(fila) {
                return this.estado(fila).replace(/^(No va completo|Va de más): /, '').replace(/^No sale/, 'no sale')
            },

            incompletas() {
                return this.filas.filter((fila) => this.n(fila.sale) < this.n(fila.cantidad))
            },

            deMas() {
                return this.filas.filter((fila) => this.n(fila.sale) > this.n(fila.cantidad))
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
            <span x-text="filas.length === 1 ? '1 producto' : filas.length + ' productos'">{{ count($filas) === 1 ? '1 producto' : count($filas).' productos' }}</span>
            <span
                x-bind:class="incompletas().length === 0 && deMas().length === 0 ? 'sp-resumen-ok' : 'sp-resumen-falta'"
                x-text="textoResumen()"
            ></span>
        </div>

        <ul class="sp-lista">
            <template x-for="fila in filas" x-bind:key="fila.id">
                <li class="sp-fila" x-bind:class="{ 'sp-fila-nada': n(fila.sale) === 0, 'sp-fila-parcial': n(fila.sale) > 0 && n(fila.sale) < n(fila.cantidad), 'sp-fila-mas': n(fila.sale) > n(fila.cantidad) }">
                    <div class="sp-texto">
                        <div class="sp-cabeza">
                            <span class="sp-unidad" x-bind:class="{ 'sp-unidad-caja': fila.es_caja }" x-text="fila.es_caja ? 'CAJA ×' + fila.por_caja : 'UNIDAD'"></span>
                            <span class="sp-nombre" x-text="fila.descripcion ?? fila.producto"></span>
                        </div>

                        <div class="sp-meta">
                            <span x-show="fila.codigo" x-text="fila.codigo"></span>
                            <span>Pidió <strong x-text="palabras(fila.cantidad, fila)"></strong></span>
                        </div>

                        {{-- Del producto en la bodega del pedido. --}}
                        <div class="sp-bodega" x-bind:class="{ 'sp-bodega-sola': ! fila.mueve }">
                            <div class="sp-dato">
                                <span>En bodega</span>
                                <strong x-text="enCajas(fila.hay_unidades, fila.por_caja)"></strong>
                            </div>
                            <div class="sp-dato" x-show="fila.mueve" x-bind:class="cambio(fila) < 0 ? 'sp-regresa' : 'sp-sale'">
                                <span x-text="paraValidar ? 'Este pedido' : (cambio(fila) < 0 ? 'Regresan' : (cambio(fila) > 0 ? 'Salen más' : 'Sin cambio'))"></span>
                                <strong x-text="(cambio(fila) > 0 ? '−' : (cambio(fila) < 0 ? '+' : '')) + enCajas(Math.abs(cambio(fila)), fila.por_caja)"></strong>
                            </div>
                            <div class="sp-dato" x-show="fila.mueve" x-bind:class="{ 'sp-negativo': quedan(fila) < 0, 'sp-justo': quedan(fila) === 0 }">
                                <span>Quedan</span>
                                <strong x-text="enCajas(quedan(fila), fila.por_caja)"></strong>
                            </div>
                        </div>

                        <div class="sp-no-alcanza" x-show="fila.mueve && quedan(fila) < 0" x-text="'No alcanza: faltan ' + enCajas(-quedan(fila), fila.por_caja) + ' en la bodega'"></div>
                        <div class="sp-estado" x-show="estado(fila) !== ''">
                            <span x-text="estado(fila)"></span>
                            <button type="button" class="sp-volver" x-show="n(fila.sale) > 0" x-on:click="fila.sale = n(fila.cantidad)" x-text="'Volver a ' + n(fila.cantidad)"></button>
                        </div>
                    </div>

                    <div class="sp-control">
                        <div class="sp-stepper">
                            <button type="button" class="sp-paso" aria-label="Uno menos" x-on:click="mover(fila, -1)" x-bind:disabled="n(fila.sale) <= 0">{{ svg('heroicon-m-minus') }}</button>
                            <input
                                type="number"
                                inputmode="numeric"
                                min="0"
                                aria-label="Sale"
                                x-bind:max="maximo"
                                x-bind:value="fila.sale"
                                x-on:focus="$event.target.select()"
                                x-on:change="poner(fila, $event.target.value); $event.target.value = fila.sale"
                            >
                            <button type="button" class="sp-paso" aria-label="Uno más" x-on:click="mover(fila, 1)" x-bind:disabled="n(fila.sale) >= maximo">{{ svg('heroicon-m-plus') }}</button>
                        </div>
                        <span class="sp-sigla" x-text="fila.es_caja ? (n(fila.sale) === 1 ? 'caja' : 'cajas') : (n(fila.sale) === 1 ? 'unidad' : 'unidades')"></span>
                        <button type="button" class="sp-boton sp-no" x-show="n(fila.sale) > 0" x-on:click="fila.sale = 0">No hay</button>
                        <button type="button" class="sp-boton sp-si" x-show="n(fila.sale) === 0" x-on:click="fila.sale = n(fila.cantidad)">Sí hay</button>
                    </div>
                </li>
            </template>
        </ul>

        <div class="sp-incompletas" x-show="incompletas().length > 0" x-cloak>
            <strong>No va completo</strong>
            <ul>
                <template x-for="fila in incompletas()" x-bind:key="'falta-' + fila.id">
                    <li><b x-text="(fila.es_caja ? 'Caja' : 'Unidad') + ' · ' + (fila.descripcion ?? fila.producto)"></b> — <span x-text="estadoCorto(fila)"></span></li>
                </template>
            </ul>
        </div>

        <div class="sp-incompletas sp-de-mas" x-show="deMas().length > 0" x-cloak>
            <strong>Va de más</strong>
            <ul>
                <template x-for="fila in deMas()" x-bind:key="'mas-' + fila.id">
                    <li><b x-text="(fila.es_caja ? 'Caja' : 'Unidad') + ' · ' + (fila.descripcion ?? fila.producto)"></b> — <span x-text="estadoCorto(fila)"></span></li>
                </template>
            </ul>
        </div>
    </div>
</x-dynamic-component>
