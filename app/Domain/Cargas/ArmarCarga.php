<?php

declare(strict_types=1);

namespace App\Domain\Cargas;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\EntregaFactura;
use App\Enums\EstadoCarga;
use App\Enums\EstadoFactura;
use App\Enums\EstadoPedido;
use App\Models\Carga;
use App\Models\CargaFactura;
use App\Models\Factura;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Arma (o cambia, mientras va en ruta) lo que sale en un camión: la bodega,
 * la fecha, quién sale, el camión y las facturas impresas que lleva.
 *
 * Cada factura: vigente, de esa bodega, de un pedido impreso y que no va en
 * otra carga ni se entregó ya. Las facturas se bloquean mientras se arma
 * para que dos cargas no se lleven la misma. El total de la carga es la
 * suma de lo que lleva: lo que debe regresar en dinero.
 *
 * Quién sale es cualquier usuario activo de la bodega (el que entrega no
 * tiene que ser el que vendió). El encargado solo arma cargas de su bodega.
 */
final class ArmarCarga
{
    /**
     * @param array{bodega_id: int, fecha: string, repartidor_id: int, camion?: string|null, facturas: list<int>} $datos
     */
    public function ejecutar(?Carga $carga, array $datos, User $usuario): Carga
    {
        $bodegaId = $usuario->veTodasLasBodegas() ? $datos['bodega_id'] : ($usuario->bodega_id ?? throw new ReglaNegocioException('Para armar una carga necesita tener una bodega asignada.'));
        $ids = array_values(array_unique($datos['facturas']));

        if ($ids === []) {
            throw new ReglaNegocioException('Escoja las facturas que lleva el camión.');
        }

        $repartidor = User::query()->whereKey($datos['repartidor_id'])->where('is_active', true)->first();

        if (! $repartidor instanceof User || ($repartidor->bodega_id !== $bodegaId && ! $repartidor->veTodasLasBodegas())) {
            throw new ReglaNegocioException('Quien sale en el camión tiene que ser un usuario activo de la bodega.');
        }

        return DB::transaction(function () use ($carga, $datos, $usuario, $bodegaId, $ids): Carga {
            if ($carga !== null) {
                $carga = Carga::query()->lockForUpdate()->findOrFail($carga->id);

                if (! $carga->enRuta()) {
                    throw new ReglaNegocioException("La carga {$carga->numero()} ya se liquidó: ya no se cambia.");
                }

                if ($carga->bodega_id !== $bodegaId) {
                    throw new ReglaNegocioException('Una carga no se cambia de bodega.');
                }
            }

            $facturas = Factura::query()->whereKey($ids)->with('pedido')->lockForUpdate()->get()->keyBy('id');

            foreach ($ids as $id) {
                $factura = $facturas->get($id);

                if (! $factura instanceof Factura || $factura->bodega_id !== $bodegaId) {
                    throw new ReglaNegocioException('Una de las facturas no es de esta bodega.');
                }

                if ($factura->estado !== EstadoFactura::Vigente || $factura->pedido->estado !== EstadoPedido::Impreso) {
                    throw new ReglaNegocioException("La factura {$factura->numero} ya no está vigente: no se puede cargar.");
                }

                $otra = CargaFactura::query()
                    ->where('factura_id', $id)
                    ->whereIn('estado', [EntregaFactura::EnRuta->value, EntregaFactura::Entregada->value])
                    ->where('carga_id', '!=', $carga->id ?? 0)
                    ->with('carga')
                    ->first();

                if ($otra instanceof CargaFactura) {
                    throw new ReglaNegocioException($otra->estado === EntregaFactura::Entregada
                        ? "La factura {$factura->numero} ya se entregó en la carga {$otra->carga->numero()}."
                        : "La factura {$factura->numero} ya va en la carga {$otra->carga->numero()}.");
                }
            }

            $carga ??= new Carga(['created_by' => $usuario->id, 'estado' => EstadoCarga::EnRuta]);
            $carga->fill([
                'bodega_id'     => $bodegaId,
                'fecha'         => Carbon::parse($datos['fecha'])->toDateString(),
                'repartidor_id' => $datos['repartidor_id'],
                'camion'        => filled($datos['camion'] ?? null) ? mb_strtoupper(trim((string) $datos['camion'])) : null,
            ])->save();

            // Las que se quitaron (las vigentes que van en ruta; la anulada al
            // corregir el pedido se queda, junto a la que la sustituye) y las nuevas.
            $carga->renglones()
                ->where('estado', EntregaFactura::EnRuta->value)
                ->whereNotIn('factura_id', $ids)
                ->whereHas('factura', fn (Builder $q): Builder => $q->where('estado', EstadoFactura::Vigente))
                ->delete();
            $ya = $carga->renglones()->pluck('factura_id')->map(fn (mixed $id): int => (int) $id)->all();

            foreach (array_diff($ids, $ya) as $id) {
                $carga->renglones()->create(['factura_id' => $id, 'estado' => EntregaFactura::EnRuta]);
            }

            return self::conTotal($carga);
        });
    }

    /**
     * La factura nueva que sustituye a una anulada al corregir el pedido sube
     * a la misma carga si la anulada iba en ruta: el camión la entrega.
     */
    public static function subirSustituta(Factura $nueva): void
    {
        if ($nueva->reemplaza_a_id === null) {
            return;
        }

        $renglon = CargaFactura::query()
            ->where('factura_id', $nueva->reemplaza_a_id)
            ->where('estado', EntregaFactura::EnRuta->value)
            ->first();

        if (! $renglon instanceof CargaFactura) {
            return;
        }

        CargaFactura::query()->firstOrCreate(
            ['carga_id' => $renglon->carga_id, 'factura_id' => $nueva->id],
            ['estado' => EntregaFactura::EnRuta],
        );

        self::conTotal($renglon->carga);
    }

    /**
     * El total de la carga: lo que lleva de facturas vigentes.
     */
    public static function conTotal(Carga $carga): Carga
    {
        $total = Factura::query()
            ->whereIn('id', CargaFactura::query()->where('carga_id', $carga->id)->select('factura_id'))
            ->where('estado', EstadoFactura::Vigente)
            ->sum('total');

        $carga->forceFill(['total_carga' => round((float) $total, 2)])->save();

        return $carga;
    }
}
