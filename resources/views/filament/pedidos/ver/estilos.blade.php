{{--
    Estilos de Ver pedido (resumen, productos y facturas). Se incluye en cada
    parte; @once lo deja una sola vez por página.
--}}
@once
    <style>
        /* En oscuro el tema shadcn cambia gray-500/600/700: el texto tenue usa gray-400 ahí. */
        .vp { --vp-tenue: var(--gray-500); --vp-linea: var(--gray-100); --vp-borde: rgb(3 7 18 / 0.08); min-width: 0; font-size: 0.875rem; }
        .dark .vp { --vp-tenue: var(--gray-400); --vp-linea: rgb(255 255 255 / 0.06); --vp-borde: rgb(255 255 255 / 0.1); }
        :where(.vp) :where(dl, dd, ul) { margin: 0; padding: 0; }
        .vp-tenue { font-size: 0.78rem; line-height: 1.45; color: var(--vp-tenue); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }

        /* ── Etiquetas de estado (como en la lista de pedidos) ── */
        .vp-pill { display: inline-block; font-size: 0.72rem; font-weight: 600; line-height: 1.4; padding: 0.1rem 0.5rem; border-radius: 0.375rem; border: 1px solid; white-space: nowrap; }
        .vp-pill.pendiente { color: var(--warning-700); border-color: color-mix(in oklab, var(--warning-500) 45%, transparent); background: color-mix(in oklab, var(--warning-500) 12%, transparent); }
        .vp-pill.por_imprimir { color: var(--info-700); border-color: color-mix(in oklab, var(--info-500) 45%, transparent); background: color-mix(in oklab, var(--info-500) 12%, transparent); }
        .vp-pill.reimprimir { color: var(--primary-700); border-color: color-mix(in oklab, var(--primary-500) 55%, transparent); background: color-mix(in oklab, var(--primary-500) 14%, transparent); }
        .vp-pill.impreso, .vp-pill.vigente { color: var(--success-700); border-color: color-mix(in oklab, var(--success-500) 45%, transparent); background: color-mix(in oklab, var(--success-500) 12%, transparent); }
        .vp-pill.anulado { color: var(--vp-tenue); border-color: var(--vp-borde); background: color-mix(in oklab, var(--gray-400) 12%, transparent); }
        .vp-pill.fuera, .vp-pill.anulada { color: var(--danger-700); border-color: color-mix(in oklab, var(--danger-500) 45%, transparent); background: color-mix(in oklab, var(--danger-500) 10%, transparent); }
        .dark .vp-pill.pendiente { color: var(--warning-400); }
        .dark .vp-pill.por_imprimir { color: var(--info-400); }
        .dark .vp-pill.reimprimir { color: var(--primary-400); }
        .dark .vp-pill.impreso, .dark .vp-pill.vigente { color: var(--success-400); }
        .dark .vp-pill.fuera, .dark .vp-pill.anulada { color: var(--danger-400); }

        /* ── Resumen ── */
        .vp-resumen { display: grid; gap: 1rem; }
        .vp-cabeza { display: grid; gap: 0.4rem; min-width: 0; }
        .vp-cliente { font-size: 1.125rem; font-weight: 700; line-height: 1.3; letter-spacing: 0.01em; overflow-wrap: anywhere; }
        .vp-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem; }
        .vp-tiempo { display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.78rem; color: var(--vp-tenue); }
        .vp-tiempo svg { width: 0.9rem; height: 0.9rem; }
        .vp-tel { display: inline-flex; align-items: center; gap: 0.3rem; margin-inline-end: 0.5rem; font-weight: 600; color: var(--primary-600); white-space: nowrap; }
        .dark .vp-tel { color: var(--primary-400); }
        .vp-tel svg { width: 0.95rem; height: 0.95rem; }

        /* Quién: 2 columnas en el teléfono, 4 en pantalla grande. */
        .vp-datos { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem 1rem; padding-top: 0.875rem; border-top: 1px solid var(--vp-linea); }
        @media (min-width: 768px) { .vp-datos { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        .vp-dato { min-width: 0; display: grid; align-content: start; gap: 0.1rem; }
        .vp-dato dt { font-size: 0.68rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; color: var(--vp-tenue); }
        .vp-dato-valor { font-weight: 600; line-height: 1.3; overflow-wrap: anywhere; }
        .vp-dato.vp-sin .vp-dato-valor { font-weight: 500; color: var(--vp-tenue); }
        .vp-dato-aviso { font-size: 0.72rem; font-weight: 700; color: var(--warning-600); }
        .dark .vp-dato-aviso { color: var(--warning-400); }

        /* Fuera de ruta, notas y factura por retirar. */
        .vp-aviso { display: grid; gap: 0.15rem; padding: 0.625rem 0.75rem; border-radius: 0.625rem; font-size: 0.8rem; line-height: 1.4; overflow-wrap: anywhere; }
        .vp-aviso strong { font-size: 0.68rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
        .vp-aviso-ruta { color: var(--warning-800); background: color-mix(in oklab, var(--warning-500) 12%, transparent); outline: 1px solid color-mix(in oklab, var(--warning-500) 40%, transparent); }
        .dark .vp-aviso-ruta { color: var(--warning-300); }
        .vp-aviso-retirar { color: var(--danger-700); background: color-mix(in oklab, var(--danger-500) 10%, transparent); outline: 1px solid color-mix(in oklab, var(--danger-500) 40%, transparent); }
        .dark .vp-aviso-retirar { color: var(--danger-300); }
        .vp-aviso-notas { background: var(--gray-50); outline: 1px solid var(--vp-borde); }
        .dark .vp-aviso-notas { background: rgb(255 255 255 / 0.04); }
        .vp-aviso-notas strong { color: var(--vp-tenue); }

        .vp-pie { display: flex; align-items: flex-end; justify-content: space-between; gap: 0.75rem; padding-top: 0.875rem; border-top: 1px solid var(--vp-linea); }
        .vp-pie-izq { min-width: 0; display: grid; gap: 0.1rem; }
        .vp-total { flex-shrink: 0; display: grid; justify-items: end; gap: 0.05rem; }
        .vp-total span { font-size: 0.72rem; font-weight: 500; color: var(--vp-tenue); }
        .vp-total strong { font-size: 1.5rem; font-weight: 800; line-height: 1.15; letter-spacing: -0.01em; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .vp-total.vp-anulado strong { text-decoration: line-through; color: var(--vp-tenue); }

        /* ── Productos: la misma fila que en la preventa ── */
        .vp-lista { display: grid; list-style: none; border-radius: 0.75rem; outline: 1px solid var(--vp-borde); overflow: hidden; }
        .vp-linea { display: flex; align-items: flex-start; gap: 0.625rem; padding: 0.7rem 0.875rem; border-bottom: 1px solid var(--vp-linea); }
        .vp-linea:last-child { border-bottom: 0; }
        .vp-cant { flex-shrink: 0; min-width: 3.6rem; padding: 0.3rem 0.45rem; border-radius: 0.5rem; text-align: center; font-size: 0.85rem; font-weight: 800; letter-spacing: 0.02em; line-height: 1.2; white-space: nowrap; font-variant-numeric: tabular-nums; color: var(--primary-700); background: color-mix(in oklab, var(--primary-500) 14%, transparent); outline: 1px solid color-mix(in oklab, var(--primary-500) 45%, transparent); }
        .dark .vp-cant { color: var(--primary-200); }
        .vp-cant.vp-cant-caja, .dark .vp-cant.vp-cant-caja { color: #fff; background: var(--primary-600); outline-color: var(--primary-600); }
        .vp-linea-texto { flex: 1 1 auto; min-width: 0; display: grid; gap: 0.15rem; }
        .vp-linea-nombre { font-size: 0.85rem; font-weight: 600; line-height: 1.3; overflow-wrap: anywhere; }
        .vp-linea-total { flex-shrink: 0; font-size: 0.9rem; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .vp-falta { font-size: 0.75rem; font-weight: 600; color: var(--danger-600); }
        .dark .vp-falta { color: var(--danger-400); }
        .vp-mas { font-size: 0.75rem; font-weight: 600; color: var(--info-600); }
        .dark .vp-mas { color: var(--info-400); }
        .vp-ahorro { font-size: 0.75rem; font-weight: 600; color: var(--success-600); }
        .dark .vp-ahorro { color: var(--success-400); }
        .vp-linea.vp-no-salio .vp-cant { opacity: 0.45; }
        .vp-linea.vp-no-salio .vp-linea-nombre, .vp-linea.vp-no-salio .vp-linea-total { color: var(--vp-tenue); text-decoration: line-through; }

        /* Totales como en un recibo: a la derecha en pantalla grande. */
        .vp-totales { display: grid; gap: 0.3rem; width: 100%; max-width: 20rem; margin: 1rem 0 0 auto; padding: 0 0.25rem; font-variant-numeric: tabular-nums; }
        .vp-totales > div { display: flex; justify-content: space-between; gap: 1rem; }
        .vp-totales dt { color: var(--vp-tenue); }
        .vp-totales dd { font-weight: 600; white-space: nowrap; }
        .vp-totales .vp-ahorro { font-size: inherit; }
        .vp-totales .vp-ahorro dt { color: inherit; }
        .vp-totales-total { margin-top: 0.2rem; padding-top: 0.5rem; border-top: 1px solid var(--vp-linea); }
        .vp-totales-total dt { color: inherit; font-weight: 700; }
        .vp-totales-total dd { font-size: 1.125rem; font-weight: 800; }

        /* ── Facturas ── */
        .vp-factura { display: grid; gap: 0.2rem; padding: 0.7rem 0.875rem; border-bottom: 1px solid var(--vp-linea); }
        .vp-factura:last-child { border-bottom: 0; }
        .vp-factura-arriba { display: flex; flex-wrap: wrap; align-items: center; gap: 0.35rem 0.6rem; }
        .vp-factura-numero { font-weight: 700; font-variant-numeric: tabular-nums; }
        .vp-factura-total { margin-inline-start: auto; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .vp-factura.vp-anulada .vp-factura-total { text-decoration: line-through; color: var(--vp-tenue); }

        /* Teléfono: menos relleno en las secciones para que quepa más. */
        @media (max-width: 639.98px) {
            .fi-main .pv > .fi-section:not(.fi-section-not-contained):not(.fi-aside) > .fi-section-header { padding: 0.875rem 1rem; }
            .fi-main .pv > .fi-section:not(.fi-section-not-contained):not(.fi-divided) > .fi-section-content-ctn > .fi-section-content { padding: 1rem; }
            .vp-linea, .vp-factura { padding: 0.65rem 0.75rem; }
            .vp-totales { max-width: none; padding: 0 0.75rem; }
        }
    </style>
@endonce
