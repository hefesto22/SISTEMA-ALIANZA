<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Pages;

use App\Enums\EstadoPedido;
use App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Filament\Support\Acceso;
use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
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
                ->dispatch(ImprimirPedidoAction::EVENTO, fn (): array => ImprimirPedidoAction::datos(ImprimirPedidoAction::urlDeVarios($this->idsPorImprimir())))
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
        $porImprimir = PedidoResource::getEloquentQuery()->where('estado', EstadoPedido::PorImprimir)->count();

        $partes = [
            match (true) {
                Acceso::esVendedor() => 'Sus pedidos',
                Acceso::veTodo()     => 'Todas las bodegas',
                default              => 'Vendedores de su bodega',
            },
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
     * Después de imprimir (resources/views/filament/imprimir-hoja.blade.php):
     * se vuelve a dibujar para que los impresos pasen a su pestaña.
     */
    #[On('hoja-impresa')]
    public function hojaImpresa(): void
    {
        $this->idsPorImprimir = null;
    }

    /**
     * Los que están Por imprimir en la tabla (con filtros y búsqueda) y que
     * esta persona puede imprimir.
     *
     * @return list<int>
     */
    private function idsPorImprimir(): array
    {
        return $this->idsPorImprimir ??= array_values(
            ($this->getFilteredTableQuery() ?? PedidoResource::getEloquentQuery())
                ->where('estado', EstadoPedido::PorImprimir)
                ->with('vendedor')
                ->orderBy('id')
                ->get()
                ->filter(fn (Pedido $pedido): bool => Gate::allows('imprimir', $pedido))
                ->map(fn (Pedido $pedido): int => $pedido->id)
                ->all(),
        );
    }
}
