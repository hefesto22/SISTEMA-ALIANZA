{{--
    Columna "Usados" de los CAI: cuántos números se han usado de los
    autorizados, una barra y el correlativo que sigue (o "agotado").
    Columna: App\Filament\Resources\Cais\CaiResource (ViewColumn "uso").
--}}
@php
    /** @var \App\Models\Cai $cai */
    $cai = $getRecord();
    $total = $cai->totalNumeros();
    $usados = min($cai->usados(), $total);
    $porciento = $total > 0 ? round($usados * 100 / $total, 1) : 0.0;
    $pocos = ! $cai->estaAgotado() && $cai->disponibles() <= max(10, (int) ($total * 0.05));
    $estado = $cai->estaAgotado() ? 'mal' : ($pocos ? 'ojo' : '');
@endphp

@once
    <style>
        .cu { --cu-tenue: var(--gray-500); --cu-fondo: var(--gray-200); min-width: 8.5rem; padding: 0.75rem; font-variant-numeric: tabular-nums; }
        .dark .cu { --cu-tenue: var(--gray-400); --cu-fondo: rgb(255 255 255 / 0.1); }
        .cu-txt { font-size: 0.875rem; line-height: 1.25rem; }
        .cu-txt b { font-weight: 600; }
        .cu-txt span, .cu-sig { color: var(--cu-tenue); }
        .cu-barra { height: 0.375rem; margin-top: 0.3rem; overflow: hidden; border-radius: 999px; background: var(--cu-fondo); }
        .cu-barra i { display: block; height: 100%; min-width: 0.375rem; border-radius: inherit; background: var(--primary-500); }
        .cu-barra.ojo i { background: var(--warning-500); }
        .cu-barra.mal i { background: var(--danger-500); }
        .cu-sig { margin-top: 0.25rem; font-size: 0.75rem; line-height: 1rem; }
        .cu-sig.mal { color: var(--danger-500); }
    </style>
@endonce

<div class="cu">
    <div class="cu-txt"><b>{{ number_format($usados) }}</b> <span>/ {{ number_format($total) }}</span></div>
    <div class="cu-barra {{ $estado }}"><i style="width: {{ $porciento }}%"></i></div>
    @if ($cai->estaAgotado())
        <div class="cu-sig mal">agotado</div>
    @else
        <div class="cu-sig">sigue {{ str_pad((string) $cai->siguiente, 8, '0', STR_PAD_LEFT) }}</div>
    @endif
</div>
