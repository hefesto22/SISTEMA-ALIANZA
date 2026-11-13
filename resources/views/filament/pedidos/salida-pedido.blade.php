{{--
    Validar y Corregir: cuánto sale de cada producto (App\Filament\Schemas\Components\SalidaDePedido).

    Pensado para pedidos largos (30 productos o más): cada producto es una
    fila corta con lo que sale ("2 CJ12", "3 UN"), el nombre, lo que pidió y,
    a la derecha, si cambió ("−1 CJ12", "+3 UN", "No sale") y cuánto queda en
    la bodega. Al tocarla se abre: cuánto hay en la bodega de cada
    presentación (cajas cerradas y sueltas aparte), cuánto saca este pedido y
    cuánto quedaría; si las sueltas no alcanzan, qué caja se abre (lo escoge
    bodega; viene sugerida la más chica que alcanza), y un contador con − y + por cada presentación
    que se vende (CJ12, CJ8, UN…): se puede mandar menos, más o en otra
    presentación (unidades sueltas si una caja vino dañada). Arriba, filtros: todos, con cambios, no alcanza. Abajo,
    un resumen de lo que no va completo, de lo que va de más y de lo agregado.

    "Agregar otro producto" (arriba): un botón que abre el buscador (así al
    abrir el modal no salta el teclado del teléfono). Busca un producto
    activo que no venía en el pedido (SalidaDePedido::buscarProductos), con
    lo que hay en la bodega, y lo suma al final de la lista ya abierto, con 1
    en la presentación más grande que alcanza. Si ya está en la lista,
    solo lo abre. Con "controlar existencias" encendido, lo que no tiene
    existencia no entra. Uno agregado se puede quitar; si se deja en 0 no se
    agrega.

    El estado son las líneas del pedido más, sin id, las presentaciones que
    cada producto no trae (LineasPedido::datos); aquí se juntan por producto.
    Todo pasa en el teléfono (Alpine); al servidor solo va al confirmar. Los
    estilos van aquí mismo, sin compilar.
--}}
@php
    $statePath = $getStatePath();
    $filas = is_array($getState()) ? array_values($getState()) : [];
    $clave = md5((string) json_encode(array_map(fn (mixed $fila): mixed => is_array($fila) ? [$fila['id'] ?? null, $fila['producto_id'] ?? null, $fila['presentacion_id'] ?? null] : null, $filas)));
    $productos = count(array_unique(array_map(fn (mixed $fila): mixed => is_array($fila) ? ($fila['producto_id'] ?? null) : null, $filas)));
    $componente = $getKey();
    $controlar = \App\Models\Configuracion::actual()->controlar_existencias;
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

            /* ── Agregar producto (no venía en el pedido) ── */
            .sp-agregar { display: grid; gap: 0.4rem; }
            .sp-agregar-boton { display: flex; align-items: center; gap: 0.5rem; width: 100%; padding: 0.55rem 0.875rem; border-radius: 0.75rem; font-size: 0.85rem; font-weight: 600; text-align: start; color: var(--primary-600); outline: 1px dashed color-mix(in oklab, var(--primary-500) 55%, transparent); }
            .sp-agregar-boton:hover { background: color-mix(in oklab, var(--primary-500) 6%, transparent); }
            .dark .sp-agregar-boton { color: var(--primary-400); }
            .sp-agregar-boton svg { flex-shrink: 0; width: 1.1rem; height: 1.1rem; }
            .sp-agregar-boton em { font-style: normal; font-weight: 500; color: var(--sp-tenue); }
            .sp-agregar-cerrar { flex-shrink: 0; padding: 0 0.75rem; font-size: 0.78rem; font-weight: 600; color: var(--sp-tenue); }
            .sp-agregar-cerrar:hover { color: var(--gray-700); }
            .dark .sp-agregar-cerrar:hover { color: var(--gray-200); }
            .sp-resultados { border-radius: 0.75rem; background: #fff; outline: 1px solid var(--sp-borde); box-shadow: 0 8px 20px rgb(0 0 0 / 0.1); max-height: min(20rem, 50vh); overflow-y: auto; overscroll-behavior: contain; }
            .dark .sp-resultados { background: var(--gray-800); }
            .sp-resultado { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; column-gap: 0.75rem; width: 100%; padding: 0.6rem 0.875rem; text-align: start; border-bottom: 1px solid var(--sp-linea); cursor: pointer; }
            .sp-resultado:last-child { border-bottom: 0; }
            .sp-resultado:hover, .sp-resultado:focus-visible { background: var(--sp-suave); outline: none; }
            .sp-resultado-texto { display: grid; gap: 0.1rem; min-width: 0; }
            .sp-resultado-nombre { font-size: 0.85rem; font-weight: 600; line-height: 1.3; }
            .sp-resultado-meta { font-size: 0.72rem; color: var(--sp-tenue); font-variant-numeric: tabular-nums; }
            .sp-resultado-ya { font-size: 0.72rem; font-weight: 600; color: var(--primary-600); }
            .dark .sp-resultado-ya { color: var(--primary-400); }
            .sp-resultado-hay { display: grid; justify-items: center; min-width: 4.75rem; padding: 0.3rem 0.5rem; border-radius: 0.5rem; line-height: 1.15; text-align: center; color: var(--success-700); background: color-mix(in oklab, var(--success-500) 12%, transparent); }
            .dark .sp-resultado-hay { color: var(--success-400); }
            .sp-resultado-hay span { font-size: 0.62rem; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase; opacity: 0.8; }
            .sp-resultado-hay strong { font-size: 0.8rem; font-weight: 700; white-space: nowrap; font-variant-numeric: tabular-nums; }
            .sp-resultado-hay.sp-hay-nada, .dark .sp-resultado-hay.sp-hay-nada { color: #fff; background: var(--danger-600); }
            .sp-resultado-hay.sp-hay-nada :is(span, strong) { font-size: 0.68rem; font-weight: 800; text-transform: uppercase; opacity: 1; }
            .sp-resultado.sp-agotado .sp-resultado-nombre { color: var(--sp-tenue); }
            .sp-resultado.sp-bloqueado { cursor: not-allowed; }
            .sp-resultado.sp-bloqueado .sp-resultado-texto { opacity: 0.55; }
            .sp-resultado.sp-bloqueado:hover { background: transparent; }
            .sp-aviso { padding: 0.7rem 0.875rem; font-size: 0.8rem; color: var(--sp-tenue); }
            .sp-aviso-sin { padding: 0.55rem 0.75rem; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 600; color: var(--danger-700); background: color-mix(in oklab, var(--danger-500) 12%, transparent); }
            .dark .sp-aviso-sin { color: var(--danger-300); }

            /* ── Lista ── */
            .sp-lista { display: grid; margin: 0; padding: 0; list-style: none; border-radius: 0.75rem; outline: 1px solid var(--sp-borde); overflow: hidden; }
            .sp-fila { border-bottom: 1px solid var(--sp-linea); transition: background-color 0.2s ease; }
            .sp-fila:last-child { border-bottom: 0; }
            .sp-fila.sp-fila-nada { background: color-mix(in oklab, var(--danger-500) 6%, transparent); }
            .sp-fila.sp-fila-parcial { background: color-mix(in oklab, var(--warning-500) 5%, transparent); }
            .sp-fila.sp-fila-mas { background: color-mix(in oklab, var(--info-500) 6%, transparent); }
            .sp-fila.sp-fila-nuevo { background: color-mix(in oklab, var(--success-500) 6%, transparent); }
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
            .sp-chip.sp-chip-nuevo { color: var(--success-700); background: color-mix(in oklab, var(--success-500) 14%, transparent); }
            .dark .sp-chip.sp-chip-nuevo { color: var(--success-300); }
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
            .sp-estado.sp-estado-nuevo { color: var(--success-700); }
            .dark .sp-estado.sp-estado-nuevo { color: var(--success-400); }
            .sp-no-alcanza { font-size: 0.75rem; font-weight: 600; color: var(--danger-600); }

            /* Las sueltas no alcanzan: qué caja se abre. */
            .sp-abrir { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem 0.6rem; padding: 0.5rem 0.65rem; border-radius: 0.5rem; font-size: 0.8rem; color: var(--warning-800); background: color-mix(in oklab, var(--warning-500) 10%, transparent); outline: 1px solid color-mix(in oklab, var(--warning-500) 40%, transparent); }
            .dark .sp-abrir { color: var(--warning-300); }
            .sp-abrir strong { font-weight: 800; font-variant-numeric: tabular-nums; }
            .sp-abrir select { height: 2rem; padding: 0 1.75rem 0 0.5rem; border-radius: 0.4rem; border: 0; font: inherit; font-weight: 700; color: inherit; background-color: #fff; outline: 1px solid color-mix(in oklab, var(--warning-500) 45%, transparent); }
            .dark .sp-abrir select { background-color: var(--gray-900); }
            .sp-abrir-control { display: inline-flex; align-items: center; gap: 0.4rem; }
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
            .sp-incompletas.sp-agregados { color: var(--success-800); background: color-mix(in oklab, var(--success-500) 10%, transparent); outline-color: color-mix(in oklab, var(--success-500) 40%, transparent); }
            .dark .sp-incompletas.sp-agregados { color: var(--success-300); }

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

            {{-- Agregar producto. --}}
            controlar: @js($controlar),
            agregando: false,
            buscar: '',
            resultados: [],
            buscando: false,
            buscado: false,
            turnoBusqueda: 0,
            esperaBusqueda: null,
            avisoAgregar: '',

            init() {
                if (! Array.isArray(this.filas)) {
                    this.filas = Object.values(this.filas ?? {})
                }

                this.ajustarAperturas()

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

            llamar(metodo, argumentos) {
                return Livewire.fireAction(this.$wire.__instance, 'callSchemaComponentMethod', [@js($componente), metodo, argumentos], { async: true })
            },

            alEscribir() {
                clearTimeout(this.esperaBusqueda)
                this.avisoAgregar = ''
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

                    this.resultados = Array.isArray(r) ? r : []
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

            abrirBusqueda() {
                this.agregando = true
                this.$nextTick(() => this.$refs.buscador?.focus())
            },

            {{-- Escape en el buscador lo cierra (no cierra el modal). --}}
            alEscape(evento) {
                evento.preventDefault()
                evento.stopPropagation()
                this.cerrarBusqueda()
            },

            cerrarBusqueda() {
                clearTimeout(this.esperaBusqueda)
                this.turnoBusqueda++
                this.agregando = false
                this.avisoAgregar = ''
                this.buscar = ''
                this.resultados = []
                this.buscado = false
                this.buscando = false
            },

            enLista(p) {
                return this.filas.some((fila) => this.n(fila.producto_id) === this.n(p.producto_id))
            },

            {{-- Controlando la existencia, lo que no hay en la bodega no se agrega. --}}
            bloqueado(p) {
                return this.controlar && ! this.enLista(p) && this.n(p.hay_unidades) <= 0 && p.filas.some((fila) => fila.mueve)
            },

            {{-- Entra al final de la lista, abierto para poner cajas y unidades; si ya estaba, solo se abre. --}}
            agregar(p) {
                if (this.bloqueado(p)) {
                    this.avisoAgregar = 'No hay ' + p.descripcion + ' en la bodega: no se puede agregar.'

                    return
                }

                const id = this.n(p.producto_id)
                const raiz = this.$root

                if (! this.enLista(p)) {
                    this.filas.push(...p.filas.map((fila) => ({ ...fila })))
                    this.ajustarAperturas()
                }

                this.cerrarBusqueda()
                this.filtro = 'todos'
                this.abierta = id

                this.$nextTick(() => raiz?.querySelector('[data-producto=\'' + id + '\']')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }))
            },

            {{-- Un producto que no venía en el pedido: el cliente no pidió nada de él. --}}
            nuevo(grupo) {
                return this.filasDe(grupo).every((fila) => this.n(fila.cantidad) === 0)
            },

            productoNuevo(fila) {
                return this.filas.every((otra) => this.n(otra.producto_id) !== this.n(fila.producto_id) || this.n(otra.cantidad) === 0)
            },

            {{-- Quitar uno agregado: si todavía no se guarda, sale de la lista; si ya estaba guardado (al corregir), queda en 0 y se quita al guardar. --}}
            quitar(grupo) {
                if (this.filasDe(grupo).every((fila) => fila.id === null || fila.id === undefined)) {
                    this.filas = this.filas.filter((fila) => this.n(fila.producto_id) !== grupo.id)
                    this.abierta = null

                    return
                }

                this.noHay(grupo)
            },

            {{-- De 0 en adelante: menos de lo pedido si no alcanzó, más si el cliente pidió más. --}}
            poner(fila, valor) {
                fila.sale = Math.min(Math.max(this.n(valor), 0), this.maximo)
                this.ajustarAperturas()
            },

            mover(fila, paso) {
                this.poner(fila, this.n(fila.sale) + paso)
            },

            {{-- Un producto: sus presentaciones (las que no venían en el pedido, sin id). --}}
            grupos() {
                const orden = []
                const porProducto = {}

                for (const fila of this.filas) {
                    const id = this.n(fila.producto_id)

                    if (! porProducto[id]) {
                        porProducto[id] = { id, info: fila, filas: [] }
                        orden.push(porProducto[id])
                    }

                    porProducto[id].filas.push(fila)
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

            {{-- La más grande primero (CJ12, CJ8, UN). --}}
            filasDe(grupo) {
                return [...grupo.filas].sort((a, b) => this.n(b.contenido) - this.n(a.contenido))
            },

            sigla(fila) {
                return fila.sigla
            },

            {{-- "1 caja de 12", "3 cajas de 12", "1 unidad", "5 unidades". --}}
            palabras(cantidad, fila) {
                cantidad = this.n(cantidad)

                return cantidad + ' ' + (cantidad === 1 ? fila.singular : fila.plural)
            },

            {{-- "Cajas de 12", "Unidades": encima de cada contador. --}}
            titulo(fila) {
                const plural = String(fila.plural ?? '')

                return plural.charAt(0).toUpperCase() + plural.slice(1)
            },

            {{-- "1 caja de 12 y 3 unidades". --}}
            pidio(grupo) {
                return this.filasDe(grupo)
                    .filter((fila) => this.n(fila.cantidad) > 0)
                    .map((fila) => this.palabras(fila.cantidad, fila))
                    .join(' y ')
            },

            {{-- "2 CJ12 + 3 UN" (en la fila corta). --}}
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
                this.ajustarAperturas()
            },

            comoSePidio(grupo) {
                this.filasDe(grupo).forEach((fila) => fila.sale = this.n(fila.cantidad))
                this.ajustarAperturas()
            },

            {{-- A la derecha de la fila corta: "−1 CJ12", "+3 UN", "No sale", "Agregado"; vacío si sale como se pidió. --}}
            cambioCorto(grupo) {
                if (! this.difiere(grupo)) return ''
                if (this.total(grupo) === 0) return 'No sale'
                if (this.nuevo(grupo)) return 'Agregado'

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
                if (this.nuevo(grupo)) return 'sp-chip-nuevo'

                return this.filasDe(grupo).some((fila) => this.n(fila.sale) < this.n(fila.cantidad)) ? '' : 'sp-chip-mas'
            },

            {{-- ── Existencia de cada presentación (las cajas cerradas y las sueltas aparte) ── --}}

            {{-- Lo que cambia en la bodega de esa presentación: al validar sale todo; al corregir, la diferencia con lo que ya salió. --}}
            cambioDe(fila) {
                return this.n(fila.sale) - (this.paraValidar ? 0 : this.n(fila.salio_pres))
            },

            baseDe(grupo) {
                return grupo.filas.find((fila) => fila.es_base) ?? null
            },

            {{-- Cajas de esa presentación que quedan cerradas después de las que salen enteras: las que se pueden abrir. --}}
            libres(fila) {
                return Math.max(0, this.n(fila.hay_pres) - Math.max(0, this.cambioDe(fila)))
            },

            {{-- Unidades sueltas que salen de más sobre las que hay: hay que abrir cajas. --}}
            faltanSueltas(grupo) {
                const base = this.baseDe(grupo)

                if (! grupo.info.mueve || ! base) {
                    return 0
                }

                return Math.max(0, this.cambioDe(base) - Math.max(0, this.n(base.hay_pres)))
            },

            {{-- Las cajas que se pueden abrir (las que quedan cerradas), la más grande primero. --}}
            opcionesAbrir(grupo) {
                return this.filasDe(grupo).filter((fila) => ! fila.es_base && this.libres(fila) > 0)
            },

            cajaElegida(grupo) {
                const base = this.baseDe(grupo)

                return this.opcionesAbrir(grupo).find((fila) => this.n(fila.presentacion_id) === this.n(base?.abrir_de)) ?? null
            },

            {{-- Cuántas de la caja escogida se abren: las que hacen falta para las sueltas (hasta las que hay). --}}
            cajasPorAbrir(grupo) {
                const caja = this.cajaElegida(grupo)
                const faltan = this.faltanSueltas(grupo)

                if (! caja || faltan <= 0) {
                    return 0
                }

                return Math.min(this.libres(caja), Math.ceil(faltan / Math.max(1, this.n(caja.contenido))))
            },

            {{-- Deja en la fila de las sueltas qué caja se abre y cuántas (es lo que va al servidor). Si bodega no ha escogido una que sirva, sugiere la más chica que alcanza (o la que más trae). --}}
            ajustarApertura(grupo) {
                const base = this.baseDe(grupo)

                if (! base) {
                    return
                }

                const opciones = this.opcionesAbrir(grupo)

                if (this.faltanSueltas(grupo) <= 0 || opciones.length === 0) {
                    base.abrir_cajas = 0

                    return
                }

                if (! opciones.some((fila) => this.n(fila.presentacion_id) === this.n(base.abrir_de))) {
                    const alcanza = [...opciones].reverse().find((fila) => this.libres(fila) * this.n(fila.contenido) >= this.faltanSueltas(grupo))
                    base.abrir_de = this.n((alcanza ?? opciones[0]).presentacion_id)
                }

                base.abrir_cajas = this.cajasPorAbrir(grupo)
            },

            ajustarAperturas() {
                this.grupos().forEach((grupo) => this.ajustarApertura(grupo))
            },

            elegirCaja(grupo, id) {
                const base = this.baseDe(grupo)

                if (base) {
                    base.abrir_de = this.n(id)
                    this.ajustarApertura(grupo)
                }
            },

            {{-- Cuánto queda de esa presentación: lo que hay − lo que sale; las cajas que se abren salen y sus unidades entran a las sueltas. --}}
            quedanDe(grupo, fila) {
                let queda = this.n(fila.hay_pres) - (grupo.info.mueve ? this.cambioDe(fila) : 0)
                const caja = this.cajaElegida(grupo)
                const abiertas = this.cajasPorAbrir(grupo)

                if (fila.es_base && caja) {
                    queda += abiertas * this.n(caja.contenido)
                }

                if (caja && this.n(caja.presentacion_id) === this.n(fila.presentacion_id)) {
                    queda -= abiertas
                }

                return queda
            },

            {{-- "2 CJ48 · 3 CJ32 · 5 UN" (las que están en 0 no salen; "0" si nada). --}}
            porPresentacion(grupo, cuanto) {
                const partes = this.filasDe(grupo)
                    .map((fila) => [cuanto(fila), fila.sigla])
                    .filter(([cantidad]) => cantidad !== 0)
                    .map(([cantidad, sigla]) => cantidad + ' ' + sigla)

                return partes.length === 0 ? '0' : partes.join(' · ')
            },

            enBodegaTexto(grupo) {
                return this.porPresentacion(grupo, (fila) => this.n(fila.hay_pres))
            },

            {{-- "−2 CJ48 · −5 UN" (sale) o "+1 CJ48" (regresa al corregir). --}}
            cambioTexto(grupo) {
                return this.porPresentacion(grupo, (fila) => -this.cambioDe(fila)).replace(/(^|· )(\d)/g, '$1+$2').replace(/-/g, '−')
            },

            quedanTexto(grupo) {
                return this.porPresentacion(grupo, (fila) => this.quedanDe(grupo, fila))
            },

            {{-- Lo que no alcanza: las presentaciones que quedarían en negativo por este pedido. --}}
            faltaTexto(grupo) {
                return this.filasDe(grupo)
                    .filter((fila) => this.cambioDe(fila) > 0 && this.quedanDe(grupo, fila) < 0)
                    .map((fila) => (-this.quedanDe(grupo, fila)) + ' ' + fila.sigla)
                    .join(' · ')
            },

            aperturaTexto(grupo) {
                const caja = this.cajaElegida(grupo)

                return caja && this.cajasPorAbrir(grupo) > 0 ? 'abre ' + this.cajasPorAbrir(grupo) + ' ' + caja.sigla : ''
            },

            cambio(grupo) {
                return this.filasDe(grupo).reduce((suma, fila) => suma + this.cambioDe(fila) * Math.max(1, this.n(fila.contenido)), 0)
            },

            noAlcanza(grupo) {
                return grupo.info.mueve && this.faltaTexto(grupo) !== ''
            },

            {{-- Para el resumen de abajo: las cajas que se abren. --}}
            aperturas() {
                return this.grupos().filter((grupo) => this.aperturaTexto(grupo) !== '')
            },

            {{-- Como PedidoItem: "No sale: pidió 2 cajas de 12", "No va completo: falta 1 caja de 12 (pidió 3)", "Va de más: …"; vacío si va justo. --}}
            estado(fila) {
                const sale = this.n(fila.sale)
                const pidio = this.n(fila.cantidad)

                if (pidio === 0 && sale > 0 && this.productoNuevo(fila)) {
                    return 'Agregado: ' + this.palabras(sale, fila) + ' (no venía en el pedido)'
                }

                if (pidio === 0) {
                    return sale > 0 ? 'Va de más: ' + this.palabras(sale, fila) + ' (no lo pidió en ' + fila.plural + ')' : ''
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

                return 'No va completo: ' + (falta === 1 ? 'falta ' : 'faltan ') + this.palabras(falta, fila) + ' (pidió ' + pidio + ')'
            },

            claseEstado(fila) {
                const sale = this.n(fila.sale)

                if (sale > 0 && this.productoNuevo(fila)) return 'sp-estado-nuevo'

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
                return this.filas.filter((fila) => this.n(fila.sale) > this.n(fila.cantidad) && ! this.productoNuevo(fila))
            },

            agregados() {
                return this.filas.filter((fila) => this.n(fila.sale) > 0 && this.productoNuevo(fila))
            },

            claseGrupo(grupo) {
                if (this.abierta === grupo.id) return 'sp-abierta'
                if (this.total(grupo) === 0) return 'sp-fila-nada'
                if (this.nuevo(grupo)) return 'sp-fila-nuevo'
                if (this.filasDe(grupo).some((fila) => this.n(fila.cantidad) > 0 && this.n(fila.sale) < this.n(fila.cantidad))) return 'sp-fila-parcial'
                if (this.filasDe(grupo).some((fila) => this.n(fila.sale) > this.n(fila.cantidad))) return 'sp-fila-mas'

                return ''
            },

            {{-- Arriba a la derecha. --}}
            textoResumen() {
                const faltan = this.incompletas().length
                const mas = this.deMas().length
                const nuevos = new Set(this.agregados().map((fila) => this.n(fila.producto_id))).size
                const partes = []

                if (faltan > 0) partes.push(faltan === 1 ? '1 no va completo' : faltan + ' no van completos')
                if (mas > 0) partes.push(mas === 1 ? '1 va de más' : mas + ' van de más')
                if (nuevos > 0) partes.push(nuevos === 1 ? '1 agregado' : nuevos + ' agregados')

                return partes.length === 0 ? 'Sale como se pidió' : partes.join(' · ')
            },
        }"
    >
        <div class="sp-resumen">
            <span x-text="grupos().length === 1 ? '1 producto' : grupos().length + ' productos'">{{ $productos === 1 ? '1 producto' : $productos.' productos' }}</span>
            <span
                x-bind:class="incompletas().length === 0 && deMas().length === 0 && agregados().length === 0 ? 'sp-resumen-ok' : 'sp-resumen-falta'"
                x-text="textoResumen()"
            ></span>
        </div>

        {{-- Agregar un producto que no venía en el pedido. --}}
        <div class="sp-agregar">
            <button type="button" class="sp-agregar-boton" x-show="! agregando" x-on:click="abrirBusqueda()">
                {{ svg('heroicon-m-plus') }}
                <span>Agregar otro producto <em>que no viene en el pedido</em></span>
            </button>

            <div class="fi-input-wrp" x-show="agregando" x-cloak>
                <div class="fi-input-wrp-prefix fi-input-wrp-prefix-has-content fi-inline">
                    {{ svg('heroicon-m-plus', 'fi-icon fi-size-md') }}
                </div>

                <div class="fi-input-wrp-content-ctn">
                    <input
                        x-ref="buscador"
                        x-model="buscar"
                        x-on:input="alEscribir()"
                        x-on:keydown.enter.prevent="alEnter()"
                        x-on:keydown.escape="alEscape($event)"
                        type="text"
                        enterkeyhint="search"
                        autocomplete="off"
                        placeholder="Código o nombre del producto…"
                        aria-label="Agregar un producto que no viene en el pedido: buscar por código o nombre"
                        class="fi-input"
                    />
                </div>

                <div class="fi-input-wrp-suffix fi-inline" x-show="buscando" x-cloak>
                    {{ svg('heroicon-m-arrow-path', 'fi-icon fi-size-md animate-spin') }}
                </div>

                <button type="button" class="sp-agregar-cerrar" x-on:click="cerrarBusqueda()">Cerrar</button>
            </div>

            <div class="sp-aviso-sin" x-show="avisoAgregar !== ''" x-cloak x-text="avisoAgregar"></div>

            <div class="sp-resultados" x-show="buscado || (buscar.trim().length === 1 && ! /^\d+$/.test(buscar.trim()))" x-cloak>
                <template x-for="p in resultados" x-bind:key="'agregar-' + p.producto_id">
                    <button
                        type="button"
                        class="sp-resultado"
                        x-bind:class="{ 'sp-agotado': n(p.hay_unidades) <= 0, 'sp-bloqueado': bloqueado(p) }"
                        x-bind:aria-disabled="bloqueado(p)"
                        x-on:click="agregar(p)"
                    >
                        <span class="sp-resultado-texto">
                            <span class="sp-resultado-nombre" x-text="p.descripcion"></span>
                            <span class="sp-resultado-meta" x-text="p.codigo + ' · ' + p.filas.map((fila) => fila.sigla).join(' · ')"></span>
                            <span class="sp-resultado-ya" x-show="enLista(p)">Ya está en la lista: tocar para verlo</span>
                        </span>
                        <span class="sp-resultado-hay" x-bind:class="{ 'sp-hay-nada': n(p.hay_unidades) <= 0 }">
                            <span x-text="n(p.hay_unidades) <= 0 ? 'Sin' : 'Hay'"></span>
                            <strong x-text="n(p.hay_unidades) <= 0 ? 'existencia' : p.hay"></strong>
                        </span>
                    </button>
                </template>

                <div class="sp-aviso" x-show="buscado && resultados.length === 0">No hay productos activos con ese código o nombre.</div>
                <div class="sp-aviso" x-show="! buscado && buscar.trim().length === 1">Escriba al menos 2 letras (o el código).</div>
            </div>
        </div>

        {{-- Con muchos productos: ver solo los que cambiaron o los que no alcanzan. --}}
        <div class="sp-filtros" x-show="grupos().length > 1">
            <button type="button" class="sp-filtro" x-bind:class="{ 'sp-activo': filtro === 'todos' }" x-on:click="filtro = 'todos'">Todos <b x-text="grupos().length"></b></button>
            <button type="button" class="sp-filtro" x-bind:class="{ 'sp-activo': filtro === 'cambios' }" x-on:click="filtro = 'cambios'">Con cambios <b x-text="grupos().filter((grupo) => difiere(grupo)).length"></b></button>
            <button type="button" class="sp-filtro" x-show="filtro === 'alcance' || grupos().some((grupo) => noAlcanza(grupo))" x-bind:class="{ 'sp-activo': filtro === 'alcance' }" x-on:click="filtro = 'alcance'">No alcanza <b x-text="grupos().filter((grupo) => noAlcanza(grupo)).length"></b></button>
        </div>

        <ul class="sp-lista">
            <template x-for="grupo in gruposVisibles()" x-bind:key="grupo.id">
                <li class="sp-fila" x-bind:class="claseGrupo(grupo)" x-bind:data-producto="grupo.id">
                    {{-- Fila corta --}}
                    <button type="button" class="sp-corta" x-on:click="abrir(grupo)" x-bind:aria-expanded="abierta === grupo.id">
                        <span class="sp-cants">
                            <template x-for="fila in filasDe(grupo).filter((fila) => n(fila.sale) > 0)" x-bind:key="'cant-' + fila.presentacion_id">
                                <span class="sp-cant" x-bind:class="{ 'sp-cant-caja': ! fila.es_base }" x-text="n(fila.sale) + ' ' + sigla(fila)"></span>
                            </template>
                            <span class="sp-cant sp-cant-cero" x-show="total(grupo) === 0">0</span>
                        </span>

                        <span class="sp-corta-texto">
                            <span class="sp-corta-nombre" x-text="grupo.info.descripcion ?? grupo.info.producto"></span>
                            <span class="sp-corta-meta" x-text="(grupo.info.codigo ? grupo.info.codigo + ' · ' : '') + (nuevo(grupo) ? 'no venía en el pedido' : 'pidió ' + pidioCorto(grupo))"></span>
                        </span>

                        <span class="sp-corta-lado">
                            <span class="sp-chip" x-show="cambioCorto(grupo) !== ''" x-bind:class="claseCambio(grupo)" x-text="cambioCorto(grupo)"></span>
                            <span
                                class="sp-corta-quedan"
                                x-show="grupo.info.mueve"
                                x-bind:class="{ 'sp-neg': noAlcanza(grupo) }"
                                x-text="(aperturaTexto(grupo) !== '' ? aperturaTexto(grupo) + ' · ' : '') + (noAlcanza(grupo) ? 'no alcanza: faltan ' + faltaTexto(grupo) : 'quedan ' + quedanTexto(grupo))"
                            ></span>
                        </span>

                        <svg class="sp-flecha" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                    </button>

                    {{-- Fila abierta --}}
                    <div class="sp-detalle" x-show="abierta === grupo.id">
                        <div class="sp-meta">
                            <span x-show="! nuevo(grupo)">Pidió <strong x-text="pidio(grupo)"></strong></span>
                            <span x-show="nuevo(grupo)"><strong>No venía en el pedido:</strong> lo agrega bodega.</span>
                        </div>

                        {{-- Del producto en la bodega del pedido. --}}
                        <div class="sp-bodega" x-bind:class="{ 'sp-bodega-sola': ! grupo.info.mueve }">
                            <div class="sp-dato">
                                <span>En bodega</span>
                                <strong x-text="enBodegaTexto(grupo)"></strong>
                            </div>
                            <div class="sp-dato" x-show="grupo.info.mueve" x-bind:class="cambio(grupo) < 0 ? 'sp-regresa' : 'sp-sale'">
                                <span x-text="paraValidar ? 'Este pedido' : (cambio(grupo) < 0 ? 'Regresan' : (cambio(grupo) > 0 ? 'Salen más' : 'Sin cambio'))"></span>
                                <strong x-text="cambioTexto(grupo)"></strong>
                            </div>
                            <div class="sp-dato" x-show="grupo.info.mueve" x-bind:class="{ 'sp-negativo': noAlcanza(grupo), 'sp-justo': ! noAlcanza(grupo) && quedanTexto(grupo) === '0' }">
                                <span>Quedan</span>
                                <strong x-text="quedanTexto(grupo)"></strong>
                            </div>
                        </div>

                        {{-- Las sueltas no alcanzan: bodega escoge qué caja abre. --}}
                        <div class="sp-abrir" x-show="faltanSueltas(grupo) > 0" x-cloak>
                            <span x-text="'Faltan ' + faltanSueltas(grupo) + ' ' + (baseDe(grupo)?.sigla ?? 'UN') + ' sueltas:'"></span>
                            <template x-if="opcionesAbrir(grupo).length > 0">
                                <span class="sp-abrir-control">
                                    <span>se abre<span x-show="cajasPorAbrir(grupo) !== 1">n</span></span>
                                    <strong x-text="cajasPorAbrir(grupo)"></strong>
                                    <select x-on:change="elegirCaja(grupo, $event.target.value)" aria-label="Qué caja se abre">
                                        <template x-for="caja in opcionesAbrir(grupo)" x-bind:key="'abrir-' + caja.presentacion_id">
                                            <option x-bind:value="caja.presentacion_id" x-bind:selected="n(caja.presentacion_id) === n(baseDe(grupo)?.abrir_de)" x-text="caja.sigla + ' (hay ' + libres(caja) + ')'"></option>
                                        </template>
                                    </select>
                                </span>
                            </template>
                            <span x-show="opcionesAbrir(grupo).length === 0">no hay cajas cerradas para abrir.</span>
                        </div>

                        <div class="sp-no-alcanza" x-show="noAlcanza(grupo)" x-text="'No alcanza: faltan ' + faltaTexto(grupo) + ' en la bodega'"></div>

                        <template x-for="fila in filasDe(grupo)" x-bind:key="'estado-' + fila.presentacion_id">
                            <div class="sp-estado" x-bind:class="claseEstado(fila)" x-show="estado(fila) !== ''" x-text="estado(fila)"></div>
                        </template>

                        <div class="sp-controles">
                            <template x-for="fila in filasDe(grupo)" x-bind:key="'cantidad-' + fila.presentacion_id">
                                <div class="sp-cantidad">
                                    <span class="sp-cantidad-etiqueta">
                                        <span x-text="titulo(fila)"></span>
                                        <em x-text="n(fila.cantidad) > 0 ? 'pidió ' + n(fila.cantidad) : 'no pidió'"></em>
                                    </span>
                                    <div class="sp-stepper" x-bind:class="{ 'sp-stepper-caja': ! fila.es_base }">
                                        <button type="button" class="sp-paso" x-bind:aria-label="'Una menos: ' + fila.nombre" x-on:click="mover(fila, -1)" x-bind:disabled="n(fila.sale) <= 0">{{ svg('heroicon-m-minus') }}</button>
                                        <input
                                            type="number"
                                            inputmode="numeric"
                                            min="0"
                                            x-bind:max="maximo"
                                            x-bind:aria-label="titulo(fila) + ' que salen'"
                                            x-bind:value="fila.sale"
                                            x-on:focus="$event.target.select()"
                                            x-on:change="poner(fila, $event.target.value); $event.target.value = fila.sale"
                                            x-on:keydown.enter.prevent="poner(fila, $event.target.value); $event.target.value = fila.sale; $event.target.blur()"
                                        >
                                        <button type="button" class="sp-paso" x-bind:aria-label="'Una más: ' + fila.nombre" x-on:click="mover(fila, 1)" x-bind:disabled="n(fila.sale) >= maximo">{{ svg('heroicon-m-plus') }}</button>
                                    </div>
                                </div>
                            </template>

                            <div class="sp-acciones">
                                <button type="button" class="sp-enlace sp-subrayado" x-show="! nuevo(grupo) && total(grupo) > 0 && difiere(grupo)" x-on:click="comoSePidio(grupo)">Volver a lo pedido</button>
                                <button type="button" class="sp-boton sp-no" x-show="! nuevo(grupo) && total(grupo) > 0" x-on:click="noHay(grupo)">No hay</button>
                                <button type="button" class="sp-boton sp-si" x-show="! nuevo(grupo) && total(grupo) === 0" x-on:click="comoSePidio(grupo)">Sí hay</button>
                                <button type="button" class="sp-boton sp-no" x-show="nuevo(grupo)" x-on:click="quitar(grupo)">Quitar</button>
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
                <template x-for="fila in incompletas()" x-bind:key="'falta-' + fila.presentacion_id">
                    <li><b x-text="sigla(fila) + ' · ' + (fila.descripcion ?? fila.producto)"></b> — <span x-text="estadoCorto(fila)"></span></li>
                </template>
            </ul>
        </div>

        <div class="sp-incompletas sp-de-mas" x-show="deMas().length > 0" x-cloak>
            <strong>Va de más</strong>
            <ul>
                <template x-for="fila in deMas()" x-bind:key="'mas-' + fila.presentacion_id">
                    <li><b x-text="sigla(fila) + ' · ' + (fila.descripcion ?? fila.producto)"></b> — <span x-text="estadoCorto(fila)"></span></li>
                </template>
            </ul>
        </div>

        <div class="sp-incompletas sp-de-mas" x-show="aperturas().length > 0" x-cloak>
            <strong>Cajas que se abren</strong>
            <ul>
                <template x-for="grupo in aperturas()" x-bind:key="'abre-' + grupo.id">
                    <li><b x-text="grupo.info.descripcion ?? grupo.info.producto"></b> — <span x-text="aperturaTexto(grupo) + ' (sus unidades pasan a las sueltas)'"></span></li>
                </template>
            </ul>
        </div>

        <div class="sp-incompletas sp-agregados" x-show="agregados().length > 0" x-cloak>
            <strong>Agregado (no venía en el pedido)</strong>
            <ul>
                <template x-for="fila in agregados()" x-bind:key="'agregado-' + fila.presentacion_id">
                    <li><b x-text="sigla(fila) + ' · ' + (fila.descripcion ?? fila.producto)"></b> — <span x-text="palabras(fila.sale, fila)"></span></li>
                </template>
            </ul>
        </div>
    </div>
</x-dynamic-component>
