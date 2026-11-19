<?php

declare(strict_types=1);

namespace App\Domain\Inventario;

use App\Models\Existencia;
use App\Models\Presentacion;
use App\Models\User;

/**
 * Abre solas las cajas que hagan falta para sacar sueltas (la base) de un
 * producto en una bodega, cuando nadie dice cuál: la más chica que sobre (la
 * que no sale entera en la misma operación) y alcance; si ninguna alcanza
 * sola, de las más grandes las que hagan falta. Sin cajas que sobren no abre
 * nada. La usan los regalos de promociones y los cambios de una devolución.
 * Se llama dentro de una transacción.
 */
final class AbrirSiFaltan
{
    public function __construct(private readonly AbrirCaja $abrirCaja) {}

    /**
     * @param array<int, int> $salen lo que sale de cada caja en la misma operación (esas no se abren)
     */
    public function ejecutar(int $bodegaId, int $productoId, int $falta, ?User $usuario, ?int $pedidoId, string $nota, array $salen = []): void
    {
        if ($falta <= 0) {
            return;
        }

        $cajas = Presentacion::query()
            ->with(['producto', 'unidadMedida'])
            ->where('producto_id', $productoId)
            ->where('es_base', false)
            ->where('contenido', '>', 1)
            ->orderBy('contenido')
            ->get();
        $sobran = [];

        foreach ($cajas as $caja) {
            $sobran[$caja->id] = max(0, Existencia::de($bodegaId, $caja->id) - max(0, $salen[$caja->id] ?? 0));
        }

        $abrir = [];
        $una = $cajas->first(fn (Presentacion $caja): bool => $sobran[$caja->id] > 0 && $caja->contenido >= $falta);

        if ($una instanceof Presentacion) {
            $abrir[$una->id] = 1;
        } else {
            foreach ($cajas->sortByDesc('contenido') as $caja) {
                $cuantas = min($sobran[$caja->id], intdiv($falta + $caja->contenido - 1, $caja->contenido));

                if ($cuantas > 0) {
                    $abrir[$caja->id] = $cuantas;
                    $falta -= $cuantas * $caja->contenido;
                }

                if ($falta <= 0) {
                    break;
                }
            }
        }

        foreach ($cajas as $caja) {
            if (isset($abrir[$caja->id])) {
                $this->abrirCaja->ejecutar($bodegaId, $caja, $abrir[$caja->id], $usuario, $pedidoId, "{$nota}: SE ABREN {$abrir[$caja->id]} {$caja->sigla()}");
            }
        }
    }
}
