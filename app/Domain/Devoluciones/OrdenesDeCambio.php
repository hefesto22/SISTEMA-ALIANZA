<?php

declare(strict_types=1);

namespace App\Domain\Devoluciones;

use App\Enums\EstadoReposicion;
use App\Models\Reposicion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * La orden de cambio: lo que se le debe llevar a un cliente por un cambio
 * por cambio (Reposicion), como documento interno sin valor fiscal
 * (CAM-000001), para que se sepa que está pendiente de entregársele y que
 * él firme al recibir. Sigue el camino de las facturas:
 *
 *  - Por imprimir: se registró el cambio (RegistrarDevolucion) y todavía no
 *    se imprime su orden.
 *  - Por cargar (Pedidos → Impreso): impresa, espera carga. Solo las
 *    impresas se suben al camión (ArmarCarga, Reposiciones::paraCarga).
 *  - En ruta (Pedidos → Cargado): va en una carga; al liquidar, entregada o
 *    vuelve a esperar carga.
 *
 * El control legal del cambio es la factura original (no cambia de valor);
 * la orden es el control interno, con la firma de quien recibe.
 */
final class OrdenesDeCambio
{
    public const POR_IMPRIMIR = 'por_imprimir';

    public const POR_CARGAR = 'por_cargar';

    public const EN_RUTA = 'en_ruta';

    /**
     * Las órdenes que ve el usuario (las de su bodega; administración, todas)
     * en ese punto, de la más vieja a la más nueva.
     *
     * @return Builder<Reposicion>
     */
    public static function de(User $usuario, string $cuales): Builder
    {
        return self::visibles($usuario)
            ->when($cuales === self::POR_IMPRIMIR, fn (Builder $q): Builder => $q->where('estado', EstadoReposicion::PorEntregar->value)->whereNull('impresa_at'))
            ->when($cuales === self::POR_CARGAR, fn (Builder $q): Builder => $q->where('estado', EstadoReposicion::PorEntregar->value)->whereNotNull('impresa_at'))
            ->when($cuales === self::EN_RUTA, fn (Builder $q): Builder => $q->where('estado', EstadoReposicion::EnRuta->value))
            ->orderBy('id');
    }

    /**
     * Las de la bodega del usuario (administración: todas).
     *
     * @return Builder<Reposicion>
     */
    public static function visibles(User $usuario): Builder
    {
        return Reposicion::query()
            ->when(! $usuario->veTodasLasBodegas(), fn (Builder $q): Builder => $q->where('bodega_id', $usuario->bodega_id));
    }

    /**
     * Las marca impresas: la primera vez, la fecha (pasa a esperar carga);
     * cada vez, una impresión más (de la segunda en adelante es COPIA). Solo
     * las que ve el usuario.
     *
     * @param list<int> $ids
     *
     * @return list<int> las que se marcaron, en orden
     */
    public function imprimir(array $ids, User $usuario): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::transaction(function () use ($ids, $usuario): array {
            $ordenes = self::visibles($usuario)->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

            foreach ($ordenes as $orden) {
                $orden->forceFill([
                    'impresa_at'  => $orden->impresa_at ?? now(),
                    'impresiones' => $orden->impresiones + 1,
                ])->save();
            }

            return array_values(array_map(intval(...), $ordenes->modelKeys()));
        });
    }
}
