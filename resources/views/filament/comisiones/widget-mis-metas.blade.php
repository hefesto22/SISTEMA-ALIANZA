{{--
    Widget del escritorio del vendedor (App\Filament\Widgets\MisMetasWidget):
    en cada grupo, la barra hacia la meta y lo ganado; enlace a Mis metas.
--}}
@php
    $d = $this->datos();
    /** @var \App\Domain\Comisiones\ResumenVendedor|null $r */
    $r = $d['resumen'];
    $porciento = fn (float $p): string => \App\Domain\Comisiones\Escala::porciento($p);
    $titulo = 'Mis metas · '.$d['periodo']->texto();
    $quedan = match (true) {
        ! $d['enCurso']     => null,
        $d['quedan'] === 0  => 'Último día',
        $d['quedan'] === 1  => 'Queda 1 día',
        default             => 'Quedan '.$d['quedan'].' días',
    };
    $descripcion = $r === null ? null : ($quedan === null ? 'Ganado: ' : $quedan.' · ganado hasta hoy: ').lempiras($r->comision());
@endphp

<x-filament-widgets::widget>
    @include('filament.reportes.pantalla')
    @include('filament.comisiones.estilos')

    @if ($r !== null && $r->filas !== [])
        <x-filament::section icon="heroicon-o-trophy" :heading="$titulo" :description="$descripcion">
            <x-slot name="afterHeader">
                <x-filament::link :href="$d['url']" icon="heroicon-m-arrow-right" icon-position="after">Ver todo</x-filament::link>
            </x-slot>

            <div class="rp cm-widget">
                <div class="cm-metas" style="grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: 0.75rem">
                    @foreach ($r->filas as $fila)
                        <div class="cm-meta" style="gap: 0.5rem; padding: 0.8rem 0.9rem">
                            <h3 style="font-size: 0.9rem">
                                <span>{{ $fila->grupo }}</span>
                                <span @class(['cm-pct', 'llego' => $fila->llegoALaMeta()])>{{ $porciento($fila->porcentaje) }}</span>
                            </h3>
                            <div class="cm-cifra"><strong style="font-size: 1.15rem">{{ lempiras($fila->base) }}</strong><span>gana {{ lempiras($fila->comision) }}</span></div>
                            @include('filament.comisiones.avance', ['fila' => $fila])
                        </div>
                    @endforeach
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
