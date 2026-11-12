<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\InventarioValorizado;
use App\Exports\InventarioValorizadoExcel;
use App\Filament\Support\Acceso;
use App\Filament\Support\VistaImpresion;
use App\Models\Bodega;
use App\Models\Proveedor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Inventario valorizado: qué hay en la bodega, de qué productos y cuánto
 * dinero hay en ellos (al costo promedio y a precio de venta, con la
 * ganancia que dejaría). Sale igual en Excel y en PDF (vista de impresión
 * en un modal, desde donde se imprime o se guarda el PDF).
 *
 * Administración lo saca de todas las bodegas (con el resumen por bodega) o
 * de una; el encargado, solo de su bodega. Las cuentas:
 * App\Domain\Reportes\InventarioValorizado.
 *
 * @property Schema $form
 */
class ReporteInventario extends Page
{
    /** En pantalla el detalle se corta aquí; Excel y PDF traen todo. */
    public const LIMITE_PANTALLA = 300;

    protected string $view = 'filament.reportes.inventario';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 2;

    /**
     * @var array<string, mixed>
     */
    public array $filtros = [];

    public function getTitle(): string
    {
        return 'Inventario valorizado';
    }

    public static function getNavigationLabel(): string
    {
        return 'Inventario valorizado';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Reportes';
    }

    public static function canAccess(): bool
    {
        return AlcanceReporte::puedeVer(Acceso::usuario(), 'View:ReporteInventario');
    }

    public function mount(): void
    {
        $this->form->fill([
            'bodega_id'           => null,
            'proveedor_id'        => null,
            'buscar'              => '',
            'solo_con_existencia' => true,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('filtros')
            ->components([
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                    Select::make('bodega_id')
                        ->label('Bodega')
                        ->placeholder('Todas las bodegas')
                        ->options(fn (): array => Bodega::query()->orderBy('nombre')->pluck('nombre', 'id')->all())
                        ->live()
                        ->visible(fn (): bool => Acceso::veTodo()),
                    Select::make('proveedor_id')
                        ->label('Proveedor')
                        ->placeholder('Todos los proveedores')
                        ->options(fn (): array => Proveedor::query()->orderBy('nombre')->get()->mapWithKeys(fn (Proveedor $p): array => [$p->id => $p->etiqueta()])->all())
                        ->searchable()
                        ->live(),
                    TextInput::make('buscar')
                        ->label('Producto')
                        ->placeholder('Código o nombre')
                        ->live(debounce: 500),
                    Toggle::make('solo_con_existencia')
                        ->label('Solo lo que tiene existencia')
                        ->inline(false)
                        ->live(),
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

                    return Excel::download(new InventarioValorizadoExcel($reporte), $reporte->nombreArchivo().'.xlsx');
                }),
            VistaImpresion::accion('Inventario valorizado', fn (): string => route('reportes.inventario.imprimir', [...$this->parametros(), 'embebido' => 1])),
        ];
    }

    public function reporte(): InventarioValorizado
    {
        $usuario = Acceso::usuario() ?? abort(403);
        $proveedor = $this->filtros['proveedor_id'] ?? null;
        $buscar = $this->filtros['buscar'] ?? '';

        return new InventarioValorizado(
            AlcanceReporte::bodega($usuario, $this->filtros['bodega_id'] ?? null),
            is_numeric($proveedor) ? Proveedor::query()->find((int) $proveedor) : null,
            is_string($buscar) ? $buscar : '',
            (bool) ($this->filtros['solo_con_existencia'] ?? true),
        );
    }

    /**
     * Lo que muestra la vista (resources/views/filament/reportes/inventario.blade.php).
     *
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        return $this->reporte()->datos(self::LIMITE_PANTALLA);
    }

    /**
     * @return array<string, int|string>
     */
    public function parametros(): array
    {
        $reporte = $this->reporte();

        return array_filter([
            'bodega'    => $reporte->bodega?->id,
            'proveedor' => $reporte->proveedor?->id,
            'buscar'    => trim($reporte->buscar) === '' ? null : trim($reporte->buscar),
            'todos'     => $reporte->soloConExistencia ? null : 1,
        ], fn (mixed $valor): bool => $valor !== null);
    }
}
