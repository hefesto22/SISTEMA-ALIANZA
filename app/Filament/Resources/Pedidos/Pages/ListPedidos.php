<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Pages;

use App\Enums\EstadoPedido;
use App\Enums\ModoImpresion;
use App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Filament\Support\Acceso;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

class ListPedidos extends ListRecords
{
    protected static string $resource = PedidoResource::class;

    /** @var list<int>|null */
    private ?array $idsPorImprimir = null;

    protected function getHeaderActions(): array
    {
        return [
            // En la pestaña Por imprimir: todos los que se ven (con filtros y búsqueda), uno por página.
            Action::make('imprimirTodos')
                ->label(fn (): string => 'Imprimir todos ('.count($this->idsPorImprimir()).')')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('info')
                ->requiresConfirmation()
                ->modalIcon(Heroicon::OutlinedPrinter)
                ->modalHeading(fn (): string => 'Imprimir '.count($this->idsPorImprimir()).' factura(s)')
                ->modalDescription('Salen como original, una por página, con los filtros y la búsqueda que tiene puestos. Si otra persona ya imprimió alguno, se salta.')
                ->modalSubmitActionLabel('Imprimir')
                ->action(function (): void {
                    ImprimirPedidoAction::ejecutar($this, $this->idsPorImprimir(), ModoImpresion::Originales);
                })
                ->visible(fn (): bool => $this->activeTab === EstadoPedido::PorImprimir->value && $this->idsPorImprimir() !== []),
            CreateAction::make()->label('Nueva preventa'),
        ];
    }

    /**
     * "Todas las bodegas · 6 pendientes · L. 4,250.00 por validar · 3 por imprimir · 1 fuera de ruta".
     */
    public function getSubheading(): string
    {
        $pendientes = PedidoResource::getEloquentQuery()->where('estado', EstadoPedido::Pendiente);

        $cuantos = (clone $pendientes)->count();
        $monto = (float) (clone $pendientes)->sum('total');
        $fuera = (clone $pendientes)->where('fuera_de_ruta', true)->count();

        // El vendedor solo ve sus pendientes: al validarlos, bodega se los lleva.
        if (Acceso::esVendedor()) {
            return "Sus pedidos por validar: {$cuantos} · ".lempiras($monto).'. Cuando bodega los valida ya no salen aquí.';
        }

        $porImprimir = PedidoResource::getEloquentQuery()->where('estado', EstadoPedido::PorImprimir)->count();

        $partes = [
            Acceso::veTodo() ? 'Todas las bodegas' : 'Vendedores de su bodega',
            $cuantos.' '.($cuantos === 1 ? 'pendiente' : 'pendientes'),
            lempiras($monto).' por validar',
            "{$porImprimir} por imprimir",
        ];

        if ($fuera > 0) {
            $partes[] = "{$fuera} fuera de ruta";
        }

        return implode(' · ', $partes);
    }

    /**
     * @return array<string|int, Tab>
     */
    public function getTabs(): array
    {
        // El vendedor solo tiene pendientes: sin pestañas.
        if (Acceso::esVendedor()) {
            return [];
        }

        $tabs = [];

        foreach (EstadoPedido::cases() as $estado) {
            $cantidad = PedidoResource::getEloquentQuery()->where('estado', $estado)->count();

            $tabs[$estado->value] = Tab::make($estado->getLabel())
                ->badge($cantidad > 0 && $estado !== EstadoPedido::Anulado ? (string) $cantidad : null)
                ->badgeColor($estado->getColor())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('estado', $estado));
        }

        $tabs['todos'] = Tab::make('Todos');

        return $tabs;
    }

    /**
     * Las pestañas cuentan pedidos y Filament las calcula al principio de cada
     * petición, antes de validar, corregir o imprimir. Se recalculan justo
     * antes de dibujar para que los números queden al día.
     */
    public function rendering(): void
    {
        unset($this->cachedTabs);
        $this->idsPorImprimir = null;
    }

    /**
     * Botones de los avisos ("Imprimir" al validar o corregir, "No salió").
     *
     * @param array<int|string, mixed> $ids
     */
    #[On(ImprimirPedidoAction::PEDIR)]
    public function imprimirPedidos(array $ids = [], string $modo = 'originales'): void
    {
        ImprimirPedidoAction::desdeAviso($this, $ids, $modo);
    }

    /**
     * Después de imprimir (resources/views/filament/imprimir-hoja.blade.php):
     * se vuelve a dibujar para que los impresos pasen a su pestaña.
     */
    #[On('hoja-impresa')]
    public function hojaImpresa(): void
    {
        $this->idsPorImprimir = null;
    }

    /**
     * Los que están Por imprimir en la tabla (con filtros y búsqueda). La
     * tabla ya muestra solo lo de su bodega (o todo a administración), así
     * que basta con que pueda validar/imprimir.
     *
     * @return list<int>
     */
    private function idsPorImprimir(): array
    {
        if (! Acceso::puede('Validar:Pedido')) {
            return $this->idsPorImprimir = [];
        }

        return $this->idsPorImprimir ??= array_values(array_map(
            intval(...),
            ($this->getFilteredTableQuery() ?? PedidoResource::getEloquentQuery())
                ->where('pedidos.estado', EstadoPedido::PorImprimir)
                ->reorder()
                ->orderBy('pedidos.id')
                ->pluck('pedidos.id')
                ->all(),
        ));
    }
}
