<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Facturacion\AnularFactura;
use App\Domain\Inventario\SalidaDePedido;
use App\Enums\EstadoPedido;
use App\Enums\TipoMovimiento;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Corrige lo que sale de un pedido ya validado (por imprimir o impreso):
 * se quitan productos o se cambian cantidades. Los precios se quedan como
 * al validar; las promociones se recalculan con lo que sale.
 *
 * Queda Por imprimir. Si ya se había impreso, su factura queda ANULADA
 * (con el motivo) y al reimprimir se emite una nueva que la sustituye.
 * Si no cambió ninguna cantidad no se corrige: no se gasta un número.
 *
 * La existencia de la bodega se ajusta con la diferencia: lo que deja de
 * salir regresa y lo que sale de más se descuenta.
 */
final class CorregirPedido
{
    public function __construct(
        private readonly RecalcularPedido $recalcular,
        private readonly AnularFactura $anular,
        private readonly SalidaDePedido $salida,
    ) {}

    /**
     * @param array<int, int|string|null> $sale [id de línea => cantidad que sale]; si falta una línea se queda como estaba
     */
    public function ejecutar(Pedido $pedido, array $sale, User $usuario, ?string $motivo = null): Pedido
    {
        return DB::transaction(function () use ($pedido, $sale, $usuario, $motivo): Pedido {
            $pedido = Pedido::query()->lockForUpdate()->findOrFail($pedido->id);

            if (! $pedido->estaValidado()) {
                throw new ReglaNegocioException("El pedido {$pedido->numero} está {$pedido->estado->getLabel()}: solo se corrigen pedidos ya validados.");
            }

            $antes = self::cantidades($pedido);

            AjustarLineas::aplicar($pedido, $sale, faltaSaleCompleta: false);

            if (self::cantidades($pedido) === $antes) {
                throw new ReglaNegocioException("No cambió ninguna cantidad del pedido {$pedido->numero}: no hay nada que corregir.");
            }

            $this->recalcular->ejecutar($pedido, preciosVigentes: false, respetarPromocionesDelPedido: true);

            // Lo que ya salió se toma del kárdex. Los validados antes de que
            // hubiera inventario no sacaron nada: a esos no se les devuelve.
            if (SalidaDePedido::yaSalio($pedido)) {
                $this->salida->ejecutar($pedido, TipoMovimiento::Correccion, $usuario, SalidaDePedido::yaSalido($pedido));
            }

            $factura = $pedido->factura()->first();

            if ($factura !== null) {
                $this->anular->ejecutar($factura, $usuario, 'PEDIDO CORREGIDO'.(filled($motivo) ? ': '.trim((string) $motivo) : ''));
            }

            $pedido->forceFill([
                'estado'        => EstadoPedido::PorImprimir,
                'corregido_por' => $usuario->id,
                'corregido_at'  => now(),
            ])->save();

            return $pedido;
        });
    }

    /**
     * @return array<int, int>
     */
    private static function cantidades(Pedido $pedido): array
    {
        $cantidades = [];

        /** @var PedidoItem $item */
        foreach ($pedido->items as $item) {
            $cantidades[$item->id] = $item->cantidadFacturable();
        }

        return $cantidades;
    }
}
