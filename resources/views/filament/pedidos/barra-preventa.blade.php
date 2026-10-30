{{--
    Preventa (PedidoForm): total estimado y botón Guardar. En el teléfono y
    la tableta queda fija abajo, siempre a la vista; en pantalla grande va al
    final del formulario. Aquí también van los estilos de la preventa
    (tarjetas de productos, cantidad con − y +), sin compilar nada.

    Variables: $total, $lineas, $ahorro (o null), $edicion.
--}}
<style>
    /* Cabecera de cada producto: el nombre (se corta con …) y abajo cantidad · subtotal. */
    .fi-main .pv-items .fi-fo-repeater-item-header { gap: 0.5rem; }
    .fi-main .pv-items .fi-fo-repeater-item-header-label { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 0.05rem; }
    .pv-item-nombre { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; }
    .pv-item-vacio { color: var(--gray-500); font-weight: 500; }
    .pv-item-resumen { font-size: 0.75rem; font-weight: 500; color: var(--gray-500); font-variant-numeric: tabular-nums; }
    .dark .pv-item-vacio, .dark .pv-item-resumen { color: var(--gray-400); }

    /* Subtotal de la línea. */
    .pv-precio { display: flex; flex-direction: column; justify-content: center; min-height: 2.25rem; line-height: 1.3; }
    .pv-precio-linea { font-size: 1rem; font-weight: 700; font-variant-numeric: tabular-nums; }
    .pv-precio-unit { font-size: 0.75rem; color: var(--gray-500); }
    .dark .pv-precio-unit { color: var(--gray-400); }

    /* Cantidad con − y +: botones del tamaño del dedo, número al centro. */
    .fi-main .pv-cantidad .fi-input-wrp-prefix:not(.fi-inline) { padding-inline: 0.25rem; }
    .fi-main .pv-cantidad .fi-input-wrp-suffix:not(.fi-inline) { padding-inline: 0.25rem; }
    .fi-main .pv-cantidad .fi-input-wrp-actions .fi-icon-btn.fi-size-sm { width: 2.25rem; height: 2.25rem; margin: 0; }
    .pv-cantidad input { font-size: 1rem; font-weight: 600; font-variant-numeric: tabular-nums; -moz-appearance: textfield; }
    .pv-cantidad input::-webkit-outer-spin-button,
    .pv-cantidad input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

    /* Agregar producto: botón a lo ancho. */
    .fi-main .pv-items .fi-fo-repeater-add .fi-btn { width: 100%; }

    /* Barra del total. */
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
        .fi-main .pv-items .fi-fo-repeater-item-header { padding: 0.625rem 0.75rem; }
        .fi-main .pv-items .fi-fo-repeater-item-content { padding: 0.875rem 0.75rem; }
        .fi-main .pv-items .fi-fo-repeater-item-content .fi-sc.fi-sc-has-gap { gap: 1rem; }
    }

    /* Teléfono y tableta: la barra fija abajo. */
    @media (max-width: 1023.98px) {
        .pv-barra { position: fixed; inset-inline: 0; bottom: 0; z-index: 15; border-radius: 1rem 1rem 0 0; padding: 0.75rem 1rem calc(0.75rem + env(safe-area-inset-bottom)); outline: none; border-top: 1px solid var(--gray-200); box-shadow: 0 -6px 20px rgb(0 0 0 / 0.08); transition: transform 0.2s ease; }
        .dark .pv-barra { border-top-color: rgb(255 255 255 / 0.1); }
        .fi-main:has(.pv-barra) { padding-bottom: 6.5rem; }
        /* Con el teclado abierto se esconde, para ver lo que se escribe. */
        .fi-main:has(:is(input:not([type=radio]):not([type=checkbox]), textarea):focus) .pv-barra { transform: translateY(110%); }
    }
</style>

<div class="pv-barra">
    <div class="pv-barra-total">
        <span class="pv-barra-etiqueta">Total estimado</span>
        <span class="pv-barra-monto">{{ $total }}</span>
        <span class="pv-barra-detalle">
            @if ($lineas === 0)
                Agregue productos
            @else
                {{ $lineas }} {{ $lineas === 1 ? 'producto' : 'productos' }}
                @if ($ahorro)
                    · <span class="pv-barra-ahorro">ahorra {{ $ahorro }}</span>
                @endif
            @endif
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
