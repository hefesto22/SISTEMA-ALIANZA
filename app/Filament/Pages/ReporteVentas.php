<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\Periodo;
use App\Domain\Reportes\VentasDelPeriodo;
use App\Exports\ReporteVentasExcel;
use App\Filament\Support\Acceso;
use App\Filament\Support\VistaImpresion;
use App\Models\Bodega;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reporte de ventas: totales y ganancia (con el detalle por día y por
 * bodega), por vendedor, por producto y por cliente. Se ve todo junto o cada
 * parte, y sale igual en Excel y en PDF (vista de impresión en un modal,
 * desde donde se imprime o se guarda el PDF).
 *
 * Administración saca el de todas las bodegas o el de una; el encargado,
 * solo el de su bodega. Las cuentas: App\Domain\Reportes\VentasDelPeriodo.
 *
 * @property Schema $form
 */
class ReporteVentas extends Page
{
    /** En pantalla, las tablas largas (productos, clientes) se cortan aquí; Excel y PDF traen todo. */
    public const LIMITE_PANTALLA = 150;

    protected string $view = 'filament.reportes.ventas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 1;

    /**
     * @var array<string, mixed>
     */
    public array $filtros = [];

    public function getTitle(): string
    {
        return 'Reporte de ventas';
    }

    public static function getNavigationLabel(): string
    {
        return 'Ventas';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Reportes';
    }

    public static function canAccess(): bool
    {
        return AlcanceReporte::puedeVer(Acceso::usuario(), 'View:ReporteVentas');
    }

    public function mount(): void
    {
        $this->form->fill([
            'periodo'   => 'mes',
            'desde'     => now()->startOfMonth()->toDateString(),
            'hasta'     => now()->toDateString(),
            'bodega_id' => null,
            'ver'       => 'todo',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('filtros')
            ->components([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    ToggleButtons::make('periodo')
                        ->label('Periodo')
                        ->options(Periodo::OPCIONES)
                        ->inline()
                        ->live()
                        ->columnSpanFull(),
                    DatePicker::make('desde')
                        ->label('Desde')
                        ->maxDate(now())
                        ->live()
                        ->visible(fn (Get $get): bool => $get('periodo') === 'otro'),
                    DatePicker::make('hasta')
                        ->label('Hasta')
                        ->maxDate(now())
                        ->live()
                        ->visible(fn (Get $get): bool => $get('periodo') === 'otro'),
                    Select::make('bodega_id')
                        ->label('Bodega')
                        ->placeholder('Todas las bodegas')
                        ->options(fn (): array => Bodega::query()->orderBy('nombre')->pluck('nombre', 'id')->all())
                        ->live()
                        ->visible(fn (): bool => Acceso::veTodo()),
                    ToggleButtons::make('ver')
                        ->label('Ver')
                        ->options(VentasDelPeriodo::VER)
                        ->inline()
                        ->live()
                        ->columnSpanFull(),
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
                    $reporte = $this->reporte();
                    $ver = $this->ver();

                    return Excel::download(new ReporteVentasExcel($reporte, $ver), $reporte->nombreArchivo($ver).'.xlsx');
                }),
            VistaImpresion::accion('Reporte de ventas', fn (): string => route('reportes.ventas.imprimir', [...$this->parametros(), 'embebido' => 1])),
        ];
    }

    public function reporte(): VentasDelPeriodo
    {
        $usuario = Acceso::usuario() ?? abort(403);

        return new VentasDelPeriodo(
            Periodo::de(self::texto($this->filtros['periodo'] ?? null), $this->filtros['desde'] ?? null, $this->filtros['hasta'] ?? null),
            AlcanceReporte::bodega($usuario, $this->filtros['bodega_id'] ?? null),
        );
    }

    /**
     * Lo que muestra la vista (resources/views/filament/reportes/ventas.blade.php).
     *
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        return $this->reporte()->datos($this->ver(), self::LIMITE_PANTALLA);
    }

    /**
     * Para la hoja de imprimir: las fechas ya resueltas, la bodega y lo que se ve.
     *
     * @return array<string, int|string>
     */
    public function parametros(): array
    {
        $reporte = $this->reporte();

        return array_filter([
            'desde'  => $reporte->periodo->desde->toDateString(),
            'hasta'  => $reporte->periodo->hasta->toDateString(),
            'bodega' => $reporte->bodega?->id,
            'ver'    => $this->ver(),
        ], fn (mixed $valor): bool => $valor !== null);
    }

    private function ver(): string
    {
        return VentasDelPeriodo::ver($this->filtros['ver'] ?? null);
    }

    private static function texto(mixed $valor): string
    {
        return is_string($valor) ? $valor : '';
    }
}
