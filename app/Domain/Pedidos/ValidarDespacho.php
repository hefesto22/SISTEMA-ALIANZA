<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Inventario\SalidaDePedido;
use App\Enums\EstadoPedido;
use App\Enums\PrecioAlDespachar;
use App\Enums\TipoMovimiento;
use App\Enums\UnidadVenta;
use App\Models\Configuracion;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Bodega confirma qué sale de cada línea. Lo que no hay se baja (hasta 0)
 * y el pedido se recalcula: eso es lo que le llega al cliente. Después de
 * esto el pedido queda Por imprimir.
 *
 * Precio (Configuración): el del día del despacho, o el del pedido salvo que
 * ya quede por debajo del costo (ahí el de hoy, para no vender a pérdida).
 *
 * Lo que sale se descuenta de la existencia de la bodega del pedido. Con
 * "controlar existencias" encendido no deja sacar más de lo que hay: no se
 * valida nada y se avisa qué producto no alcanza.
 *
 * Las promociones se vuelven a calcular con lo que de verdad sale: si ya no
 * se cumple la condición, el regalo se cobra. Se respetan las promociones con
 * que se levantó el pedido aunque se hayan desactivado después.
 */
final class ValidarDespacho
{
    public function __construct(
        private readonly RecalcularPedido $recalcular,
        private readonly SalidaDePedido $salida,
    ) {}

    /**
     * @param array<int, int|string|null> $despachado [id de línea => cantidad que sale]; si falta una línea sale completa
     * @param list<array{producto_id: int, unidad: UnidadVenta, cantidad: int}> $nuevas productos del pedido en la unidad que no traían (ver AjustarLineas)
     */
    public function ejecutar(Pedido $pedido, array $despachado, User $usuario, array $nuevas = []): Pedido
    {
        return DB::transaction(function () use ($pedido, $despachado, $usuario, $nuevas): Pedido {
            $pedido = Pedido::query()->lockForUpdate()->findOrFail($pedido->id);

            if (! $pedido->estaPendiente()) {
                throw new ReglaNegocioException("El pedido {$pedido->numero} ya está {$pedido->estado->getLabel()}; no se puede validar otra vez.");
            }

            AjustarLineas::aplicar($pedido, $despachado, faltaSaleCompleta: true, nuevas: $nuevas);

            $precioDelPedido = Configuracion::actual()->precio_al_despachar === PrecioAlDespachar::Pedido;

            $this->recalcular->ejecutar(
                $pedido,
                preciosVigentes: ! $precioDelPedido,
                respetarPromocionesDelPedido: true,
                sinPerdida: $precioDelPedido,
            );

            $this->salida->ejecutar($pedido, TipoMovimiento::Despacho, $usuario);

            $pedido->forceFill([
                'estado'       => EstadoPedido::PorImprimir,
                'validado_por' => $usuario->id,
                'validado_at'  => now(),
            ])->save();

            return $pedido;
        });
    }
}
