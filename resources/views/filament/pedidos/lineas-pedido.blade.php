{{--
    Productos de la preventa como lista tipo carrito (App\Filament\Schemas\Components\LineasPedido)
    y, abajo, la barra con el total y Guardar.

    Todo lo de la lista pasa en el teléfono (Alpine): agregar, unidad o caja,
    − y +, quitar. Al servidor solo van la búsqueda, el total con promociones
    (cuando se deja de tocar), el último pedido del cliente y Guardar.

    Cada producto es una sola fila corta (cajas y unidades, nombre, subtotal);
    al tocarla se abre para poner cajas y unidades. Por dentro son hasta dos
    líneas (una en caja y otra en unidad), como siempre las ve bodega. El que
    se acaba de agregar queda abierto.
    El buscador se queda arriba al bajar por la lista. Los estilos van aquí
    mismo, sin compilar.

    Existencia: lo DISPONIBLE en la bodega del pedido, o sea lo que hay menos
    lo apartado en otros pedidos pendientes (administración sin bodega
    escogida: lo de todas). En el buscador salen primero los que tienen
    disponible; los que no, al final con "SIN EXISTENCIA" en rojo. Solo se
    muestra lo disponible (lo de otros pedidos ya va restado). Con
    "controlar existencias" encendido los que no tienen se ven pero no se
    pueden agregar, y no se deja pedir más de lo disponible.
--}}
@php
    use App\Domain\Precios\CalculadoraPrecio;
    use App\Filament\Resources\Pedidos\Schemas\PedidoForm;
    use App\Filament\Schemas\Components\LineasPedido;
    use App\Filament\Support\Acceso;
    use App\Models\Configuracion;

    $statePath = $getStatePath();
    $lineasIniciales = LineasPedido::normalizar($getState());
    $resumen = PedidoForm::resumenDe($lineasIniciales);
    $productosIniciales = count(array_unique(array_column($lineasIniciales, 'producto_id')));
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
        'controlar' => Configuracion::actual()->controlar_existencias,
        'veTodo'    => Acceso::veTodo(),
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
        .pv-resultado { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; column-gap: 0.75rem; width: 100%; padding: 0.7rem 0.875rem; text-align: start; border-bottom: 1px solid var(--gray-100); cursor: pointer; }
        .pv-resultado-texto { display: grid; gap: 0.1rem; min-width: 0; }
        .pv-resultado-hay { display: grid; justify-items: center; min-width: 4.75rem; padding: 0.3rem 0.5rem; border-radius: 0.5rem; background: var(--success-50); color: var(--success-700); line-height: 1.15; text-align: center; }
        .pv-resultado-hay strong { font-size: 0.85rem; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .pv-resultado-hay span { font-size: 0.65rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; opacity: 0.8; }
        .dark .pv-resultado-hay { background: rgb(34 197 94 / 0.12); color: var(--success-400); }
        .pv-resultado-hay.pv-hay-poco { background: var(--warning-50); color: var(--warning-700); }
        .dark .pv-resultado-hay.pv-hay-poco { background: rgb(245 158 11 / 0.12); color: var(--warning-400); }
        /* Sin existencia: rojo lleno, que se vea. */
        .pv-resultado-hay.pv-hay-nada, .dark .pv-resultado-hay.pv-hay-nada { background: var(--danger-600); color: #fff; }
        .pv-resultado-hay.pv-hay-nada :is(span, strong) { font-size: 0.72rem; font-weight: 800; letter-spacing: 0.03em; text-transform: uppercase; opacity: 1; }
        .pv-resultado.pv-agotado .pv-resultado-nombre { color: var(--gray-500); }
        .dark .pv-resultado.pv-agotado .pv-resultado-nombre { color: var(--gray-400); }
        /* Controlando la existencia: se ve, pero no se puede agregar. */
        .pv-resultado.pv-bloqueado { cursor: not-allowed; }
        .pv-resultado.pv-bloqueado .pv-resultado-texto { opacity: 0.55; }
        .pv-resultado.pv-bloqueado:hover { background: transparent; }
        .pv-aviso-sin { display: flex; align-items: center; gap: 0.5rem; margin: 0.5rem; padding: 0.6rem 0.75rem; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 600; color: var(--danger-700); background: color-mix(in oklab, var(--danger-500) 12%, transparent); }
        .dark .pv-aviso-sin { color: var(--danger-300); }
        .pv-aviso-sin svg { flex-shrink: 0; width: 1.1rem; height: 1.1rem; }
        .dark .pv-resultado { border-bottom-color: rgb(255 255 255 / 0.06); }
        .pv-resultado:last-child { border-bottom: 0; }
        .pv-resultado:hover, .pv-resultado:focus-visible { background: var(--gray-50); outline: none; }
        .dark .pv-resultado:hover, .dark .pv-resultado:focus-visible { background: rgb(255 255 255 / 0.05); }
        .pv-resultado-nombre { font-size: 0.875rem; font-weight: 600; line-height: 1.3; }
        .pv-resultado-meta { font-size: 0.75rem; color: var(--gray-500); font-variant-numeric: tabular-nums; }
        .pv-resultado-lleva { font-size: 0.72rem; font-weight: 600; color: var(--primary-600); }
        .dark .pv-resultado-meta { color: var(--gray-400); }
        .dark .pv-resultado-lleva { color: var(--primary-400); }
        .pv-hay { font-size: 0.72rem; font-weight: 600; color: var(--success-600); font-variant-numeric: tabular-nums; }
        .dark .pv-hay { color: var(--success-400); }
        .pv-hay.pv-hay-poco { color: var(--warning-600); }
        .dark .pv-hay.pv-hay-poco { color: var(--warning-400); }
        .pv-hay.pv-hay-nada { color: var(--danger-600); }
        .dark .pv-hay.pv-hay-nada { color: var(--danger-400); }
        .pv-tope { font-size: 0.75rem; font-weight: 600; color: var(--warning-600); }
        .dark .pv-tope { color: var(--warning-400); }
        .pv-paso:disabled { opacity: 0.35; cursor: not-allowed; }
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

        /* Cajas y unidades del mismo producto, cada una con su − y +. */
        .pv-cants { flex-shrink: 0; display: grid; gap: 0.25rem; }
        .pv-cantidades { display: flex; flex-wrap: wrap; gap: 0.6rem 1rem; }
        .pv-cantidad { display: grid; gap: 0.25rem; }
        .pv-cantidad-etiqueta { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase; color: var(--gray-500); }
        .dark .pv-cantidad-etiqueta { color: var(--gray-400); }

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
            ordenProductos: [],
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
            topado: null,
            esperaTope: null,
            sinExistencia: '',
            esperaSinExistencia: null,
            ultimo: null,
            total: @js($config['total']),
            ahorro: @js($config['ahorro']),
            promociones: @js($config['promos']),

            init() {
                if (! Array.isArray(this.lineas)) {
                    this.lineas = Object.values(this.lineas ?? {})
                }

                {{-- Un producto = una fila: si venía dos veces en unidad (o dos en caja), se juntan. --}}
                const unidas = []

                for (const l of this.lineas) {
                    const j = unidas.findIndex((u) => Number(u.producto_id) === Number(l.producto_id) && this.esCaja(u) === this.esCaja(l))

                    if (j >= 0) {
                        unidas[j].cantidad = Number(unidas[j].cantidad) + Number(l.cantidad)
                    } else {
                        unidas.push({ ...l })
                    }
                }

                if (unidas.length !== this.lineas.length) {
                    this.lineas = unidas
                }

                this.ordenProductos = [...new Set(this.lineas.map((l) => Number(l.producto_id)))]

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

            esCaja(linea) {
                return linea.unidad === 'caja'
            },

            {{-- Igual que el servidor: precio sin ISV × cantidad (a 2 decimales), más el 15% si es gravado. --}}
            subtotal(linea) {
                const p = this.productos[linea.producto_id] ?? null

                if (! p) {
                    return 0
                }

                const bruto = this.redondear((this.esCaja(linea) ? p.precioCaja : p.precioUnidad) * Number(linea.cantidad || 0))

                return this.redondear(bruto + (p.gravado ? this.redondear(bruto * @js($config['tasa'])) : 0))
            },

            {{-- Cada producto es una fila con sus cajas y sus unidades (por dentro son hasta dos líneas). --}}
            get grupos() {
                const ids = [...new Set(this.lineas.map((l) => Number(l.producto_id)))]
                const conocidos = this.ordenProductos.filter((id) => ids.includes(id))

                return [...conocidos, ...ids.filter((id) => ! conocidos.includes(id))].reverse()
            },

            indice(id, unidad) {
                return this.lineas.findIndex((l) => Number(l.producto_id) === Number(id) && this.esCaja(l) === (unidad === 'caja'))
            },

            cant(id, unidad) {
                const i = this.indice(id, unidad)

                return i >= 0 ? Number(this.lineas[i].cantidad || 0) : 0
            },

            subtotalDe(id) {
                return this.redondear(this.lineas.filter((l) => Number(l.producto_id) === Number(id)).reduce((t, l) => t + this.subtotal(l), 0))
            },

            nombreDe(id) {
                return this.productos[id]?.descripcion ?? ('Producto ' + id)
            },

            precioDe(id) {
                const p = this.productos[id]

                if (! p) {
                    return ''
                }

                return p.codigo + ' · ' + this.dinero(p.precioUnidadIsv) + ' c/u'
                    + (p.caja > 1 ? ' · caja de ' + p.caja + ' ' + this.dinero(p.precioCajaIsv) : '')
                    + (p.gravado ? '' : ' · exento')
            },

            {{-- Las cajas solo se ofrecen si la caja trae más de 1 (o si ya llevaba cajas). --}}
            tieneCajas(id) {
                return (this.productos[id]?.caja ?? 1) > 1 || this.cant(id, 'caja') > 0
            },

            {{-- Pone la cantidad de cajas o de unidades. En 0 esa parte se quita, pero el producto siempre queda con algo: para quitarlo está la ✕. --}}
            fijarCant(id, unidad, valor) {
                const pedido = Math.max(0, parseInt(valor, 10) || 0)
                const maximo = this.maximoPara(id, unidad)
                let n = Math.min(pedido, maximo)
                const otra = this.cant(id, unidad === 'caja' ? 'unidad' : 'caja')

                if (pedido > maximo && maximo < @js($config['maximo'])) {
                    this.avisarTope(id)
                }

                if (n === 0 && otra === 0) {
                    n = 1
                }

                const i = this.indice(id, unidad)

                if (n === 0) {
                    if (i >= 0) {
                        this.lineas.splice(i, 1)
                    }
                } else if (i >= 0) {
                    this.lineas[i].cantidad = n
                } else {
                    this.lineas.push({ producto_id: Number(id), unidad: unidad, cantidad: n })
                }
            },

            cambiarCant(id, unidad, paso) {
                this.fijarCant(id, unidad, this.cant(id, unidad) + paso)
            },

            alEscribirCant(evento, id, unidad) {
                this.fijarCant(id, unidad, evento.target.value)
                evento.target.value = this.cant(id, unidad)
            },

            quitarProducto(id) {
                this.lineas = this.lineas.filter((l) => Number(l.producto_id) !== Number(id))

                if (this.abierta === Number(id)) {
                    this.abierta = null
                }
            },

            {{-- En el buscador: lo que ya lleva de ese producto. --}}
            lleva(id) {
                const partes = []

                if (this.cant(id, 'caja') > 0) {
                    partes.push(this.cant(id, 'caja') + ' CJ')
                }

                if (this.cant(id, 'unidad') > 0) {
                    partes.push(this.cant(id, 'unidad') + ' UN')
                }

                return partes.join(' + ')
            },

            {{-- Unidades como se cuentan en bodega: 29 en cajas de 12 = "2 CJ + 5 UN". --}}
            enCajas(unidades, caja) {
                const u = Number(unidades) || 0

                if (u === 0) {
                    return '0'
                }

                const resto = Math.abs(u)
                const porCaja = Math.max(1, Number(caja) || 1)
                const signo = u < 0 ? '-' : ''

                if (porCaja === 1) {
                    return signo + resto + ' UN'
                }

                const partes = []
                const cajas = Math.floor(resto / porCaja)
                const sueltas = resto % porCaja

                if (cajas > 0) {
                    partes.push(cajas + ' CJ')
                }

                if (sueltas > 0) {
                    partes.push(sueltas + ' UN')
                }

                return signo + partes.join(' + ')
            },

            {{-- Unidades disponibles en la bodega del pedido: lo que hay menos lo apartado en otros pedidos pendientes (null si no se sabe). --}}
            hayDe(id) {
                const hay = this.productos[id]?.hay

                return hay === null || hay === undefined ? null : Number(hay)
            },

            {{-- Debajo del producto abierto, solo lo disponible: "Disponible 2 CJ + 2 UN" o "Sin existencia". --}}
            textoHay(id) {
                const hay = this.hayDe(id) ?? 0

                return hay > 0 ? 'Disponible ' + this.enCajas(hay, this.productos[id]?.caja) : 'Sin existencia'
            },

            {{-- En el buscador, a la derecha: "disponible / 2 CJ" ("en todas" sin bodega escogida) o "sin / existencia". --}}
            etiquetaHay(p) {
                if (Number(p?.hay ?? 0) > 0) {
                    return [this.sinBodegaEscogida() ? 'en todas' : 'disponible', this.enCajas(p.hay, p.caja)]
                }

                return ['sin', 'existencia']
            },

            {{-- Controlando la existencia, lo que no tiene disponible se ve pero no se agrega. --}}
            bloqueado(p) {
                return @js($config['controlar']) && ! this.sinBodegaEscogida() && Number(p?.hay ?? 0) <= 0
            },

            {{-- Poco: menos de lo que trae una caja. --}}
            hayPoco(p) {
                return Number(p?.hay ?? 0) < Math.max(2, Number(p?.caja ?? 1))
            },

            {{-- Lo más que se puede pedir en cajas o en unidades sin pasar de lo que hay. --}}
            maximoPara(id, unidad) {
                const hay = this.hayDe(id)

                if (! @js($config['controlar']) || hay === null || this.sinBodegaEscogida()) {
                    return @js($config['maximo'])
                }

                const caja = Math.max(1, Number(this.productos[id]?.caja ?? 1))
                const yaVa = unidad === 'caja' ? this.cant(id, 'unidad') : this.cant(id, 'caja') * caja

                return Math.max(0, Math.floor((hay - yaVa) / (unidad === 'caja' ? caja : 1)))
            },

            avisarTope(id) {
                this.topado = Number(id)
                clearTimeout(this.esperaTope)
                this.esperaTope = setTimeout(() => this.topado = null, 2500)
            },

            {{-- Administración sin bodega escogida: lo que hay es lo de todas las bodegas. --}}
            sinBodegaEscogida() {
                return @js($config['veTodo']) && ! this.$wire.get('data.bodega_id')
            },

            {{-- Controlando la existencia, sin bodega no se sabe qué se puede despachar. --}}
            sinBodega() {
                return @js($config['controlar']) && this.sinBodegaEscogida()
            },

            recordar(id) {
                if (! this.ordenProductos.includes(Number(id))) {
                    this.ordenProductos.push(Number(id))
                }
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
                    this.recordar(l.producto_id)
                    this.fijarCant(l.producto_id, l.unidad, this.cant(l.producto_id, l.unidad) + Number(l.cantidad))
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

            {{-- Tocar un producto: suma 1 unidad y su fila queda abierta para poner cajas o unidades. Sin disponible (controlando la existencia) no entra: avisa. --}}
            agregar(p) {
                if (this.bloqueado(p)) {
                    this.sinExistencia = p.descripcion
                    clearTimeout(this.esperaSinExistencia)
                    this.esperaSinExistencia = setTimeout(() => this.sinExistencia = '', 3500)

                    return
                }

                this.sinExistencia = ''
                const id = Number(p.id)
                this.productos[id] = p
                this.recordar(id)
                this.cambiarCant(id, 'unidad', 1)
                this.abierta = id
                this.resaltar(id)
                this.cerrarBusqueda()
                this.$refs.buscador.blur()
            },

            abrir(id) {
                this.abierta = this.abierta === id ? null : id
            },

            resaltar(id) {
                this.resaltada = id
                setTimeout(() => {
                    if (this.resaltada === id) {
                        this.resaltada = null
                    }
                }, 900)
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
                <div class="pv-aviso-sin" x-show="sinExistencia !== ''" x-cloak>
                    {{ svg('heroicon-m-no-symbol') }}
                    <span x-text="'No hay existencia de ' + sinExistencia + ' en la bodega: no se puede agregar.'"></span>
                </div>

                <template x-for="p in resultados" x-bind:key="p.id">
                    <button type="button" class="pv-resultado" x-bind:class="{ 'pv-agotado': Number(p.hay ?? 0) <= 0, 'pv-bloqueado': bloqueado(p) }" x-bind:aria-disabled="bloqueado(p)" x-on:click="agregar(p)">
                        <span class="pv-resultado-texto">
                            <span class="pv-resultado-nombre" x-text="p.descripcion"></span>
                            <span class="pv-resultado-meta" x-text="p.codigo + ' · ' + dinero(p.precioUnidadIsv) + ' c/u' + (p.caja > 1 ? ' · caja de ' + p.caja + ' ' + dinero(p.precioCajaIsv) : '')"></span>
                            <span class="pv-resultado-lleva" x-show="lleva(p.id) !== ''" x-text="'Ya lleva ' + lleva(p.id) + ' · tocar suma 1 UN'"></span>
                        </span>
                        <span
                            class="pv-resultado-hay"
                            x-show="p.hay !== null && p.hay !== undefined"
                            x-bind:class="{ 'pv-hay-nada': Number(p.hay ?? 0) <= 0, 'pv-hay-poco': Number(p.hay ?? 0) > 0 && hayPoco(p) }"
                        >
                            <span x-text="etiquetaHay(p)[0]"></span>
                            <strong x-text="etiquetaHay(p)[1]"></strong>
                        </span>
                    </button>
                </template>

                <div class="pv-aviso" x-show="buscado && resultados.length === 0" x-text="sinBodega()
                    ? 'Escoja arriba la bodega que despacha para ver lo que hay.'
                    : 'No hay productos activos con ese código o nombre.'"></div>
                <div class="pv-aviso" x-show="! buscado && buscar.trim().length === 1">Escriba al menos 2 letras (o el código).</div>
            </div>
        </div>

        {{-- Último pedido de este cliente (al crear, con la lista vacía) --}}
        <div class="pv-ultimo" x-show="ultimo && lineas.length === 0" x-cloak>
            <div>
                <div class="pv-ultimo-titulo" x-text="'Último pedido de este cliente: ' + (ultimo?.numero ?? '') + ' del ' + (ultimo?.fecha ?? '')"></div>
                <div class="pv-ultimo-detalle" x-text="(ultimo ? Object.keys(ultimo.productos).length : 0) + ' productos, con los precios de hoy' + (ultimo?.fuera ? ' (' + ultimo.fuera + (@js($config['controlar']) ? ' no se agregan: ya no se venden o no hay disponible en la bodega)' : ' ya no se venden)') : '') + '.'"></div>
            </div>
            <button type="button" x-on:click="repetirUltimo()">Agregar los mismos productos</button>
        </div>

        {{-- Lista: un producto por fila; lo último que se agregó queda arriba --}}
        <div class="pv-vacio" x-show="lineas.length === 0" @if ($productosIniciales > 0) style="display: none" @endif>
            Todavía no hay productos. Búsquelos arriba y tóquelos para agregarlos.
        </div>

        <div class="pv-lista-titulo" x-show="lineas.length > 0" @if ($productosIniciales === 0) style="display: none" @endif>
            <span x-text="grupos.length === 1 ? '1 producto en el pedido' : grupos.length + ' productos en el pedido'">{{ $productosIniciales === 1 ? '1 producto en el pedido' : "{$productosIniciales} productos en el pedido" }}</span>
            <span x-show="abierta === null" class="pv-aviso" style="padding: 0">Toque uno para cambiarlo</span>
        </div>

        <div class="pv-lista" x-show="lineas.length > 0" @if ($productosIniciales === 0) style="display: none" @endif>
            <template x-for="id in grupos" x-bind:key="id">
                <div class="pv-linea" x-bind:class="{ 'pv-resaltada': resaltada === id, 'pv-abierta': abierta === id }">
                    {{-- Corta: cajas y unidades, nombre, subtotal --}}
                    <button type="button" class="pv-corta" x-show="abierta !== id" x-on:click="abrir(id)">
                        <span class="pv-cants">
                            <span class="pv-cant pv-cant-caja" x-show="cant(id, 'caja') > 0" x-text="cant(id, 'caja') + ' CJ'"></span>
                            <span class="pv-cant" x-show="cant(id, 'unidad') > 0" x-text="cant(id, 'unidad') + ' UN'"></span>
                        </span>
                        <span class="pv-corta-nombre" x-text="nombreDe(id)"></span>
                        <span class="pv-linea-subtotal" x-text="dinero(subtotalDe(id))"></span>
                    </button>

                    {{-- Abierta: cajas y unidades del mismo producto --}}
                    <div class="pv-detalle" x-show="abierta === id">
                        <div class="pv-linea-arriba" x-on:click="abrir(id)">
                            <div class="pv-linea-nombre" x-text="nombreDe(id)"></div>
                            <div class="pv-linea-subtotal" x-text="dinero(subtotalDe(id))"></div>
                            <button type="button" class="pv-quitar" x-on:click.stop="quitarProducto(id)" aria-label="Quitar del pedido" title="Quitar">
                                {{ svg('heroicon-m-x-mark') }}
                            </button>
                        </div>

                        <div class="pv-linea-precio" x-text="precioDe(id)"></div>
                        <div class="pv-hay" x-show="hayDe(id) !== null" x-bind:class="{ 'pv-hay-poco': hayPoco(productos[id]), 'pv-hay-nada': (hayDe(id) ?? 0) <= 0 }" x-text="textoHay(id)"></div>
                        <div class="pv-tope" x-show="topado === id" x-cloak x-text="'No hay más: disponible ' + enCajas(Math.max(0, hayDe(id) ?? 0), productos[id]?.caja)"></div>

                        <div class="pv-cantidades">
                            <div class="pv-cantidad" x-show="tieneCajas(id)">
                                <span class="pv-cantidad-etiqueta" x-text="'Cajas de ' + (productos[id]?.caja ?? '')">Cajas</span>
                                <div class="pv-stepper">
                                    <button type="button" class="pv-paso" x-on:click="cambiarCant(id, 'caja', -1)" aria-label="Una caja menos">
                                        {{ svg('heroicon-m-minus') }}
                                    </button>
                                    <input
                                        type="number"
                                        inputmode="numeric"
                                        min="0"
                                        aria-label="Cajas"
                                        x-bind:value="cant(id, 'caja')"
                                        x-on:change="alEscribirCant($event, id, 'caja')"
                                        x-on:focus="$event.target.select()"
                                        x-on:keydown.enter.prevent="$event.target.blur()"
                                    />
                                    <button type="button" class="pv-paso" x-on:click="cambiarCant(id, 'caja', 1)" x-bind:disabled="maximoPara(id, 'caja') <= cant(id, 'caja')" aria-label="Una caja más">
                                        {{ svg('heroicon-m-plus') }}
                                    </button>
                                </div>
                            </div>

                            <div class="pv-cantidad">
                                <span class="pv-cantidad-etiqueta">Unidades</span>
                                <div class="pv-stepper">
                                    <button type="button" class="pv-paso" x-on:click="cambiarCant(id, 'unidad', -1)" aria-label="Una unidad menos">
                                        {{ svg('heroicon-m-minus') }}
                                    </button>
                                    <input
                                        type="number"
                                        inputmode="numeric"
                                        min="0"
                                        aria-label="Unidades"
                                        x-bind:value="cant(id, 'unidad')"
                                        x-on:change="alEscribirCant($event, id, 'unidad')"
                                        x-on:focus="$event.target.select()"
                                        x-on:keydown.enter.prevent="$event.target.blur()"
                                    />
                                    <button type="button" class="pv-paso" x-on:click="cambiarCant(id, 'unidad', 1)" x-bind:disabled="maximoPara(id, 'unidad') <= cant(id, 'unidad')" aria-label="Una unidad más">
                                        {{ svg('heroicon-m-plus') }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="pv-linea-controles">
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
                    <span x-text="grupos.length === 0 ? 'Agregue productos' : (grupos.length === 1 ? '1 producto' : grupos.length + ' productos')">{{ $productosIniciales === 0 ? 'Agregue productos' : ($productosIniciales === 1 ? '1 producto' : "{$productosIniciales} productos") }}</span><span x-show="ahorro > 0" @if ($resumen['ahorro'] <= 0) style="display: none" @endif> · <span class="pv-barra-ahorro" x-text="'ahorra ' + dinero(ahorro)">ahorra {{ lempiras($resumen['ahorro']) }}</span></span>
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
