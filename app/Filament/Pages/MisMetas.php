<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Comisiones\ComisionesDelPeriodo;
use App\Domain\Comisiones\FilaComision;
use App\Domain\Comisiones\PeriodoComision;
use App\Domain\Reportes\KpisVentas;
use App\Domain\Reportes\Periodo;
use App\Filament\Support\Acceso;
use App\Models\ComisionPeriodo;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Mis metas (el vendedor): en cada grupo de comisión cuánto lleva vendido
 * en el mes (la meta es del mes; en la segunda quincena se suma la
 * primera), el escalón en que va, cuánto le falta para el siguiente, lo que
 * lleva ganado y, a este ritmo, cómo cerraría el mes. Además sus clientes
 * atendidos, el ticket promedio y lo que más vende.
 *
 * Es lo mismo que ve administración en Comisiones, solo de él.
 *
 * @property Schema $form
 */
class MisMetas extends Page
{
    protected string $view = 'filament.comisiones.mis-metas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?int $navigationSort = 9;

    /**
     * @var array<string, mixed>
     */
    public array $filtros = [];

    public function getTitle(): string
    {
        return 'Mis metas';
    }

    public static function getNavigationLabel(): string
    {
        return 'Mis metas';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Ventas';
    }

    /**
     * Solo quien vende (administración ve las de todos en Comisiones).
     */
    public static function canAccess(): bool
    {
        return Acceso::esVendedor() && Acceso::puede('View:MisMetas');
    }

    public function mount(): void
    {
        $this->form->fill(['periodo' => PeriodoComision::actual()->clave()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('filtros')
            ->components([
                Select::make('periodo')
                    ->label('Periodo')
                    ->options(fn (): array => self::periodos())
                    ->selectablePlaceholder(false)
                    ->live()
                    ->extraAttributes(['class' => 'max-w-md']),
            ]);
    }

    /**
     * Lo que muestra resources/views/filament/comisiones/mis-metas.blade.php.
     *
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        $usuario = Acceso::usuario() ?? abort(403);

        return self::paraVendedor($usuario->id, PeriodoComision::deClave($this->filtros['periodo'] ?? null) ?? PeriodoComision::actual());
    }

    /**
     * Las metas de un vendedor en un periodo (también para el widget del escritorio).
     *
     * @return array<string, mixed>
     */
    public static function paraVendedor(int $vendedorId, PeriodoComision $periodo, bool $conKpis = true): array
    {
        $calculo = new ComisionesDelPeriodo($periodo, vendedorId: $vendedorId);
        $resumen = $calculo->vendedores()[0] ?? null;
        $hoy = CarbonImmutable::today();
        $corridos = $periodo->diasCorridos($hoy);
        $enCurso = $calculo->cierre() === null && $periodo->enCurso($hoy);
        // "A este ritmo" es del mes: desde el día en que empieza a contar hasta fin de mes.
        $mes = new PeriodoComision($calculo->cuentaDesde(), $periodo->desde->endOfMonth()->startOfDay(), $periodo->frecuencia);
        $kpis = $conKpis ? new KpisVentas(new Periodo($periodo->desde, $periodo->hasta), null, $vendedorId) : null;
        $suFila = $kpis === null ? null : (collect($kpis->vendedores())->firstWhere('id', $vendedorId));

        return [
            'periodo'   => $periodo,
            'cierre'    => $calculo->cierre(),
            'anterior'  => $calculo->cierre() === null ? $calculo->anteriorDelMes() : null,
            'resumen'   => $resumen,
            'enCurso'   => $enCurso,
            'quedan'    => max(0, $periodo->dias() - $corridos),
            'ritmo'     => $resumen === null ? [] : self::ritmo($resumen->filas, $enCurso ? $mes->diasCorridos($hoy) : 0, $mes->dias()),
            'kpis'      => $kpis?->resumen(),
            'cobertura' => $suFila['cobertura'] ?? null,
            'cartera'   => $suFila['cartera'] ?? null,
            'productos' => $kpis?->productos(5) ?? [],
        ];
    }

    /**
     * A este ritmo: si sigue vendiendo igual lo que queda del mes, cuánto
     * vendería en el mes en cada grupo, con qué % y cuánto ganaría en el mes.
     *
     * @param list<FilaComision> $filas
     *
     * @return array<int, array{venta: float, porcentaje: float, comision: float}>
     */
    private static function ritmo(array $filas, int $corridos, int $dias): array
    {
        if ($corridos <= 0 || $corridos >= $dias) {
            return [];
        }

        $ritmo = [];

        foreach ($filas as $fila) {
            if ($fila->baseMes() <= 0) {
                continue;
            }

            $venta = round($fila->baseMes() / $corridos * $dias, 2);
            $ritmo[$fila->grupoId] = ['venta' => $venta, 'porcentaje' => $fila->escala->porcentaje($venta), 'comision' => $fila->escala->comision($venta)];
        }

        return $ritmo;
    }

    /**
     * El actual y los anteriores (los cerrados dicen "pagado").
     *
     * @return array<string, string>
     */
    private static function periodos(): array
    {
        $cerrados = ComisionPeriodo::query()->get(['desde', 'hasta'])
            ->mapWithKeys(fn (ComisionPeriodo $c): array => [$c->desde->format('Y-m-d').'_'.$c->hasta->format('Y-m-d') => true])
            ->all();
        $opciones = [];

        foreach (PeriodoComision::recientes(6) as $i => $periodo) {
            $opciones[$periodo->clave()] = $periodo->texto().match (true) {
                $i === 0                            => ' · en curso',
                isset($cerrados[$periodo->clave()]) => ' · pagado',
                default                             => ' · por pagar',
            };
        }

        return $opciones;
    }
}
