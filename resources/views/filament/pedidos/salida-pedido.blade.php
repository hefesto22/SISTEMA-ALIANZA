{{--
    Validar y Corregir: cuánto sale de cada línea (App\Filament\Schemas\Components\SalidaDePedido).

    Una fila por línea: nombre, código, lo que pidió y lo que hay; a un lado
    − Sale + y "No hay" (la deja en 0) o "Sí hay" (la regresa a lo pedido).
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
            .sp { --sp-tenue: var(--gray-500); --sp-linea: var(--gray-100); --sp-borde: rgb(3 7 18 / 0.08); display: grid; gap: 0.5rem; font-size: 0.875rem; }
            .dark .sp { --sp-tenue: var(--gray-400); --sp-linea: rgb(255 255 255 / 0.06); --sp-borde: rgb(255 255 255 / 0.1); }

            .sp-resumen { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; font-size: 0.8rem; font-weight: 600; color: var(--sp-tenue); }
            .sp-resumen-ok { color: var(--success-600); }
            .sp-resumen-falta { color: var(--warning-600); }
            .dark .sp-resumen-ok { color: var(--success-400); }
            .dark .sp-resumen-falta { color: var(--warning-400); }

            .sp-lista { display: grid; margin: 0; padding: 0; list-style: none; border-radius: 0.75rem; outline: 1px solid var(--sp-borde); overflow: hidden; }
            .sp-fila { display: grid; grid-template-columns: minmax(0, 1fr); gap: 0.6rem; padding: 0.75rem 0.875rem; border-bottom: 1px solid var(--sp-linea); transition: background-color 0.2s ease; }
            .sp-fila:last-child { border-bottom: 0; }
            .sp-fila.sp-fila-nada { background: color-mix(in oklab, var(--danger-500) 6%, transparent); }
            /* Pantalla grande: el texto a la izquierda y Sale a la derecha, en un renglón. */
            @media (min-width: 640px) {
                .sp-fila { grid-template-columns: minmax(0, 1fr) auto; align-items: center; column-gap: 1rem; }
            }

            .sp-texto { min-width: 0; display: grid; gap: 0.15rem; }
            .sp-nombre { font-weight: 600; line-height: 1.3; overflow-wrap: anywhere; }
            .sp-fila-nada .sp-nombre { text-decoration: line-through; color: var(--sp-tenue); }
            .sp-meta { display: flex; flex-wrap: wrap; gap: 0.1rem 0.65rem; font-size: 0.78rem; color: var(--sp-tenue); font-variant-numeric: tabular-nums; }
            .sp-meta strong { font-weight: 700; color: var(--gray-700); }
            .dark .sp-meta strong { color: var(--gray-200); }
            .sp-alcanza { font-weight: 600; color: var(--success-600); }
            .sp-poco { font-weight: 600; color: var(--warning-600); }
            .sp-sin { font-weight: 600; color: var(--danger-600); }
            .dark .sp-alcanza { color: var(--success-400); }
            .dark .sp-poco { color: var(--warning-400); }
            .dark .sp-sin { color: var(--danger-400); }
            .sp-estado { font-size: 0.75rem; font-weight: 700; color: var(--warning-600); }
            .dark .sp-estado { color: var(--warning-400); }
            .sp-fila-nada .sp-estado { color: var(--danger-600); }
            .dark .sp-fila-nada .sp-estado { color: var(--danger-400); }

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
            .sp-sigla { min-width: 1.5rem; font-size: 0.75rem; font-weight: 700; color: var(--sp-tenue); }

            .sp-boton { margin-inline-start: auto; min-width: 5.25rem; padding: 0.5rem 0.75rem; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 600; white-space: nowrap; }
            .sp-no { color: var(--danger-600); outline: 1px solid color-mix(in oklab, var(--danger-500) 40%, transparent); }
            .sp-no:hover { background: color-mix(in oklab, var(--danger-500) 10%, transparent); }
            .dark .sp-no { color: var(--danger-400); }
            .sp-si { color: var(--success-700); background: color-mix(in oklab, var(--success-500) 12%, transparent); outline: 1px solid color-mix(in oklab, var(--success-500) 45%, transparent); }
            .dark .sp-si { color: var(--success-400); }
            @media (min-width: 640px) {
                .sp-boton { margin-inline-start: 0.25rem; }
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

            init() {
                if (! Array.isArray(this.filas)) {
                    this.filas = Object.values(this.filas ?? {})
                }
            },

            n(valor) {
                const numero = parseInt(valor, 10)

                return Number.isFinite(numero) ? numero : 0
            },

            poner(fila, valor) {
                fila.sale = Math.min(Math.max(this.n(valor), 0), this.n(fila.cantidad))
            },

            mover(fila, paso) {
                this.poner(fila, this.n(fila.sale) + paso)
            },

            salen() {
                return this.filas.filter((fila) => this.n(fila.sale) > 0).length
            },

            completo() {
                return this.filas.every((fila) => this.n(fila.sale) >= this.n(fila.cantidad))
            },

            {{-- Al validar: verde si alcanza para lo pedido, ámbar si no, rojo si no hay. --}}
            claseHay(fila) {
                if (! this.paraValidar) {
                    return ''
                }

                const hay = this.n(fila.hay_unidades)

                if (hay <= 0) {
                    return 'sp-sin'
                }

                return hay < this.n(fila.cantidad) * Math.max(1, this.n(fila.por_unidad)) ? 'sp-poco' : 'sp-alcanza'
            },
        }"
    >
        <div class="sp-resumen">
            <span x-text="filas.length === 1 ? '1 producto' : filas.length + ' productos'">{{ count($filas) === 1 ? '1 producto' : count($filas).' productos' }}</span>
            <span
                x-bind:class="completo() ? 'sp-resumen-ok' : 'sp-resumen-falta'"
                x-text="completo() ? 'Sale completo' : (salen() === 0 ? 'No sale nada' : 'Salen ' + salen() + ' de ' + filas.length)"
            ></span>
        </div>

        <ul class="sp-lista">
            <template x-for="fila in filas" x-bind:key="fila.id">
                <li class="sp-fila" x-bind:class="{ 'sp-fila-nada': n(fila.sale) === 0 }">
                    <div class="sp-texto">
                        <div class="sp-nombre" x-text="fila.descripcion ?? fila.producto"></div>
                        <div class="sp-meta">
                            <span x-show="fila.codigo" x-text="fila.codigo"></span>
                            <span>Pidió <strong x-text="fila.cantidad + ' ' + (fila.sigla ?? '')"></strong></span>
                            <span x-bind:class="claseHay(fila)" x-text="'Hay ' + fila.hay"></span>
                        </div>
                        <div
                            class="sp-estado"
                            x-show="n(fila.sale) < n(fila.cantidad)"
                            x-text="n(fila.sale) === 0 ? 'No sale' : 'Faltan ' + (n(fila.cantidad) - n(fila.sale)) + ' ' + (fila.sigla ?? '')"
                        ></div>
                    </div>

                    <div class="sp-control">
                        <div class="sp-stepper">
                            <button type="button" class="sp-paso" aria-label="Uno menos" x-on:click="mover(fila, -1)" x-bind:disabled="n(fila.sale) <= 0">{{ svg('heroicon-m-minus') }}</button>
                            <input
                                type="number"
                                inputmode="numeric"
                                min="0"
                                aria-label="Sale"
                                x-bind:max="fila.cantidad"
                                x-bind:value="fila.sale"
                                x-on:focus="$event.target.select()"
                                x-on:change="poner(fila, $event.target.value); $event.target.value = fila.sale"
                            >
                            <button type="button" class="sp-paso" aria-label="Uno más" x-on:click="mover(fila, 1)" x-bind:disabled="n(fila.sale) >= n(fila.cantidad)">{{ svg('heroicon-m-plus') }}</button>
                        </div>
                        <span class="sp-sigla" x-text="fila.sigla ?? ''"></span>
                        <button type="button" class="sp-boton sp-no" x-show="n(fila.sale) > 0" x-on:click="fila.sale = 0">No hay</button>
                        <button type="button" class="sp-boton sp-si" x-show="n(fila.sale) === 0" x-on:click="fila.sale = n(fila.cantidad)">Sí hay</button>
                    </div>
                </li>
            </template>
        </ul>
    </div>
</x-dynamic-component>
