<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\KpisVentas as Kpis;
use App\Domain\Reportes\Periodo;
use App\Enums\Rol;
use App\Filament\Support\Acceso;
use App\Models\Bodega;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/**
 * KPIs de ventas sobre lo facturado (sin ISV): venta, ticket promedio,
 * clientes, cómo va contra el periodo anterior; ranking de vendedores con
 * su cobertura de clientes; venta por grupo de comisión, productos más
 * vendidos, zonas (municipio) y la venta de cada día.
 *
 * Administración, de todas las bodegas o una; el encargado, de la suya.
 * Las cuentas: App\Domain\Reportes\KpisVentas.
 *
 * @property Schema $form
 */
class KpisVentas extends Page
{
    protected string $view = 'filament.comisiones.kpis';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 3;

    /**
     * @var array<string, mixed>
     */
    public array $filtros = [];

    public function getTitle(): string
    {
        return 'KPIs de ventas';
    }

    public static function getNavigationLabel(): string
    {
        return 'KPIs de ventas';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Reportes';
    }

    public static function canAccess(): bool
    {
        return AlcanceReporte::puedeVer(Acceso::usuario(), 'View:KpisVentas');
    }

    public function mount(): void
    {
        $this->form->fill([
            'periodo'     => 'mes',
            'desde'       => now()->startOfMonth()->toDateString(),
            'hasta'       => now()->toDateString(),
            'bodega_id'   => null,
            'vendedor_id' => null,
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
                    Select::make('vendedor_id')
                        ->label('Vendedor')
                        ->placeholder('Todos los vendedores')
                        ->options(fn (Get $get): array => self::vendedores($get('bodega_id')))
                        ->searchable()
                        ->live(),
                ]),
            ]);
    }

    public function kpis(): Kpis
    {
        $usuario = Acceso::usuario() ?? abort(403);
        $bodega = AlcanceReporte::bodega($usuario, $this->filtros['bodega_id'] ?? null);
        $vendedor = is_numeric($this->filtros['vendedor_id'] ?? null) ? (int) $this->filtros['vendedor_id'] : null;

        // El encargado solo ve a los vendedores de su bodega.
        if ($vendedor !== null && ! array_key_exists($vendedor, self::vendedores($bodega?->id))) {
            $vendedor = null;
        }

        return new Kpis(
            Periodo::de(is_string($this->filtros['periodo'] ?? null) ? $this->filtros['periodo'] : 'mes', $this->filtros['desde'] ?? null, $this->filtros['hasta'] ?? null),
            $bodega,
            $vendedor,
        );
    }

    /**
     * Lo que muestra resources/views/filament/comisiones/kpis.blade.php.
     *
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        $kpis = $this->kpis();

        return [
            'periodo'    => $kpis->periodo->texto(),
            'alcance'    => $kpis->bodega->nombre ?? 'Todas las bodegas',
            'resumen'    => $kpis->resumen(),
            'vendedores' => $kpis->vendedorId === null ? $kpis->vendedores() : [],
            'grupos'     => $kpis->grupos(),
            'productos'  => $kpis->productos(15),
            'zonas'      => $kpis->zonas(15),
            'dias'       => $kpis->porDia(),
        ];
    }

    /**
     * Vendedores (con su código) de la bodega escogida o de la del encargado.
     *
     * @return array<int, string>
     */
    private static function vendedores(mixed $bodegaId): array
    {
        $bodega = Acceso::veTodo() ? (is_numeric($bodegaId) ? (int) $bodegaId : null) : Acceso::usuario()?->bodega_id;

        return User::query()
            ->whereHas('roles', fn (Builder $r): Builder => $r->where('name', Rol::Vendedor->value))
            ->when($bodega !== null, fn (Builder $q): Builder => $q->where('bodega_id', $bodega))
            ->orderBy('codigo')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $u): array => [$u->id => filled($u->codigo) ? "{$u->codigo} · {$u->name}" : $u->name])
            ->all();
    }
}
