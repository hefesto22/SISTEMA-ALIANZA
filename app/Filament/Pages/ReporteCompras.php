<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\ComprasDelPeriodo;
use App\Domain\Reportes\Periodo;
use App\Exports\ReporteComprasExcel;
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
 * Reporte de compras: cuánto se le compró a cada proveedor y de qué, por
 * semana, quincena o mes (el que va o el pasado) u otras fechas. Con un
 * proveedor escogido sale el que se le manda: sus categorías, sus productos
 * (en la presentación en que entraron) y sus facturas. Sale igual en Excel y
 * en PDF (vista de impresión en un modal).
 *
 * Administración saca el de todas las bodegas o el de una; el encargado,
 * solo el de su bodega. Desde Proveedores se llega con el proveedor ya
 * escogido (?proveedor=5). Las cuentas: App\Domain\Reportes\ComprasDelPeriodo.
 *
 * @property Schema $form
 */
class ReporteCompras extends Page
{
    /** En pantalla, las tablas largas (productos, compras) se cortan aquí; Excel y PDF traen todo. */
    public const LIMITE_PANTALLA = 150;

    protected string $view = 'filament.reportes.compras';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 4;

    /**
     * @var array<string, mixed>
     */
    public array $filtros = [];

    public function getTitle(): string
    {
        return 'Reporte de compras';
    }

    public static function getNavigationLabel(): string
    {
        return 'Compras';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Reportes';
    }

    public static function canAccess(): bool
    {
        return AlcanceReporte::puedeVer(Acceso::usuario(), 'View:ReporteCompras');
    }

    /**
     * Enlace al reporte con el proveedor y el periodo ya escogidos (desde
     * Proveedores); con "otro", también las fechas.
     */
    public static function delProveedor(Proveedor $proveedor, string $periodo = 'mes', ?string $desde = null, ?string $hasta = null): string
    {
        $periodo = ComprasDelPeriodo::periodo($periodo);

        return self::getUrl(array_filter([
            'proveedor' => $proveedor->id,
            'periodo'   => $periodo,
            'desde'     => $periodo === 'otro' ? Periodo::dia($desde) : null,
            'hasta'     => $periodo === 'otro' ? Periodo::dia($hasta) : null,
        ], fn (mixed $valor): bool => $valor !== null));
    }

    public function mount(): void
    {
        $proveedor = request()->query('proveedor');

        $this->form->fill([
            'periodo'      => ComprasDelPeriodo::periodo(request()->query('periodo')),
            'desde'        => Periodo::dia(request()->query('desde')) ?? now()->startOfMonth()->toDateString(),
            'hasta'        => Periodo::dia(request()->query('hasta')) ?? now()->toDateString(),
            'bodega_id'    => null,
            'proveedor_id' => is_numeric($proveedor) && Proveedor::query()->whereKey((int) $proveedor)->exists() ? (int) $proveedor : null,
            'ver'          => 'todo',
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
                        ->options(ComprasDelPeriodo::PERIODOS)
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
                        ->helperText(fn (Get $get): ?string => filled($get('proveedor_id')) ? null : 'Escoja uno para sacar el que se le manda: sus productos y sus facturas.'),
                    Select::make('bodega_id')
                        ->label('Bodega')
                        ->placeholder('Todas las bodegas')
                        ->options(fn (): array => Bodega::query()->orderBy('nombre')->pluck('nombre', 'id')->all())
                        ->live()
                        ->visible(fn (): bool => Acceso::veTodo()),
                    ToggleButtons::make('ver')
                        ->label('Ver')
                        ->options(ComprasDelPeriodo::VER)
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

                    return Excel::download(new ReporteComprasExcel($reporte, $ver), $reporte->nombreArchivo($ver).'.xlsx');
                }),
            VistaImpresion::accion('Reporte de compras', fn (): string => route('reportes.compras.imprimir', [...$this->parametros(), 'embebido' => 1])),
        ];
    }

    public function reporte(): ComprasDelPeriodo
    {
        $usuario = Acceso::usuario() ?? abort(403);

        return new ComprasDelPeriodo(
            Periodo::de(ComprasDelPeriodo::periodo($this->filtros['periodo'] ?? null), $this->filtros['desde'] ?? null, $this->filtros['hasta'] ?? null),
            AlcanceReporte::bodega($usuario, $this->filtros['bodega_id'] ?? null),
            AlcanceReporte::proveedor($this->filtros['proveedor_id'] ?? null),
        );
    }

    /**
     * Lo que muestra la vista (resources/views/filament/reportes/compras.blade.php).
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
        return ComprasDelPeriodo::ver($this->filtros['ver'] ?? null);
    }
}
