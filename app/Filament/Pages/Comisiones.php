<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Comisiones\CerrarPeriodoComision;
use App\Domain\Comisiones\ComisionesDelPeriodo;
use App\Domain\Comisiones\PeriodoComision;
use App\Domain\Comisiones\ReabrirPeriodoComision;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Reportes\AlcanceReporte;
use App\Enums\FrecuenciaComision;
use App\Exports\ComisionesExcel;
use App\Filament\Resources\ComisionGrupos\ComisionGrupoResource;
use App\Filament\Support\Acceso;
use App\Models\Bodega;
use App\Models\ComisionGrupo;
use App\Models\ComisionPeriodo;
use App\Models\Configuracion;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Comisiones del periodo (quincena o mes): por vendedor y por grupo, la
 * venta neta facturada (sin ISV), el escalón que alcanzó, cuánto le falta
 * para el siguiente y lo que gana.
 *
 * Abierto, se calcula al momento. Administración lo cierra (paga) el último
 * día o después: desde ahí ya no cambia. Si se cerró por error, se reabre
 * el último. Sale en Excel para pagar.
 *
 * Administración ve todas las bodegas o una; el encargado, sus vendedores.
 * Las cuentas: App\Domain\Comisiones\ComisionesDelPeriodo.
 *
 * @property Schema $form
 */
class Comisiones extends Page
{
    protected string $view = 'filament.comisiones.comisiones';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 1;

    /**
     * @var array<string, mixed>
     */
    public array $filtros = [];

    public function getTitle(): string
    {
        return 'Comisiones';
    }

    public static function getNavigationLabel(): string
    {
        return 'Comisiones';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Comisiones';
    }

    public static function canAccess(): bool
    {
        return AlcanceReporte::puedeVer(Acceso::usuario(), 'View:Comisiones');
    }

