{{--
    Estilo del formulario de devolución o faltante
    (App\Filament\Resources\Pedidos\Actions\DevolucionAction): las opciones
    (Qué pasó, Por qué le faltó, Qué se le da) como tarjetas que se marcan
    al escogerlas, y las cantidades alineadas a la derecha.
--}}
<style>
    .dev-tarjetas.fi-fo-radio { display: grid !important; grid-template-columns: repeat(auto-fit, minmax(12.5rem, 1fr)); gap: 0.75rem; margin-top: 0 !important; }
    .dev-tarjetas > .fi-fo-radio-label { align-self: stretch; margin: 0; padding: 0.8rem 0.95rem !important; border: 1px solid var(--gray-200); border-radius: 0.75rem; background: #fff; cursor: pointer; transition: border-color 0.15s, box-shadow 0.15s, background-color 0.15s; }
    .dark .dev-tarjetas > .fi-fo-radio-label { border-color: rgb(255 255 255 / 0.1); background: rgb(255 255 255 / 0.02); }
    .dev-tarjetas > .fi-fo-radio-label:hover { border-color: var(--primary-400); }
    .dev-tarjetas > .fi-fo-radio-label:has(.fi-radio-input:checked) { border-color: var(--primary-500); box-shadow: 0 0 0 1px var(--primary-500); background: color-mix(in oklab, var(--primary-500) 7%, transparent); }
    .dev-tarjetas .fi-fo-radio-label-text > p:first-child { font-weight: 600; }
    .dev-tarjetas .fi-fo-radio-label-description { font-size: 0.8125rem; line-height: 1.3rem; }
    .dev-numero { text-align: right; font-variant-numeric: tabular-nums; }
</style>
