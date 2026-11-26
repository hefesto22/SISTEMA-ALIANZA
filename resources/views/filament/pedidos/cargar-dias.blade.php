{{--
    Arriba del modal "Cargar camión" de Impresos (ListPedidos::cargarDiasAction):
    cuántas facturas lleva de cada día escogido (las que todavía se pueden
    cargar) y con cuánto sale. Abajo del modal van quién sale, la fecha y el camión.
    Datos: $carga = ListPedidos::paraCargar().
--}}
@php
    /** @var array{bodega: int|null, nombre: string, dias: list<string>, hasta: string|null, cuantas: int, total: float, porDia: list<array{dia: string, titulo: string, cuantas: int, total: float}>} $carga */
@endphp

<style>
    .cd { --cd-tenue: var(--gray-500); --cd-borde: var(--gray-200); --cd-suave: var(--gray-50); display: grid; gap: 0.75rem; }
    .dark .cd { --cd-tenue: var(--gray-400); --cd-borde: rgb(255 255 255 / 0.1); --cd-suave: rgb(255 255 255 / 0.03); }
    .cd-dias { margin: 0; padding: 0; list-style: none; border: 1px solid var(--cd-borde); border-radius: 0.75rem; overflow: hidden; }
    .cd-dias li { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; padding: 0.6rem 0.9rem; font-size: 0.875rem; }
    .cd-dias li + li { border-top: 1px solid var(--cd-borde); }
    .cd-dias span { color: var(--cd-tenue); font-variant-numeric: tabular-nums; white-space: nowrap; }
    .cd-dias li.cd-vacio b { color: var(--cd-tenue); font-weight: 500; }
    .cd-dias li.cd-suma { background: var(--cd-suave); font-weight: 700; }
    .cd-dias li.cd-suma span { color: inherit; }
    .cd-ayuda { margin: 0; font-size: 0.8rem; color: var(--cd-tenue); }
    .cd-nada { padding: 1.25rem 1rem; text-align: center; font-size: 0.875rem; color: var(--cd-tenue); border: 1px dashed var(--cd-borde); border-radius: 0.75rem; }
</style>

<div class="cd">
    @if ($carga['cuantas'] === 0)
        <div class="cd-nada">
            Esos días ya no tienen facturas para cargar: las que quedan ya van en otra carga o su día ya salió en su ruta.
        </div>
    @else
        <ul class="cd-dias">
            @foreach ($carga['porDia'] as $dia)
                <li @class(['cd-vacio' => $dia['cuantas'] === 0])>
                    <b>{{ $dia['titulo'] }}</b>
                    <span>
                        @if ($dia['cuantas'] === 0)
                            ya no quedan para cargar
                        @else
                            {{ $dia['cuantas'] === 1 ? '1 factura' : number_format($dia['cuantas']).' facturas' }} · {{ lempiras($dia['total']) }}
                        @endif
                    </span>
                </li>
            @endforeach
            <li class="cd-suma">
                <b>Sale con</b>
                <span>{{ $carga['cuantas'] === 1 ? '1 factura' : number_format($carga['cuantas']).' facturas' }} · {{ lempiras($carga['total']) }}</span>
            </li>
        </ul>
        <p class="cd-ayuda">Lleva todas las de esos días que no van en otra carga. Para dejar alguna, use «Escoger una por una».</p>
    @endif
</div>
