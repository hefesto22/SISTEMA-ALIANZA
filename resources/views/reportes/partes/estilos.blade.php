{{--
    Estilos de los reportes (ventas e inventario valorizado), los mismos en
    pantalla y en la hoja de imprimir. Los colores son variables --rp-*: la
    pantalla las toma del panel (claro y oscuro) y la hoja usa blanco y negro.
--}}
<style>
    .rp { --rp-texto: #111827; --rp-tenue: #6b7280; --rp-borde: #e5e7eb; --rp-suave: #f9fafb; --rp-tarjeta: #fff; --rp-acento: #111827; --rp-mal: #b91c1c; --rp-aviso: #f59e0b; display: grid; gap: 1.25rem; color: var(--rp-texto); font-variant-numeric: tabular-nums; min-width: 0; }

    .rp-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr)); gap: 0.75rem; }
    .rp-kpi { min-width: 0; display: grid; align-content: start; gap: 0.15rem; padding: 0.85rem 1rem; border: 1px solid var(--rp-borde); border-radius: 0.75rem; background: var(--rp-tarjeta); }
    .rp-kpi span { font-size: 0.72rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--rp-tenue); }
    .rp-kpi strong { font-size: 1.3rem; line-height: 1.2; font-weight: 700; overflow-wrap: anywhere; }
    @media (max-width: 640px) { .rp-kpi { padding: 0.7rem 0.8rem; } .rp-kpi strong { font-size: 1.1rem; } }
    .rp-kpi small { font-size: 0.78rem; color: var(--rp-tenue); }
    .rp-kpi.principal strong { color: var(--rp-acento); }
    .rp-kpi strong.neg { color: var(--rp-mal); }

    .rp-aviso { padding: 0.6rem 0.85rem; border-radius: 0.6rem; font-size: 0.85rem; line-height: 1.45; border: 1px solid color-mix(in oklab, var(--rp-aviso) 45%, transparent); background: color-mix(in oklab, var(--rp-aviso) 12%, transparent); }
    .rp-nota { font-size: 0.8rem; color: var(--rp-tenue); }
    .rp-vacio { padding: 2.5rem 1rem; text-align: center; color: var(--rp-tenue); border: 1px dashed var(--rp-borde); border-radius: 0.75rem; }

    .rp-seccion { min-width: 0; display: grid; gap: 0.5rem; }
    .rp-seccion h2 { margin: 0; display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.25rem 0.6rem; font-size: 1rem; font-weight: 700; }
    .rp-seccion h2 small { font-size: 0.8rem; font-weight: 500; color: var(--rp-tenue); }

    .rp-tabla { min-width: 0; overflow-x: auto; border: 1px solid var(--rp-borde); border-radius: 0.75rem; background: var(--rp-tarjeta); }
    .rp table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .rp th { padding: 0.55rem 0.75rem; text-align: left; white-space: nowrap; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--rp-tenue); background: var(--rp-suave); border-bottom: 1px solid var(--rp-borde); }
    .rp td { padding: 0.5rem 0.75rem; vertical-align: top; border-bottom: 1px solid var(--rp-borde); }
    .rp tbody tr:last-child td { border-bottom: 0; }
    .rp tfoot td { font-weight: 700; background: var(--rp-suave); border-top: 1px solid var(--rp-borde); border-bottom: 0; }
    .rp .n, .rp .dia { white-space: nowrap; }
    .rp .n { text-align: right; }
    .rp th.n { text-align: right; }
    .rp .nombre { min-width: 11rem; }
    .rp .nombre small { display: block; font-size: 0.75rem; color: var(--rp-tenue); }
    .rp .cod { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace; font-size: 0.75rem; color: var(--rp-tenue); }
    .rp .neg { color: var(--rp-mal); }
    .rp .fijo { margin-inline-start: 0.3rem; padding: 0 0.3rem; font-size: 0.66rem; font-weight: 600; letter-spacing: 0.03em; text-transform: uppercase; color: var(--rp-tenue); border: 1px solid var(--rp-borde); border-radius: 0.25rem; }
</style>
