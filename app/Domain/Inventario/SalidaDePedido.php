<?php

declare(strict_types=1);

namespace App\Domain\Inventario;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\TipoMovimiento;
use App\Models\Existencia;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Presentacion;
use App\Models\User;

/**
 * Lo que sale de la bodega con un pedido: al validar se saca lo que se
 * despacha; al corregir, solo la diferencia (si sale menos, regresa).
 *
 * Cada presentación sale de SU existencia: las CJ48 de las CJ48 y las UN de
 * las sueltas, con el costo de ese momento. Si para las sueltas hay que
 * abrir cajas, quien despacha dice cuáles ($abrir) y se abren antes de
 * sacar (AbrirCaja). Los regalos de promociones (bonificación) no los
 * escribe nadie: si las sueltas no alcanzan para ellos, se abre sola la
 * caja más chica del producto que sobre y alcance (abrirParaRegalos).
 *
 * Un movimiento por presentación aunque venga en varias líneas. Se procesan
 * en orden de producto y presentación para que dos despachos al mismo
 * tiempo bloqueen las existencias en el mismo orden.
 */
final class SalidaDePedido
{
    public function __construct(
        private readonly MoverExistencia $mover,
        private readonly AbrirCaja $abrirCaja,
        private readonly AbrirSiFaltan $abrirSiFaltan,
    ) {}

    /**
     * @param array<int, int> $antes lo que ya había salido por presentación (al corregir)
     * @param array<int, int> $abrir [presentacion_id de la caja => cuántas se abren] para tener sueltas
     * @param bool $abrirSolas para todas las sueltas (no solo los regalos), se abren solas las cajas que sobran
     */
    public function ejecutar(Pedido $pedido, TipoMovimiento $tipo, User $usuario, array $antes = [], array $abrir = [], bool $abrirSolas = false): void
    {
        $pedido->loadMissing(['items.presentacion.unidadMedida', 'items.producto']);

        $this->abrir($pedido, $abrir, $usuario);
        $this->abrirParaRegalos($pedido, $usuario, $antes, $abrirSolas);

        $ahora = self::porPresentacion($pedido);
        $ids = array_unique([...array_keys($antes), ...array_keys($ahora)]);

        /** @var array<int, Presentacion> $presentaciones */
        $presentaciones = Presentacion::query()->with(['producto', 'unidadMedida'])->whereKey($ids)->get()->keyBy('id')->all();

        usort($ids, fn (int $a, int $b): int => [$presentaciones[$a]->producto_id ?? 0, $a] <=> [$presentaciones[$b]->producto_id ?? 0, $b]);

        foreach ($ids as $id) {
            $sale = ($ahora[$id] ?? 0) - ($antes[$id] ?? 0);

            if ($sale === 0 || ! isset($presentaciones[$id])) {
                continue;
            }

            $this->mover->ejecutar(
                $pedido->bodega_id,
                $presentaciones[$id],
                -$sale,
                $tipo,
                $usuario,
                pedidoId: $pedido->id,
                nota: "PEDIDO {$pedido->numero}",
            );
        }
    }

    /**
     * Lo que sale en cada presentación (cada línea, lo que sale de ella).
     *
     * @return array<int, int>
     */
    public static function porPresentacion(Pedido $pedido): array
    {
        $cantidades = [];

        /** @var PedidoItem $item */
        foreach ($pedido->items as $item) {
            $cantidades[$item->presentacion_id] = ($cantidades[$item->presentacion_id] ?? 0) + $item->cantidadFacturable();
        }

        return $cantidades;
    }

    /**
     * Lo que de verdad ha salido con el pedido según el kárdex (despacho y
     * correcciones), por presentación. Se usa al corregir.
     *
     * @return array<int, int>
     */
    public static function yaSalido(Pedido $pedido): array
    {
        /** @var array<int, int> $salido */
        $salido = MovimientoInventario::query()
            ->where('pedido_id', $pedido->id)
            ->whereIn('tipo', [TipoMovimiento::Despacho, TipoMovimiento::Correccion])
            ->groupBy('presentacion_id')
            ->selectRaw('presentacion_id, -SUM(cantidad) as salido')
            ->pluck('salido', 'presentacion_id')
            ->map(fn (mixed $cantidad): int => (int) $cantidad)
            ->all();

        return $salido;
    }

