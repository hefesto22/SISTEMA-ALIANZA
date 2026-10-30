<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\EstadoPedido;
use App\Enums\MotivoCambioPrecio;
use App\Filament\Resources\CambiosPrecio\CambioPrecioResource;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Filament\Resources\Productos\ProductoResource;
use App\Filament\Resources\Promociones\PromocionResource;
use App\Filament\Support\Acceso;
use App\Models\CambioPrecio;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\Proveedor;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resumen del día en el escritorio, según quién mira:
 * el vendedor ve lo suyo, bodega lo de su bodega, administración todo.
 */
class ResumenOperacion extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected ?string $pollingInterval = '60s';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $esVendedor = Acceso::esVendedor();
        $pendientes = $this->pedidos()->where('estado', EstadoPedido::Pendiente)->count();

        // Lo validado hoy cuenta aunque el vendedor ya no vea esos pedidos.
        $validadosHoy = $this->pedidos(cualquierEstado: true)
            ->whereIn('estado', [EstadoPedido::PorImprimir, EstadoPedido::Impreso])
            ->whereDate('validado_at', today());

        $stats = [
            Stat::make('Pedidos por validar', $pendientes)
                ->description($pendientes > 0 ? 'Esperan validación de bodega' : 'Todo al día')
                ->descriptionIcon($pendientes > 0 ? Heroicon::OutlinedClock : Heroicon::OutlinedCheckBadge)
                ->color($pendientes > 0 ? 'warning' : 'success')
                ->url(PedidoResource::getUrl('index', $esVendedor ? [] : ['tab' => EstadoPedido::Pendiente->value])),
        ];

        // Lo que sigue después de validar ya es de bodega.
        if (! $esVendedor) {
            $porImprimir = $this->pedidos()->where('estado', EstadoPedido::PorImprimir)->count();

            $stats[] = Stat::make('Por imprimir', $porImprimir)
                ->description($porImprimir > 0 ? 'Validados, falta la hoja' : 'Nada en cola')
                ->descriptionIcon(Heroicon::OutlinedPrinter)
                ->color($porImprimir > 0 ? 'info' : 'success')
                ->url(PedidoResource::getUrl('index', ['tab' => EstadoPedido::PorImprimir->value]));
        }

        $stats[] = Stat::make('Validado hoy', lempiras((float) (clone $validadosHoy)->sum('total')))
            ->description((clone $validadosHoy)->count().' pedido(s) con ISV incluido')
            ->descriptionIcon(Heroicon::OutlinedShoppingCart)
            ->color('success');

        $promociones = Acceso::puede('ViewAny:Promocion') ? Promocion::query()->vigentes()->count() : 0;

        if ($promociones > 0) {
            $stats[] = Stat::make('Promociones vigentes', $promociones)
                ->description('Se aplican solas en la preventa')
                ->descriptionIcon(Heroicon::OutlinedGift)
                ->color('success')
                ->url(PromocionResource::getUrl('index'));
        }

        if (Acceso::puede('ViewAny:CambioPrecio')) {
            $cambiosHoy = CambioPrecio::query()
                ->whereDate('created_at', today())
                ->where('motivo', '!=', MotivoCambioPrecio::CargaInicial)
                ->count();

            $stats[] = Stat::make('Precios cambiados hoy', $cambiosHoy)
                ->description('Ver historial')
                ->descriptionIcon(Heroicon::OutlinedArrowTrendingUp)
                ->color('info')
                ->url(CambioPrecioResource::getUrl('index'));
        }

        $stats[] = Stat::make('Productos activos', Producto::query()->activos()->count())
            ->description(Proveedor::query()->whereHas('productos')->count().' proveedores')
            ->descriptionIcon(Heroicon::OutlinedTag)
            ->url(ProductoResource::getUrl('index'));

        return $stats;
    }

    /**
     * Los que ve (el vendedor: sus pendientes) o, con $cualquierEstado, todos
     * los que le tocan (el vendedor: todos los suyos).
     *
     * @return Builder<Pedido>
     */
    private function pedidos(bool $cualquierEstado = false): Builder
    {
        $usuario = Acceso::usuario();

        if ($usuario === null) {
            return Pedido::query()->whereRaw('1 = 0');
        }

        return $cualquierEstado
            ? Pedido::query()->delAlcanceDe($usuario)
            : Pedido::query()->visiblePara($usuario);
    }
}
