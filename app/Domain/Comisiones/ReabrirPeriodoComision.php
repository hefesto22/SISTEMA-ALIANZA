<?php

declare(strict_types=1);

namespace App\Domain\Comisiones;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Models\ComisionPeriodo;
use Illuminate\Support\Facades\DB;

/**
 * Deshace el cierre del último periodo (se cerró antes de tiempo o con una
 * escala mal puesta): vuelve a quedar abierto y se calcula al momento. Solo
 * el último, para no mover lo que ya se pagó en los siguientes.
 */
final class ReabrirPeriodoComision
{
    public function ejecutar(ComisionPeriodo $cierre): void
    {
        DB::transaction(function () use ($cierre): void {
            DB::select('SELECT pg_advisory_xact_lock(?)', [CerrarPeriodoComision::CANDADO]);

            if (! $cierre->esElUltimo()) {
                throw new ReglaNegocioException('Solo se puede reabrir el último periodo cerrado.');
            }

            $cierre->delete();
        });
    }
}
