{{--
    Avance de un vendedor hacia la meta de un grupo: la barra (lo vendido
    hasta la meta, con una marca en cada escalón) y cuánto le falta para el
    escalón que sigue. $fila = App\Domain\Comisiones\FilaComision.
--}}
@php
    /** @var \App\Domain\Comisiones\FilaComision $fila */
    $meta = $fila->meta;
    $avance = min(1.0, $fila->avance() ?? 0.0);
    $siguiente = $fila->siguiente();
    $porciento = fn (float $p): string => \App\Domain\Comisiones\Escala::porciento($p);
@endphp

<div class="cm-avance">
    @if ($meta !== null)
        <div @class(['cm-barra', 'llego' => $fila->llegoALaMeta()]) role="progressbar" aria-valuenow="{{ (int) round($avance * 100) }}" aria-valuemin="0" aria-valuemax="100" aria-label="Avance hacia la meta de {{ $fila->grupo }}">
            <i style="width: {{ round($avance * 100, 1) }}%"></i>
            @foreach ($fila->escala->escalones as $escalon)
                @if ($escalon['desde'] > 0 && $escalon['desde'] < $meta)
                    <b style="inset-inline-start: {{ round($escalon['desde'] / $meta * 100, 1) }}%" title="{{ $porciento($escalon['porcentaje']) }} desde {{ lempiras($escalon['desde'], 0) }}"></b>
                @endif
            @endforeach
        </div>
    @endif

    @if ($fila->llegoALaMeta())
        <small class="llego">Meta cumplida ({{ lempiras($meta, 0) }})</small>
    @elseif ($siguiente !== null)
        <small>Le faltan {{ lempiras($fila->faltante() ?? 0) }} para el {{ $porciento($siguiente['porcentaje']) }}@if ($meta !== null) · {{ (int) floor($avance * 100) }}% de la meta @endif</small>
    @else
        <small>{{ $porciento($fila->porcentaje) }} sobre todo lo vendido</small>
    @endif
</div>
