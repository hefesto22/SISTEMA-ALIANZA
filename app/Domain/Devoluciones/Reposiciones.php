<?php

declare(strict_types=1);

namespace App\Domain\Devoluciones;

use App\Domain\Inventario\AbrirSiFaltan;
use App\Domain\Inventario\MoverExistencia;
use App\Enums\EntregaFactura;
use App\Enums\EstadoReposicion;
use App\Enums\TipoMovimiento;
use App\Models\Carga;
use App\Models\CargaReposicion;
use App\Models\Devolucion;
use App\Models\Existencia;
use App\Models\Pedido;
use App\Models\Reposicion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Los cambios por entregar: lo que se le debe llevar a un cliente por un
 * cambio por cambio.
 *
 *  - Al registrar el cambio (RegistrarDevolucion) queda Por entregar: al
 *    mismo cliente o, si a él ya se le dio con producto del pedido de otro
 *    cliente, a ese otro (el que se quedó esperando). Es su orden de
 *    cambio (CAM-000001, OrdenesDeCambio): se imprime en Pedidos → Por
 *    imprimir y solo impresa se sube a una carga.
 *  - Al subirlo a una carga (ArmarCarga) sale de la bodega (kárdex
 *    "Reposición") y queda En ruta. Si se quita de la carga o la carga se
 *    borra, regresa a la bodega y vuelve a esperar.
 *  - Al liquidar la carga (LiquidarCarga): entregado, o no se entregó
 *    (regresa a la bodega y espera otra carga).
 *
 * Los movimientos del kárdex quedan con el pedido de la devolución (así se
 * deshacen si ese pedido de prueba se borra).
 */
final class Reposiciones
{
    /** Lo que se carga para mostrarlo (quién, su ruta y lo que se le lleva). */
    public const DATOS = ['cliente.municipio', 'pedido.ruta', 'pedido.vendedor', 'devolucion.factura', 'devolucion.items.presentacion.unidadMedida', 'devolucion.items.presentacion.producto'];

    public function __construct(
        private readonly MoverExistencia $mover,
        private readonly AbrirSiFaltan $abrirSiFaltan,
    ) {}

    /**
     * Lo que se le debe por el cambio $devolucion del pedido $pedido: a ese
     * cliente o al del pedido $seLeDebeA (su producto se le dio al primero).
     * En un faltante, lo que le faltó de su factura.
     */
    public function crear(Devolucion $devolucion, Pedido $pedido, ?Pedido $seLeDebeA = null): Reposicion
    {
        $para = $seLeDebeA ?? $pedido;

        return Reposicion::query()->create([
            'devolucion_id' => $devolucion->id,
            'bodega_id'     => $pedido->bodega_id,
            'cliente_id'    => $para->cliente_id,
            'pedido_id'     => $para->id,
            'estado'        => EstadoReposicion::PorEntregar,
            'nota'          => match (true) {
                $seLeDebeA !== null => mb_strtoupper("Se le quedó debiendo: lo de su pedido se le dio a {$pedido->cliente->nombre} ({$devolucion->numero})"),
                $devolucion->falto  => mb_strtoupper("Le faltó en la entrega ({$devolucion->numero})"),
                default             => null,
            },
        ]);
    }

    /**
     * Los que puede llevar una carga de la bodega: los que esperan carga con
     * su orden de cambio ya impresa (OrdenesDeCambio) y, al cambiar la carga
     * $cargaId, los que ya lleva. Del más viejo al más nuevo.
     *
     * @return Collection<int, Reposicion>
     */
    public static function paraCarga(int $bodegaId, ?int $cargaId = null): Collection
    {
        return Reposicion::query()
            ->where('bodega_id', $bodegaId)
            ->where(fn (Builder $q): Builder => $q
                ->where(fn (Builder $q): Builder => $q->where('estado', EstadoReposicion::PorEntregar->value)->whereNotNull('impresa_at'))
                ->when($cargaId !== null, fn (Builder $q): Builder => $q->orWhereHas('renglones', fn (Builder $r): Builder => $r
                    ->where('carga_id', $cargaId)
                    ->where('estado', EntregaFactura::EnRuta->value))))
            ->with(self::DATOS)
            ->orderBy('id')
            ->get();
    }

