{{--
    Estilos de Comisiones, Mis metas y KPIs de ventas (además de los rp-* de
    los reportes): estado del periodo, tarjeta de cada vendedor y la barra de
    avance hacia la meta con una marca en cada escalón. Claro y oscuro.
--}}
<style>
    .fi-main .rp, .cm-widget { --cm-bien: var(--success-600); --cm-camino: var(--primary-600); --cm-pista: var(--gray-200); --cm-info: var(--info-600); }
    .dark .fi-main .rp, .dark .cm-widget { --cm-bien: var(--success-400); --cm-camino: var(--primary-400); --cm-pista: rgb(255 255 255 / 0.1); --cm-info: var(--info-400); }

    .cm-estado { display: flex; flex-wrap: wrap; align-items: center; gap: 0.35rem 0.75rem; padding: 0.7rem 0.95rem; border-radius: 0.75rem; font-size: 0.875rem; line-height: 1.45; border: 1px solid var(--rp-borde); background: var(--rp-tarjeta); }
    .cm-estado strong { font-weight: 700; }
    .cm-estado.abierto { border-color: color-mix(in oklab, var(--cm-info) 40%, transparent); background: color-mix(in oklab, var(--cm-info) 8%, var(--rp-tarjeta)); }
    .cm-estado.pendiente { border-color: color-mix(in oklab, var(--rp-aviso) 50%, transparent); background: color-mix(in oklab, var(--rp-aviso) 10%, var(--rp-tarjeta)); }
    .cm-estado.cerrado { border-color: color-mix(in oklab, var(--cm-bien) 45%, transparent); background: color-mix(in oklab, var(--cm-bien) 8%, var(--rp-tarjeta)); }
    .cm-estado small { color: var(--rp-tenue); }

    .cm-vendedores { display: grid; gap: 1rem; }
    .cm-vendedor { min-width: 0; display: grid; gap: 0.6rem; }
    .cm-vendedor > header { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 0.25rem 1rem; }
    .cm-vendedor > header strong { font-size: 1rem; font-weight: 700; }
    .cm-vendedor > header small { display: block; font-size: 0.8rem; color: var(--rp-tenue); }
    .cm-total { text-align: right; }
    .cm-total span { display: block; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--rp-tenue); }
    .cm-total strong { font-size: 1.15rem !important; color: var(--rp-acento); }

    .cm-tag { display: inline-block; margin-inline-start: 0.35rem; padding: 0 0.35rem; font-size: 0.66rem; font-weight: 600; letter-spacing: 0.03em; text-transform: uppercase; color: var(--rp-tenue); border: 1px solid var(--rp-borde); border-radius: 0.25rem; vertical-align: middle; }
    .cm-pct { display: inline-block; min-width: 3rem; padding: 0.1rem 0.45rem; border-radius: 999px; text-align: center; font-weight: 700; font-size: 0.8rem; background: var(--rp-suave); border: 1px solid var(--rp-borde); }
    .cm-pct.llego { color: #fff; background: var(--cm-bien); border-color: transparent; }
    .dark .cm-pct.llego { color: var(--gray-950); }

    /* Barra de avance: lo vendido hasta la meta, con una marca en cada escalón. */
    .cm-avance { min-width: 13rem; display: grid; gap: 0.25rem; }
    .cm-barra { position: relative; height: 0.55rem; border-radius: 999px; background: var(--cm-pista); overflow: visible; }
    .cm-barra > i { position: absolute; inset-block: 0; inset-inline-start: 0; border-radius: 999px; background: var(--cm-camino); }
    .cm-barra.llego > i { background: var(--cm-bien); }
    .cm-barra > b { position: absolute; top: -0.2rem; bottom: -0.2rem; width: 2px; margin-inline-start: -1px; border-radius: 1px; background: var(--rp-tenue); opacity: 0.55; }
    .cm-avance small { font-size: 0.75rem; color: var(--rp-tenue); }
    .cm-avance small.llego { color: var(--cm-bien); font-weight: 600; }

    /* Mis metas: una tarjeta grande por grupo. */
    .cm-metas { display: grid; grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr)); gap: 1rem; }
    .cm-meta { min-width: 0; display: grid; gap: 0.75rem; padding: 1rem 1.1rem; border: 1px solid var(--rp-borde); border-radius: 0.9rem; background: var(--rp-tarjeta); }
    .cm-meta h3 { margin: 0; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; font-size: 1rem; font-weight: 700; }
    .cm-meta .cm-cifra { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.25rem 0.5rem; }
    .cm-meta .cm-cifra strong { font-size: 1.5rem; line-height: 1.15; font-weight: 800; }
    .cm-meta .cm-cifra span { font-size: 0.85rem; color: var(--rp-tenue); }
    .cm-meta .cm-barra { height: 0.8rem; }
    .cm-escalones { display: grid; gap: 0.3rem; margin: 0; padding: 0; list-style: none; font-size: 0.82rem; }
    .cm-escalones li { display: flex; justify-content: space-between; gap: 0.5rem; padding: 0.3rem 0.55rem; border-radius: 0.45rem; color: var(--rp-tenue); }
    .cm-escalones li.alcanzado { color: var(--rp-texto); background: var(--rp-suave); }
    .cm-escalones li.actual { color: var(--cm-bien); font-weight: 700; background: color-mix(in oklab, var(--cm-bien) 10%, transparent); }
    .cm-falta { font-size: 0.85rem; line-height: 1.4; }
    .cm-falta strong { color: var(--rp-acento); }

    /* Barras de participación en los KPIs. */
    .cm-parte { position: relative; min-width: 7rem; height: 0.45rem; border-radius: 999px; background: var(--cm-pista); }
    .cm-parte > i { position: absolute; inset-block: 0; inset-inline-start: 0; border-radius: 999px; background: var(--cm-camino); }
    .cm-var { font-size: 0.78rem; font-weight: 600; }
    .cm-var.sube { color: var(--cm-bien); }
    .cm-var.baja { color: var(--rp-mal); }
    .cm-dias { display: flex; align-items: flex-end; gap: 2px; height: 6rem; padding: 0.75rem; border: 1px solid var(--rp-borde); border-radius: 0.75rem; background: var(--rp-tarjeta); }
    .cm-dias > i { flex: 1 1 0; min-width: 2px; border-radius: 2px 2px 0 0; background: var(--cm-camino); opacity: 0.85; }
    .cm-dias > i.cero { background: var(--cm-pista); }
    .cm-columnas { display: grid; grid-template-columns: repeat(auto-fit, minmax(22rem, 1fr)); gap: 1.25rem; }
    .cm-columnas > .rp-seccion { align-content: start; }
    @media (max-width: 640px) { .cm-columnas { grid-template-columns: 1fr; } .cm-avance { min-width: 10rem; } }
</style>
