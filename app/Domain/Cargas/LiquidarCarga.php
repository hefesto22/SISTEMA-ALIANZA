<?php

declare(strict_types=1);

namespace App\Domain\Cargas;

use App\Domain\Devoluciones\Reposiciones;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Pedidos\AnularNoEntregado;
use App\Enums\EntregaFactura;
use App\Enums\EstadoCarga;
use App\Models\Carga;
use App\Models\CargaFactura;
use App\Models\CargaPago;
use App\Models\CargaReposicion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Liquida la carga cuando el camión regresa.
 *
 *  - Cada factura: entregada (lo normal), o no se entregó y se anula
 *    (AnularNoEntregado: la factura se anula y lo que salió regresa a la
 *    bodega), o no se entregó y sale en otra carga (queda libre para otra).
 *    Las que se anularon ese día al corregir el pedido quedan anuladas.
 *  - Cada cambio que llevaba: entregado (lo normal) o no se entregó (regresa
 *    a la bodega y espera otra carga: Reposiciones).
 *  - Lo que trajo: efectivo, y los cheques y las transferencias uno por
 *    uno (de qué factura, banco, número o referencia, monto).
 *  - Se guarda el cuadre (CuentaCarga). Si no cuadra, la nota es
 *    obligatoria: por qué falta o sobra.
 *
 * Queda Liquidada y ya no cambia.
 */
final class LiquidarCarga
{
    public function __construct(
        private readonly AnularNoEntregado $anular,
        private readonly Reposiciones $reposiciones,
    ) {}

    /**
     * @param array{
     *     entregas: array<int, string>,
     *     cambios?: array<int, string>,
     *     efectivo: float,
     *     cheques: list<array{factura_id?: int|null, banco?: string|null, referencia?: string|null, monto: float}>,
     *     transferencias: list<array{factura_id?: int|null, banco?: string|null, referencia?: string|null, monto: float}>,
     *     nota?: string|null
     * } $datos
     */
    public function ejecutar(Carga $carga, array $datos, User $usuario): Carga
    {
        return DB::transaction(function () use ($carga, $datos, $usuario): Carga {
            $carga = Carga::query()->lockForUpdate()->findOrFail($carga->id);

            if (! $carga->enRuta()) {
                throw new ReglaNegocioException("La carga {$carga->numero()} ya se liquidó.");
            }

            if ($datos['efectivo'] < 0) {
                throw new ReglaNegocioException('El efectivo no puede ser negativo.');
            }

            foreach ([...$datos['cheques'], ...$datos['transferencias']] as $pago) {
                if ($pago['monto'] <= 0) {
                    throw new ReglaNegocioException('Cada cheque y cada transferencia lleva su monto (mayor que cero).');
                }
            }

            $cuenta = CuentaCarga::de(
                $carga,
                $datos['entregas'],
                $datos['efectivo'],
                array_map(fn (array $pago): float => (float) $pago['monto'], $datos['cheques']),
                array_map(fn (array $pago): float => (float) $pago['monto'], $datos['transferencias']),
            );

            $nota = trim((string) ($datos['nota'] ?? ''));

            if (abs($cuenta['diferencia']) >= 0.005 && $nota === '') {
                throw new ReglaNegocioException(CuentaCarga::texto($cuenta['diferencia']).': escriba en la nota por qué no cuadra.');
            }

            $this->entregas($carga, $datos['entregas'], $usuario);
            $this->cambios($carga, $datos['cambios'] ?? [], $usuario);
            $this->pagos($carga, CargaPago::CHEQUE, $datos['cheques']);
            $this->pagos($carga, CargaPago::TRANSFERENCIA, $datos['transferencias']);

            $carga->forceFill([
                'estado'         => EstadoCarga::Liquidada,
                'entregado'      => $cuenta['entregado'],
                'no_entregado'   => $cuenta['no_entregado'],
                'devuelto'       => $cuenta['devuelto'],
                'debe_traer'     => $cuenta['debe_traer'],
                'efectivo'       => $cuenta['efectivo'],
                'cheques'        => $cuenta['cheques'],
                'transferencias' => $cuenta['transferencias'],
                'trajo'          => $cuenta['trajo'],
                'diferencia'     => $cuenta['diferencia'],
                'nota'           => $nota === '' ? null : mb_strtoupper($nota),
                'liquidada_at'   => now(),
                'liquidada_por'  => $usuario->id,
            ])->save();

            return $carga;
        });
    }

    /**
     * @param array<int, string> $entregas
     */
    private function entregas(Carga $carga, array $entregas, User $usuario): void
    {
        $carga->load('renglones.factura.pedido');

        /** @var CargaFactura $renglon */
        foreach ($carga->renglones as $renglon) {
            $factura = $renglon->factura;
            $decision = $entregas[$factura->id] ?? CuentaCarga::ENTREGADA;

            $estado = match (true) {
                $factura->estaAnulada()               => EntregaFactura::Anulada,
                $decision === CuentaCarga::ANULAR     => EntregaFactura::Anulada,
                $decision === CuentaCarga::OTRA_CARGA => EntregaFactura::OtraCarga,
                default                               => EntregaFactura::Entregada,
            };

            if (! $factura->estaAnulada() && $decision === CuentaCarga::ANULAR) {
                $this->anular->ejecutar($factura->pedido, $usuario, "CARGA {$carga->numero()}");
            }

            $renglon->forceFill(['estado' => $estado])->save();
        }
    }

    /**
     * Los cambios que llevaba: entregados, salvo los que se marcaron "No se
     * entregó".
     *
     * @param array<int, string> $cambios [id del cambio (reposición) => entregada | otra_carga]
     */
    private function cambios(Carga $carga, array $cambios, User $usuario): void
    {
        $renglones = $carga->cambios()->where('estado', EntregaFactura::EnRuta->value)->with(['carga', 'reposicion'])->get();

        /** @var CargaReposicion $renglon */
        foreach ($renglones as $renglon) {
            if (($cambios[$renglon->reposicion_id] ?? CuentaCarga::ENTREGADA) === CuentaCarga::OTRA_CARGA) {
                $this->reposiciones->noSeEntrego($renglon, $usuario);
            } else {
                $this->reposiciones->entregar($renglon);
            }
        }
    }

    /**
     * @param list<array{factura_id?: int|null, banco?: string|null, referencia?: string|null, monto: float}> $pagos
     */
    private function pagos(Carga $carga, string $tipo, array $pagos): void
    {
        $facturas = $carga->renglones->pluck('factura_id')->map(fn (mixed $id): int => (int) $id)->all();

        foreach ($pagos as $pago) {
            $facturaId = $pago['factura_id'] ?? null;

            $carga->pagos()->create([
                'tipo'       => $tipo,
                'factura_id' => $facturaId !== null && in_array($facturaId, $facturas, true) ? $facturaId : null,
                'banco'      => filled($pago['banco'] ?? null) ? mb_strtoupper(trim((string) $pago['banco'])) : null,
                'referencia' => filled($pago['referencia'] ?? null) ? mb_strtoupper(trim((string) $pago['referencia'])) : null,
                'monto'      => round($pago['monto'], 2),
            ]);
        }
    }
}
