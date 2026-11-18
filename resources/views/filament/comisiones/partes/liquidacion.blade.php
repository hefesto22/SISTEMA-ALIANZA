{{--
    Cuerpo de Comisiones: estado del periodo, totales y la tarjeta de cada
    vendedor. $d = App\Filament\Pages\Comisiones::datos().
--}}
@php
    /** @var \App\Domain\Comisiones\PeriodoComision $periodo */
    $periodo = $d['periodo'];
    /** @var \App\Models\ComisionPeriodo|null $cierre */
    $cierre = $d['cierre'];
    $t = $d['totales'];
    $porciento = fn (float $p): string => \App\Domain\Comisiones\Escala::porciento($p);
@endphp

<div class="rp" wire:loading.delay.class="rp-cargando">
    <div class="rp-encabezado" style="margin: 0">{{ $periodo->texto() }} · {{ $d['alcance'] }}</div>

    @if ($cierre !== null)
        <div class="cm-estado cerrado">
            <strong>Cerrado y pagado</strong>
            <span>el {{ $cierre->cerrado_at->format('d/m/Y h:i A') }}{{ $cierre->cerradoPor ? ' por '.$cierre->cerradoPor->name : '' }}. Esto es lo que se pagó: ya no cambia.</span>
            @if (filled($cierre->nota))
                <small>Nota: {{ $cierre->nota }}</small>
            @endif
        </div>
    @elseif ($periodo->enCurso())
        <div class="cm-estado abierto">
            <strong>En curso</strong>
            <span>Así va hasta hoy (día {{ $periodo->diasCorridos() }} de {{ $periodo->dias() }}). Se cierra el {{ $periodo->hasta->format('d/m/Y') }} o después.</span>
        </div>
    @elseif ($periodo->sePuedeCerrar())
        <div class="cm-estado pendiente">
            <strong>Por cerrar</strong>
            <span>Terminó el {{ $periodo->hasta->format('d/m/Y') }}. Al cerrarlo queda lo que se paga y ya no cambia.</span>
        </div>
    @else
        <div class="cm-estado">
            <strong>Por venir</strong>
            <span>Empieza el {{ $periodo->desde->format('d/m/Y') }}.</span>
        </div>
    @endif

    @if (! $d['hayGrupos'] && $cierre === null)
        <div class="rp-vacio">
            Todavía no hay grupos de comisión.
            @if ($d['urlGrupos'] !== null)
                <a href="{{ $d['urlGrupos'] }}" class="text-primary-600 underline dark:text-primary-400">Cree el primero</a>
                (ej. Limpieza): escoja sus categorías y ponga desde cuánto vende y qué % gana.
            @endif
        </div>
    @else
        <section class="rp-kpis">
            <div class="rp-kpi principal">
                <span>Comisiones {{ $cierre !== null ? 'pagadas' : 'a la fecha' }}</span>
                <strong>{{ lempiras($t['comision']) }}</strong>
                <small>{{ $t['vendedores'] === 1 ? '1 vendedor' : $t['vendedores'].' vendedores' }} con venta</small>
            </div>
            <div class="rp-kpi">
                <span>Venta neta facturada</span>
                <strong>{{ lempiras($t['venta']) }}</strong>
                <small>sin ISV, ya con descuentos</small>
            </div>
            <div class="rp-kpi">
                <span>En grupos con comisión</span>
                <strong>{{ lempiras($t['base']) }}</strong>
                <small>{{ $t['venta'] > 0 ? $porciento(round($t['base'] / $t['venta'] * 100, 1)).' de la venta' : 'sobre esto se paga' }}</small>
            </div>
            <div class="rp-kpi">
                <span>Facturas</span>
                <strong>{{ number_format($t['facturas']) }}</strong>
                <small>vigentes del periodo</small>
            </div>
        </section>

        @if ($d['rezagadas'] > 0 || $d['anuladas'] > 0)
            <div class="rp-aviso">
                @if ($d['rezagadas'] > 0)
                    {{ $d['rezagadas'] === 1 ? 'Entra 1 factura' : 'Entran '.$d['rezagadas'].' facturas' }} con fecha de un periodo ya cerrado (se emitieron después del cierre).
                @endif
                @if ($d['anuladas'] > 0)
                    Se {{ $d['anuladas'] === 1 ? 'descuenta 1 factura que ya se había pagado y se anuló' : 'descuentan '.$d['anuladas'].' facturas que ya se habían pagado y se anularon' }} (al corregir el pedido sale otra, que cuenta en su fecha).
                @endif
            </div>
        @endif

        <div class="cm-vendedores">
            @forelse ($d['vendedores'] as $v)
                @php
                    /** @var \App\Domain\Comisiones\ResumenVendedor $v */
                    $conAnulado = collect($v->filas)->contains(fn ($f) => $f->anulado != 0.0);
                @endphp
                <section class="cm-vendedor">
                    <header>
                        <div>
                            <strong>{{ $v->etiqueta() }}</strong>
                            <small>{{ $v->bodega ?? 'Sin bodega' }} · {{ $v->facturas === 1 ? '1 factura' : $v->facturas.' facturas' }} · venta {{ lempiras($v->ventaTotal) }} sin ISV</small>
                        </div>
                        <div class="cm-total">
                            <span>Comisión</span>
                            <strong>{{ lempiras($v->comision()) }}</strong>
                        </div>
                    </header>

                    @if ($v->filas === [])
                        <div class="rp-nota">Sin venta en grupos con comisión.</div>
                    @else
                        <div class="rp-tabla">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Grupo</th>
                                        <th class="n">Venta</th>
                                        @if ($conAnulado)
                                            <th class="n">Anuladas</th>
                                        @endif
                                        <th>Avance a la meta</th>
                                        <th class="n">%</th>
                                        <th class="n">Comisión</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($v->filas as $fila)
                                        <tr>
                                            <td class="nombre">
                                                {{ $fila->grupo }}@if ($fila->propia)<span class="cm-tag" title="{{ $fila->escala->texto() }}">meta propia</span>@endif
                                                <small>{{ $fila->facturas === 1 ? '1 factura' : $fila->facturas.' facturas' }}</small>
                                            </td>
                                            <td class="n">{{ lempiras($fila->venta) }}</td>
                                            @if ($conAnulado)
                                                <td @class(['n', 'neg' => $fila->anulado != 0.0])>{{ $fila->anulado != 0.0 ? '− '.lempiras($fila->anulado) : '—' }}</td>
                                            @endif
                                            <td>@include('filament.comisiones.avance', ['fila' => $fila])</td>
                                            <td class="n"><span @class(['cm-pct', 'llego' => $fila->llegoALaMeta()])>{{ $porciento($fila->porcentaje) }}</span></td>
                                            <td class="n"><strong>{{ lempiras($fila->comision) }}</strong></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @empty
                <div class="rp-vacio">No hay vendedores {{ $d['alcance'] === 'Todas las bodegas' ? '' : 'en '.$d['alcance'] }} con ventas en este periodo.</div>
            @endforelse
        </div>
    @endif
</div>
