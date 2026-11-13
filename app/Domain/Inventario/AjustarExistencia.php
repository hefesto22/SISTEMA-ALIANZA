<?php

declare(strict_types=1);

namespace App\Domain\Inventario;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\TipoMovimiento;
use App\Models\MovimientoInventario;
use App\Models\Presentacion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Conteo físico de una presentación: deja su existencia en la bodega en lo
 * que se contó (cuántas CJ48, cuántas UN sueltas). Sirve para cargar el
 * inventario inicial y para corregir (dañados, faltantes). El kárdex guarda
 * la diferencia como "Ajuste por conteo" con el costo de la presentación;
 * el costo no cambia.
 *
 * Si ya estaba en esa cantidad no hace nada y devuelve null.
 */
final class AjustarExistencia
{
    public function __construct(private readonly MoverExistencia $mover) {}

    public function ejecutar(int $bodegaId, Presentacion $presentacion, int $contado, User $usuario, ?string $nota = null): ?MovimientoInventario
    {
        if ($contado < 0) {
            throw new ReglaNegocioException('Lo contado no puede ser negativo.');
        }

        return DB::transaction(function () use ($bodegaId, $presentacion, $contado, $usuario, $nota): ?MovimientoInventario {
            $diferencia = $contado - MoverExistencia::bloquear($bodegaId, $presentacion)->cantidad;

            // Con su costo de este momento (el kárdex lo guarda).
            $presentacion->refresh();

            if ($diferencia === 0) {
                return null;
            }

            return $this->mover->ejecutar($bodegaId, $presentacion, $diferencia, TipoMovimiento::Ajuste, $usuario, nota: filled($nota) ? trim((string) $nota) : 'CONTEO');
        });
    }
}