    /**
     * Lo sube al camión: sale de la bodega y queda en ruta. $reposicion debe
     * venir bloqueada y estar por entregar.
     */
    public function subir(Carga $carga, Reposicion $reposicion, User $usuario): void
    {
        $carga->cambios()->create(['reposicion_id' => $reposicion->id, 'estado' => EntregaFactura::EnRuta]);
        $this->mover($reposicion, -1, $usuario, "CARGA {$carga->numero()}");
        $reposicion->forceFill(['estado' => EstadoReposicion::EnRuta])->save();
    }

    /**
     * Lo saca de la carga (se quitó al cambiarla o la carga se borró): regresa
     * a la bodega y vuelve a esperar.
     */
    public function bajar(CargaReposicion $renglon, User $usuario): void
    {
        $reposicion = $renglon->reposicion;
        $this->mover($reposicion, 1, $usuario, "SE QUITÓ DE LA CARGA {$renglon->carga->numero()}");
        $renglon->delete();
        $reposicion->forceFill(['estado' => EstadoReposicion::PorEntregar])->save();
    }

    /**
     * Al liquidar: se le entregó.
     */
    public function entregar(CargaReposicion $renglon): void
    {
        $renglon->forceFill(['estado' => EntregaFactura::Entregada])->save();
        $renglon->reposicion->forceFill(['estado' => EstadoReposicion::Entregada, 'entregada_at' => now()])->save();
    }

    /**
     * Al liquidar: no se le entregó. Regresa a la bodega y espera otra carga.
     */
    public function noSeEntrego(CargaReposicion $renglon, User $usuario): void
    {
        $reposicion = $renglon->reposicion;
        $this->mover($reposicion, 1, $usuario, "NO SE ENTREGÓ EN LA CARGA {$renglon->carga->numero()}");
        $renglon->forceFill(['estado' => EntregaFactura::OtraCarga])->save();
        $reposicion->forceFill(['estado' => EstadoReposicion::PorEntregar])->save();
    }

    /**
     * Ya se le dio sin pasar por una carga (la operación de demostración):
     * sale de la bodega y queda entregada (su orden, como impresa).
     */
    public function entregarSinCarga(Reposicion $reposicion, User $usuario): void
    {
        $this->mover($reposicion, -1, $usuario, 'ENTREGADO');
        $reposicion->forceFill([
            'estado'       => EstadoReposicion::Entregada,
            'entregada_at' => now(),
            'impresa_at'   => $reposicion->impresa_at ?? now(),
            'impresiones'  => max(1, $reposicion->impresiones),
        ])->save();
    }

    /**
     * Saca ($signo = -1) o regresa (1) lo del cambio en la bodega. Si salen
     * sueltas y no alcanzan, se abre sola una caja (AbrirSiFaltan).
     */
    private function mover(Reposicion $reposicion, int $signo, User $usuario, string $detalle): void
    {
        $devolucion = $reposicion->devolucion;
        $nota = mb_strtoupper("Cambio {$devolucion->numero} · para {$reposicion->cliente->nombre} · {$detalle}");

        foreach ($reposicion->productos() as $fila) {
            $presentacion = $fila['presentacion'];

            if ($signo < 0 && $presentacion->es_base) {
                $falta = $fila['cantidad'] - Existencia::de($reposicion->bodega_id, $presentacion->id);
                $this->abrirSiFaltan->ejecutar($reposicion->bodega_id, $presentacion->producto_id, $falta, $usuario, $devolucion->pedido_id, $nota);
            }

            $this->mover->ejecutar($reposicion->bodega_id, $presentacion->refresh(), $signo * $fila['cantidad'], TipoMovimiento::Reposicion, $usuario, pedidoId: $devolucion->pedido_id, nota: $nota);
        }
    }
}
