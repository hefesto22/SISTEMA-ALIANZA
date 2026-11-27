{{--
    Un cliente en el celular: nombre y código, municipio, dirección y teléfono.
    Columna: App\Filament\Resources\Clientes\ClienteResource (ViewColumn "celular").
    Sin enlaces ni botones adentro: la tarjeta entera ya es uno, y los
    botones (Tomar pedido, Llamar) van abajo, en las acciones de la fila.
--}}
@php
    /** @var \App\Models\Cliente $cliente */
    $cliente = $getRecord();
    $municipio = $cliente->municipio;
@endphp

@once
    <style>
        /* En oscuro el tema shadcn cambia gray-500/600/700: el texto tenue usa gray-400 ahí. */
        .ct { --ct-tenue: var(--gray-500); display: grid; gap: 0.3rem; width: 100%; min-width: 0; text-align: start; white-space: normal; }
        .dark .ct { --ct-tenue: var(--gray-400); }
        .ct-arriba { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.6rem; }
        .ct-nombre { min-width: 0; font-size: 1rem; font-weight: 700; line-height: 1.3; overflow-wrap: anywhere; }
        .ct-codigo { flex-shrink: 0; margin-top: 0.1rem; padding: 0.05rem 0.45rem; border-radius: 0.375rem; font-size: 0.7rem; font-weight: 600; font-variant-numeric: tabular-nums; color: var(--ct-tenue); background: color-mix(in oklab, var(--gray-400) 15%, transparent); }
        .ct-linea { display: flex; align-items: flex-start; gap: 0.4rem; min-width: 0; font-size: 0.85rem; line-height: 1.35; overflow-wrap: anywhere; }
        .ct-linea svg { flex-shrink: 0; width: 1rem; height: 1rem; margin-top: 0.1rem; color: var(--ct-tenue); }
        .ct-tenue { font-size: 0.8rem; color: var(--ct-tenue); }
        .ct-inactivo { justify-self: start; padding: 0.05rem 0.45rem; border-radius: 0.375rem; font-size: 0.7rem; font-weight: 700; color: var(--danger-700); background: color-mix(in oklab, var(--danger-500) 12%, transparent); }
        .dark .ct-inactivo { color: var(--danger-400); }
    </style>
@endonce

<div class="ct">
    <div class="ct-arriba">
        <span class="ct-nombre">{{ $cliente->nombre }}</span>

        @if (filled($cliente->codigo))
            <span class="ct-codigo">{{ $cliente->codigo }}</span>
        @endif
    </div>

    <div @class(['ct-linea', 'ct-tenue' => $municipio === null])>
        {{ svg('heroicon-m-map-pin') }}
        <span>{{ $municipio === null ? 'Sin municipio' : $municipio->nombre.', '.$municipio->departamento->nombre }}</span>
    </div>

    @if (filled($cliente->direccion))
        <div class="ct-linea ct-tenue">
            {{ svg('heroicon-m-home') }}
            <span>{{ $cliente->direccion }}</span>
        </div>
    @endif

    @if (filled($cliente->telefono))
        <div class="ct-linea">
            {{ svg('heroicon-m-phone') }}
            <span>{{ $cliente->telefono }}</span>
        </div>
    @endif

    @unless ($cliente->activo)
        <span class="ct-inactivo">Inactivo</span>
    @endunless
</div>
