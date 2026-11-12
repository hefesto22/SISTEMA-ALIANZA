{{--
    Colores de los reportes en el panel (claro y oscuro) y el aviso de que se
    está recalculando. Lo usan filament/reportes/ventas e inventario.
    El tema shadcn cambia en oscuro los gray/primary 500-700: ahí se usan los 400.
--}}
@include('reportes.partes.estilos')
<style>
    .fi-main .rp { --rp-texto: var(--gray-950); --rp-tenue: var(--gray-500); --rp-borde: var(--gray-200); --rp-suave: var(--gray-50); --rp-tarjeta: #fff; --rp-acento: var(--primary-600); --rp-mal: var(--danger-600); --rp-aviso: var(--warning-500); }
    .dark .fi-main .rp { --rp-texto: #fff; --rp-tenue: var(--gray-400); --rp-borde: rgb(255 255 255 / 0.1); --rp-suave: rgb(255 255 255 / 0.03); --rp-tarjeta: var(--gray-900); --rp-acento: var(--primary-400); --rp-mal: var(--danger-400); }
    .rp-encabezado { margin-bottom: 0.75rem; font-size: 0.9rem; font-weight: 600; color: var(--gray-500); }
    .dark .rp-encabezado { color: var(--gray-400); }
    .rp-cargando { opacity: 0.5; transition: opacity 0.15s; }
</style>
