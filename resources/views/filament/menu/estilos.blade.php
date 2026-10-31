{{--
    Menú lateral más limpio (AdminPanelProvider, HEAD_END): en computadora
    queda como una columna con su fondo y una línea, que junto con la barra
    de arriba forman una "L"; grupos con título pequeño, renglones más
    compactos y el que está abierto marcado con el color del tema y una
    rayita a la orilla. Abajo, quién entró y de qué bodega (menu/pie).
--}}
<style>
    /* ── Columna ── */
    .fi-sidebar-nav { --menu-px: 0.75rem; gap: 1.25rem; padding: 1.25rem var(--menu-px) 1rem; }
    .fi-sidebar-nav-groups { margin-inline: 0; gap: 1.35rem; }

    @media (min-width: 1024px) {
        .fi-body-has-topbar .fi-sidebar { background: #fff; border-inline-end: 1px solid rgb(3 7 18 / 0.07); }
        .dark .fi-body-has-topbar .fi-sidebar { background: var(--gray-900); border-inline-end-color: rgb(255 255 255 / 0.07); }

        /* Cerrado (solo íconos): centrados. */
        .fi-body-has-sidebar-collapsible-on-desktop .fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-nav { --menu-px: calc((var(--collapsed-sidebar-width) - 2.375rem) / 2); }
        .fi-body-has-sidebar-collapsible-on-desktop .fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-group { gap: 0.15rem; }
    }

    /* ── Grupos ── */
    .fi-sidebar-group { gap: 0.15rem; }
    .fi-sidebar-group-btn { gap: 0.5rem; padding: 0 0.625rem 0.3rem; min-height: 1.5rem; }
    .fi-sidebar-group-label { font-size: 0.6875rem; line-height: 1rem; font-weight: 600; letter-spacing: 0.07em; text-transform: uppercase; color: var(--gray-400); }
    .dark .fi-sidebar-group-label { color: var(--gray-500); }
    .fi-sidebar-group-collapse-btn { width: 1.5rem; height: 1.5rem; margin: -0.25rem -0.375rem -0.25rem 0; opacity: 0.6; transition: opacity 0.15s ease; }
    .fi-sidebar-group-collapse-btn .fi-icon { width: 0.875rem; height: 0.875rem; }
    .fi-sidebar-group.fi-collapsed .fi-sidebar-group-label { color: var(--gray-500); }
    .dark .fi-sidebar-group.fi-collapsed .fi-sidebar-group-label { color: var(--gray-400); }

    /* Con mouse, la flechita sale al pasar por el grupo (o si está cerrado). */
    @media (hover: hover) {
        .fi-sidebar-group:not(.fi-collapsed) .fi-sidebar-group-collapse-btn { opacity: 0; }
        .fi-sidebar-group:hover .fi-sidebar-group-collapse-btn, .fi-sidebar-group-collapse-btn:focus-visible { opacity: 0.8; }
    }

    /* ── Renglones ── */
    .fi-sidebar-group-items { gap: 0.125rem; }
    .fi-sidebar-item-btn { gap: 0.7rem; padding: 0.5rem 0.625rem; border-radius: 0.5rem; }
    .fi-sidebar-item-btn > .fi-icon { width: 1.125rem; height: 1.125rem; color: var(--gray-400); transition: color 0.15s ease; }
    .dark .fi-sidebar-item-btn > .fi-icon { color: var(--gray-500); }
    .fi-sidebar-item-label { font-size: 0.875rem; line-height: 1.25rem; font-weight: 500; color: var(--gray-600); }
    .dark .fi-sidebar-item-label { color: var(--gray-300); }

    .fi-sidebar-item.fi-sidebar-item-has-url > .fi-sidebar-item-btn:hover { background: var(--gray-100); }
    .dark .fi-sidebar-item.fi-sidebar-item-has-url > .fi-sidebar-item-btn:hover { background: rgb(255 255 255 / 0.05); }
    .fi-sidebar-item-btn:hover > .fi-icon { color: var(--gray-600); }
    .dark .fi-sidebar-item-btn:hover > .fi-icon { color: var(--gray-300); }
    .fi-sidebar-item-btn:hover > .fi-sidebar-item-label { color: var(--gray-950); }
    .dark .fi-sidebar-item-btn:hover > .fi-sidebar-item-label { color: #fff; }

    /* El abierto: fondo del color del tema y la rayita a la orilla. */
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn,
    .fi-sidebar-item.fi-active.fi-sidebar-item-has-url > .fi-sidebar-item-btn:hover { background: color-mix(in oklab, var(--primary-500) 11%, transparent); }
    .dark .fi-sidebar-item.fi-active > .fi-sidebar-item-btn,
    .dark .fi-sidebar-item.fi-active.fi-sidebar-item-has-url > .fi-sidebar-item-btn:hover { background: color-mix(in oklab, var(--primary-400) 14%, transparent); }
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn > .fi-icon { color: var(--primary-600); }
    .dark .fi-sidebar-item.fi-active > .fi-sidebar-item-btn > .fi-icon { color: var(--primary-400); }
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn > .fi-sidebar-item-label { font-weight: 600; color: var(--primary-700); }
    .dark .fi-sidebar-item.fi-active > .fi-sidebar-item-btn > .fi-sidebar-item-label { color: var(--primary-300); }
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn::before { content: ''; position: absolute; inset-block: 0.45rem; inset-inline-start: calc(-1 * var(--menu-px, 0.75rem)); width: 3px; border-start-end-radius: 3px; border-end-end-radius: 3px; background: var(--primary-500); }

    /* Contador (pedidos por validar): pastilla. */
    .fi-sidebar-item-badge-ctn .fi-badge { min-width: 1.5rem; justify-content: center; padding: 0.05rem 0.45rem; border-radius: 999px; font-size: 0.7rem; font-weight: 700; font-variant-numeric: tabular-nums; }

    /* ── Pie: quién entró y su bodega ── */
    .mn-pie { display: flex; align-items: center; gap: 0.65rem; margin: 0 0.75rem 0.75rem; padding: 0.6rem; border-radius: 0.75rem; background: var(--gray-50); outline: 1px solid rgb(3 7 18 / 0.06); }
    .dark .mn-pie { background: rgb(255 255 255 / 0.04); outline-color: rgb(255 255 255 / 0.07); }
    .mn-pie .fi-avatar { flex-shrink: 0; }
    .mn-pie-texto { display: grid; min-width: 0; line-height: 1.2; }
    .mn-pie-nombre { font-size: 0.8125rem; font-weight: 600; color: var(--gray-900); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dark .mn-pie-nombre { color: #fff; }
    .mn-pie-detalle { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden; margin-top: 0.1rem; font-size: 0.72rem; line-height: 1.25; color: var(--gray-500); }
    .dark .mn-pie-detalle { color: var(--gray-400); }
    @media (min-width: 1024px) {
        .fi-body-has-sidebar-collapsible-on-desktop .fi-sidebar:not(.fi-sidebar-open) .mn-pie { justify-content: center; margin-inline: 0.5rem; padding: 0.5rem 0; background: transparent; outline: 0; }
    }

    /* ── Marca (sin logo cargado): iniciales + nombre ── */
    .mn-marca { display: inline-flex; align-items: center; gap: 0.6rem; height: 100%; }
    .mn-marca-sello { display: grid; place-items: center; width: 2rem; height: 2rem; border-radius: 0.5rem; background: var(--primary-600); color: #fff; font-size: 0.8rem; font-weight: 800; letter-spacing: 0.02em; box-shadow: inset 0 -2px 0 rgb(0 0 0 / 0.15); }
    .mn-marca-nombre { font-size: 1.05rem; font-weight: 700; letter-spacing: -0.01em; white-space: nowrap; }
</style>
