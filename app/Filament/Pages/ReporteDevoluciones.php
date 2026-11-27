<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\DevolucionesDelPeriodo;
use App\Domain\Reportes\Periodo;
use App\Exports\ReporteDevolucionesExcel;
use App\Filament\Support\Acceso;
use App\Filament\Support\VistaImpresion;
use App\Models\Bodega;
use App\Models\Proveedor;
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
 * Reporte de devoluciones y pérdidas: lo que se registra cuando el que sale
 * a entregar regresa de la ruta. Las pérdidas (lo que regresó dañado, a
 * costo, por proveedor para reclamarlo), lo de cambio por cambio (lo que
 * regresó a la bodega y se le repuso al cliente) y cada devolución. Sale
 * igual en Excel y en PDF (vista de impresión en un modal).
 *
 * "Acta de baja": lo dañado del periodo para darlo de baja del inventario
 * con las firmas (una hoja por bodega; ImprimirReporteController::actaDeBaja).
 *
 * Lo ven quienes ven las devoluciones (administración, de todas las bodegas
 * o de una; el encargado, de la suya). Las cuentas:
 * App\Domain\Reportes\DevolucionesDelPeriodo.
 *
 * @property Schema $form
 */
class ReporteDevoluciones extends Page
{
    /** Permiso: el mismo de ver las devoluciones (no hace falta otro). */
    public const PERMISO = 'ViewAny:Devolucion';

    /** En pantalla, las tablas largas se cortan aquí; Excel y PDF traen todo. */
    public const LIMITE_PANTALLA = 150;

    protected string $view = 'filament.reportes.devoluciones';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static ?int $navigationSort = 5;

    /**
     * @var array<string, mixed>
     */
    public array $filtros = [];

    public function getTitle(): string
    {
        return 'Devoluciones y pérdidas';
    }

    public static function getNavigationLabel(): string
    {
        return 'Devoluciones y pérdidas';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Reportes';
    }

    public static function canAccess(): bool
    {
        return AlcanceReporte::puedeVer(Acceso::usuario(), self::PERMISO);
    }

    public function mount(): void
    {
        $this->form->fill([
            'periodo'      => DevolucionesDelPeriodo::periodo(request()->query('periodo')),
            'desde'        => Periodo::dia(request()->query('desde')) ?? now()->startOfMonth()->toDateString(),
            'hasta'        => Periodo::dia(request()->query('hasta')) ?? now()->toDateString(),
            'bodega_id'    => null,
            'proveedor_id' => null,
            'ver'          => DevolucionesDelPeriodo::ver(request()->query('ver')),
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
                        ->helperText(fn (Get $get): ?string => in_array($get('periodo'), ['quincena', 'quincena_pasada'], true) ? Periodo::ayudaQuincenas() : null)
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
                    Select::make('proveedor_id')
                        ->label('Proveedor')
                        ->placeholder('Todos los proveedores')
                        ->options(fn (): array => Proveedor::query()->orderBy('nombre')->get()->mapWithKeys(fn (Proveedor $p): array => [$p->id => $p->etiqueta()])->all())
                        ->searchable()
                        ->live()
                        ->helperText(fn (Get $get): ?string => filled($get('proveedor_id')) ? null : 'Escoja uno para sacar lo dañado que se le reclama.'),
                    Select::make('bodega_id')
                        ->label('Bodega')
                        ->placeholder('Todas las bodegas')
                        ->options(fn (): array => Bodega::query()->orderBy('nombre')->pluck('nombre', 'id')->all())
                        ->live()
                        ->visible(fn (): bool => Acceso::veTodo()),
                    ToggleButtons::make('ver')
                        ->label('Ver')
                        ->options(DevolucionesDelPeriodo::VER)
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

                    return Excel::download(new ReporteDevolucionesExcel($reporte, $ver), $reporte->nombreArchivo($ver).'.xlsx');
                }),
            VistaImpresion::accion('Acta de baja de inventario', fn (): string => route('reportes.devoluciones.acta', [...$this->parametros(), 'embebido' => 1]), 'acta', 'Acta de baja')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('gray')
                ->tooltip('Lo que regresó dañado en el periodo, para darlo de baja del inventario con las firmas')
                ->modalDescription('Lo dañado del periodo, a costo, con las firmas (una hoja por bodega). Lo que pida el SAR para deducir la pérdida, confírmelo con su contador.'),
            VistaImpresion::accion('Devoluciones y pérdidas', fn (): string => route('reportes.devoluciones.imprimir', [...$this->parametros(), 'embebido' => 1])),
        ];
    }

    public function reporte(): DevolucionesDelPeriodo
    {
        $usuario = Acceso::usuario() ?? abort(403);

        return new DevolucionesDelPeriodo(
            Periodo::de(DevolucionesDelPeriodo::periodo($this->filtros['periodo'] ?? null), $this->filtros['desde'] ?? null, $this->filtros['hasta'] ?? null),
            AlcanceReporte::bodega($usuario, $this->filtros['bodega_id'] ?? null),
            AlcanceReporte::proveedor($this->filtros['proveedor_id'] ?? null),
        );
    }

    /**
     * Lo que muestra la vista (resources/views/filament/reportes/devoluciones.blade.php).
     *
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        return $this->reporte()->datos($this->ver(), self::LIMITE_PANTALLA);
    }

    /**
     * Para la hoja de imprimir: las fechas ya resueltas, la bodega, el proveedor y lo que se ve.
     *
     * @return array<string, int|string>
     */
    public function parametros(): array
    {
        $reporte = $this->reporte();

        return array_filter([
            'desde'     => $reporte->periodo->desde->toDateString(),
            'hasta'     => $reporte->periodo->hasta->toDateString(),
            'bodega'    => $reporte->bodega?->id,
            'proveedor' => $reporte->proveedor?->id,
            'ver'       => $this->ver(),
        ], fn (mixed $valor): bool => $valor !== null);
    }

    private function ver(): string
    {
        return DevolucionesDelPeriodo::ver($this->filtros['ver'] ?? null);
    }
}
