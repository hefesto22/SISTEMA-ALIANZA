<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Facturacion\AnularFactura;
use App\Domain\Inventario\MoverExistencia;
use App\Domain\Inventario\SalidaDePedido;
use App\Enums\EstadoPedido;
use App\Enums\TipoMovimiento;
use App\Models\Devolucion;
use App\Models\Pedido;
use App\Models\Presentacion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * El camión regresó con un pedido impreso que no se entregó (cliente
 * cerrado, no lo quiso) y se decidió anularlo: su factura queda ANULADA
 * (la hoja se guarda marcada), lo que salió regresa a la bodega (kárdex
 * "Corrección") y el pedido queda Anulado. No se cobra.
 *
 * Si ya tiene devoluciones (se entregó una parte), no se anula: lo que
 * regresó va como devolución.
 */
final class AnularNoEntregado
{
    /** Así empieza el motivo de la factura anulada (la carga lo usa para saber por qué se anuló). */
    public const MOTIVO = 'NO SE ENTREGÓ';

    public function __construct(
        private readonly MoverExistencia $mover,
        private readonly AnularFactura $anular,
    ) {}

    public function ejecutar(Pedido $pedido, User $usuario, string $motivo): Pedido
    {
        return DB::transaction(function () use ($pedido, $usuario, $motivo): Pedido {
            $pedido = Pedido::query()->lockForUpdate()->findOrFail($pedido->id);

            if ($pedido->estado !== EstadoPedido::Impreso) {
                throw new ReglaNegocioException("El pedido {$pedido->numero} está {$pedido->estado->getLabel()}: solo se anula así un pedido impreso que no se entregó.");
            }

            if (Devolucion::query()->where('pedido_id', $pedido->id)->exists()) {
                throw new ReglaNegocioException("El pedido {$pedido->numero} ya tiene devoluciones (se entregó una parte): no se anula; lo que regresó va como devolución.");
            }

            // Lo que salió de la bodega regresa, presentación por presentación.
            foreach (SalidaDePedido::yaSalido($pedido) as $presentacionId => $salio) {
                $presentacion = Presentacion::query()->with(['producto', 'unidadMedida'])->find($presentacionId);

                if ($salio <= 0 || ! $presentacion instanceof Presentacion) {
                    continue;
                }

                $this->mover->ejecutar(
                    $pedido->bodega_id,
                    $presentacion,
                    $salio,
                    TipoMovimiento::Correccion,
                    $usuario,
                    pedidoId: $pedido->id,
                    nota: "NO SE ENTREGÓ · PEDIDO {$pedido->numero}",
                );
            }

            $factura = $pedido->factura()->first();

            if ($factura !== null) {
                $this->anular->ejecutar($factura, $usuario, self::MOTIVO.': '.trim($motivo));
            }

            $pedido->forceFill([
                'estado' => EstadoPedido::Anulado,
                'notas'  => trim(($pedido->notas ?? '')."\nAnulado: no se entregó. ".trim($motivo)),
            ])->save();

            return $pedido;
        });
    }
}
