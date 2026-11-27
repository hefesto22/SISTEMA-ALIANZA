{{--
    Aviso al liquidar (App\Filament\Resources\Cargas\Pages\Liquidacion): lo que
    se dio del camión en cambios tiene que haberle faltado a otro cliente de
    la carga (App\Domain\Devoluciones\CambiosDelCamion). Por producto, lo que
    no cuadra; si todo cuadra no sale nada.
    Datos: $filas (CambiosDelCamion::sinCuadrar()).
--}}
@php
    use App\Domain\Devoluciones\CambiosDelCamion;

    /** @var list<array{producto: string, dado: int, falto: int}> $filas */
@endphp

@if ($filas !== [])
    <style>
        .cdc { display: grid; gap: 0.35rem; padding: 0.8rem 1rem; border-radius: 0.65rem; border: 1px solid color-mix(in oklab, var(--warning-500) 45%, transparent); background: color-mix(in oklab, var(--warning-500) 8%, transparent); font-size: 0.875rem; }
        .cdc b { font-weight: 700; color: var(--warning-700); }
        .dark .cdc b { color: var(--warning-400); }
        .cdc ul { margin: 0; padding-inline-start: 1.1rem; }
        .cdc p { margin: 0; color: var(--gray-500); font-size: 0.8rem; }
        .dark .cdc p { color: var(--gray-400); }
    </style>

    <div class="cdc" role="status">
        <b>Lo que se dio del camión en cambios no cuadra con lo que les faltó a otros clientes</b>
        <ul>
            @foreach ($filas as $fila)
                <li>{{ CambiosDelCamion::texto($fila) }}</li>
            @endforeach
        </ul>
        <p>Al cliente al que al final le faltó, regístrelo en su factura: Devolución → «Le faltó producto» → «Se le dio a otro cliente en un cambio» (crédito o se le lleva en la próxima carga).</p>
    </div>
@endif