    /**
     * Si al pedido ya se le sacó de la bodega (los validados antes de que
     * hubiera inventario no tienen salida, y al corregirlos no se devuelve nada).
     */
    public static function yaSalio(Pedido $pedido): bool
    {
        return MovimientoInventario::query()
            ->where('pedido_id', $pedido->id)
            ->where('tipo', TipoMovimiento::Despacho)
            ->exists();
    }

    /**
     * Abre las cajas que dijo quien despacha: solo de productos del pedido y
     * que sean cajas (no la base).
     *
     * @param array<int, int> $abrir
     */
    private function abrir(Pedido $pedido, array $abrir, User $usuario): void
    {
        $abrir = array_filter($abrir, fn (int $cajas): bool => $cajas > 0);

        if ($abrir === []) {
            return;
        }

        $productos = $pedido->items->map(fn (PedidoItem $item): int => (int) $item->producto_id)->unique()->all();
        $cajas = Presentacion::query()->with(['producto', 'unidadMedida'])->whereKey(array_keys($abrir))->get()->sortBy(fn (Presentacion $caja): array => [$caja->producto_id, $caja->id]);

        if ($cajas->count() !== count($abrir)) {
            throw new ReglaNegocioException('Una de las cajas que se iban a abrir ya no existe: vuelva a abrir el pedido.');
        }

        foreach ($cajas as $caja) {
            if (! in_array($caja->producto_id, $productos, true)) {
                throw new ReglaNegocioException("{$caja->producto->descripcion} no va en el pedido {$pedido->numero}: no se abren sus cajas.");
            }

            $this->abrirCaja->ejecutar($pedido->bodega_id, $caja, $abrir[$caja->id], $usuario, $pedido->id, "PEDIDO {$pedido->numero}: SE ABREN {$abrir[$caja->id]} {$caja->sigla()}");
        }
    }

    /**
     * Si las sueltas de la bodega no alcanzan para lo que sale de regalo en
     * la base (UN), abre solas las cajas del producto que sobran (AbrirSiFaltan).
     * Con $todas, para todo lo que sale suelto (venta en bodega). Sin cajas
     * que sobren no abre nada (sin controlar existencias, las sueltas quedan
     * en negativo).
     *
     * @param array<int, int> $antes lo que ya había salido por presentación (al corregir)
     */
    private function abrirParaRegalos(Pedido $pedido, User $usuario, array $antes, bool $todas = false): void
    {
        $regalos = [];

        /** @var PedidoItem $item */
        foreach ($pedido->items as $item) {
            if (($item->bonificacion || $todas) && $item->presentacion->es_base && $item->cantidadFacturable() > 0) {
                $regalos[$item->producto_id] = $item->presentacion;
            }
        }

        if ($regalos === []) {
            return;
        }

        $ahora = self::porPresentacion($pedido);
        $salen = [];

        foreach ($ahora as $presentacionId => $cantidad) {
            $salen[$presentacionId] = $cantidad - ($antes[$presentacionId] ?? 0);
        }

        foreach ($regalos as $productoId => $base) {
            $deRegalo = (int) $pedido->items
                ->filter(fn (PedidoItem $item): bool => ($item->bonificacion || $todas) && $item->presentacion_id === $base->id)
                ->sum(fn (PedidoItem $item): int => $item->cantidadFacturable());
            $falta = min($deRegalo, ($salen[$base->id] ?? 0) - Existencia::de($pedido->bodega_id, $base->id));

            $this->abrirSiFaltan->ejecutar($pedido->bodega_id, $productoId, $falta, $usuario, $pedido->id, $todas ? "PEDIDO {$pedido->numero}" : "PEDIDO {$pedido->numero} (REGALO)", $salen);
        }
    }
}