    public function mount(): void
    {
        $this->form->fill([
            'periodo'   => PeriodoComision::actual()->clave(),
            'bodega_id' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('filtros')
            ->components([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    Select::make('periodo')
                        ->label('Periodo')
                        ->options(fn (): array => self::periodos())
                        ->selectablePlaceholder(false)
                        ->live(),
                    Select::make('bodega_id')
                        ->label('Bodega')
                        ->placeholder('Todas las bodegas')
                        ->options(fn (): array => Bodega::query()->orderBy('nombre')->pluck('nombre', 'id')->all())
                        ->live()
                        ->visible(fn (): bool => Acceso::veTodo()),
                ]),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('excel')
                ->label('Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(function (): BinaryFileResponse {
                    $calculo = $this->calculo();

                    return Excel::download(new ComisionesExcel($calculo, $this->nombreAlcance()), 'comisiones-'.$calculo->periodo->clave().'.xlsx');
                }),
            Action::make('cerrar')
                ->label('Cerrar y pagar')
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('success')
                ->visible(fn (): bool => Acceso::puede('Cerrar:Comisiones') && $this->calculo()->cierre() === null && $this->periodo()->sePuedeCerrar())
                ->modalHeading(fn (): string => 'Cerrar el '.$this->periodo()->texto())
                ->modalDescription(fn (): string => self::textoCierre($this->periodoCompleto()))
                ->modalSubmitActionLabel('Cerrar y pagar')
                ->schema([
                    Textarea::make('nota')
                        ->label('Nota')
                        ->placeholder('Opcional: pagado por transferencia el 15…')
                        ->maxLength(255)
                        ->rows(2),
                ])
                ->action(function (array $data): void {
                    try {
                        $cierre = app(CerrarPeriodoComision::class)->ejecutar($this->periodo(), Acceso::usuario() ?? abort(403), is_string($data['nota'] ?? null) ? $data['nota'] : null);
                    } catch (ReglaNegocioException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Periodo cerrado')
                        ->body('Comisiones a pagar: '.lempiras($cierre->comision).'. Desde ahora ya no cambian.')
                        ->success()
                        ->send();
                }),
            Action::make('reabrir')
                ->label('Reabrir')
                ->icon(Heroicon::OutlinedLockOpen)
                ->color('danger')
                ->visible(fn (): bool => Acceso::puede('Cerrar:Comisiones') && ($this->calculo()->cierre()?->esElUltimo() ?? false))
                ->requiresConfirmation()
                ->modalHeading('¿Reabrir el periodo?')
                ->modalDescription('Se borra lo que se guardó al cerrarlo y vuelve a calcularse con las facturas y escalas de hoy. Úselo solo si se cerró por error y todavía no se pagó.')
                ->modalSubmitActionLabel('Sí, reabrir')
                ->action(function (): void {
                    $cierre = $this->calculo()->cierre();

                    if ($cierre === null) {
                        return;
                    }

                    try {
                        app(ReabrirPeriodoComision::class)->ejecutar($cierre);
                    } catch (ReglaNegocioException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Periodo reabierto')->success()->send();
                }),
            Action::make('frecuencia')
                ->label('Periodo de pago')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->visible(fn (): bool => Acceso::puede('Configurar:Comisiones'))
                ->modalHeading('Cada cuánto se pagan las comisiones')
                ->modalDescription('Las metas (escalones) son por periodo: si cambia de quincenal a mensual, revise los montos de los grupos. Lo ya cerrado no cambia.')
                ->fillForm(fn (): array => [
                    'comision_frecuencia' => Configuracion::actual()->comision_frecuencia->value,
                    'comision_corte'      => Configuracion::actual()->comision_corte,
                ])
                ->schema([
                    Radio::make('comision_frecuencia')
                        ->label('Se paga')
                        ->options(FrecuenciaComision::class)
                        ->required()
                        ->live(),
                    TextInput::make('comision_corte')
                        ->label('La primera quincena va del 1 al día')
                        ->numeric()
                        ->integer()
                        ->minValue(PeriodoComision::CORTE_MINIMO)
                        ->maxValue(PeriodoComision::CORTE_MAXIMO)
                        ->required()
                        ->helperText(fn (Get $get): string => is_numeric($get('comision_corte'))
                            ? 'Del 1 al '.(int) $get('comision_corte').' y del '.((int) $get('comision_corte') + 1).' a fin de mes.'
                            : 'Ej. 14: del 1 al 14 (se paga el 15) y del 15 a fin de mes.')
                        ->live(onBlur: true)
                        ->visible(fn (Get $get): bool => self::frecuencia($get('comision_frecuencia')) === FrecuenciaComision::Quincenal),
                ])
                ->action(function (array $data): void {
                    $frecuencia = self::frecuencia($data['comision_frecuencia'] ?? null);
                    $corte = is_numeric($data['comision_corte'] ?? null) ? (int) $data['comision_corte'] : Configuracion::actual()->comision_corte;

                    Configuracion::actual()->update([
                        'comision_frecuencia' => $frecuencia,
                        'comision_corte'      => max(PeriodoComision::CORTE_MINIMO, min(PeriodoComision::CORTE_MAXIMO, $corte)),
                    ]);

                    $this->filtros['periodo'] = PeriodoComision::actual()->clave();

                    Notification::make()->title('Periodo de pago guardado')->success()->send();
                }),
        ];
    }

    public function periodo(): PeriodoComision
    {
        return PeriodoComision::deClave($this->filtros['periodo'] ?? null) ?? PeriodoComision::actual();
    }

    /**
     * Lo de la pantalla: con la bodega escogida (administración) o la del encargado.
     */
    public function calculo(): ComisionesDelPeriodo
    {
        $usuario = Acceso::usuario() ?? abort(403);

        return new ComisionesDelPeriodo($this->periodo(), AlcanceReporte::bodega($usuario, $this->filtros['bodega_id'] ?? null)?->id);
    }

    public function nombreAlcance(): string
    {
        $usuario = Acceso::usuario() ?? abort(403);

        return AlcanceReporte::bodega($usuario, $this->filtros['bodega_id'] ?? null)->nombre ?? 'Todas las bodegas';
    }

    /**
     * Lo que muestra resources/views/filament/comisiones/comisiones.blade.php.
     *
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        $calculo = $this->calculo();

        return [
            'periodo'    => $calculo->periodo,
            'cierre'     => $calculo->cierre(),
            'vendedores' => $calculo->vendedores(),
            'totales'    => $calculo->totales(),
            'rezagadas'  => $calculo->rezagadas(),
            'anuladas'   => $calculo->anuladas(),
            'alcance'    => $this->nombreAlcance(),
            'hayGrupos'  => ComisionGrupo::query()->activos()->exists(),
            'urlGrupos'  => Acceso::puede('ViewAny:ComisionGrupo') ? ComisionGrupoResource::getUrl() : null,
        ];
    }

    /**
     * Los periodos del selector: los últimos (con el actual) y los ya cerrados.
     *
     * @return array<string, string>
     */
    public static function periodos(): array
    {
        $hoy = PeriodoComision::actual();
        $periodos = [];

        foreach (PeriodoComision::recientes(12) as $periodo) {
            $periodos[$periodo->clave()] = $periodo;
        }

        foreach (ComisionPeriodo::query()->orderByDesc('desde')->limit(36)->get() as $cierre) {
            $periodo = $cierre->periodo();
            $periodos[$periodo->clave()] = $periodo;
        }

        uasort($periodos, fn (PeriodoComision $a, PeriodoComision $b): int => $b->desde <=> $a->desde);
        $cerrados = ComisionPeriodo::query()->get(['desde', 'hasta'])
            ->mapWithKeys(fn (ComisionPeriodo $c): array => [$c->desde->format('Y-m-d').'_'.$c->hasta->format('Y-m-d') => true])
            ->all();

        return array_map(function (PeriodoComision $periodo) use ($hoy, $cerrados): string {
            $estado = match (true) {
                isset($cerrados[$periodo->clave()]) => 'cerrado',
                $periodo->clave() === $hoy->clave() => 'en curso',
                $periodo->desde->gt($hoy->desde)    => 'por venir',
                default                             => 'por cerrar',
            };

            return $periodo->texto().' · '.$estado;
        }, $periodos);
    }

    /**
     * Lo que se va a pagar, para la confirmación del cierre.
     */
    private static function textoCierre(ComisionesDelPeriodo $calculo): string
    {
        $t = $calculo->totales();

        return 'Se pagan '.lempiras($t['comision']).' de comisiones a '.$t['vendedores'].' vendedor(es), sobre '.lempiras($t['venta'])
            .' de venta neta facturada. Después ya no cambia: lo que se facture con fecha de este periodo o se anule pasa al siguiente.';
    }

    /**
     * El periodo con todos los vendedores (el cierre es de todas las bodegas).
     */
    private function periodoCompleto(): ComisionesDelPeriodo
    {
        return new ComisionesDelPeriodo($this->periodo());
    }

    private static function frecuencia(mixed $valor): FrecuenciaComision
    {
        if ($valor instanceof FrecuenciaComision) {
            return $valor;
        }

        return FrecuenciaComision::tryFrom(is_string($valor) ? $valor : '') ?? FrecuenciaComision::Quincenal;
    }
}
