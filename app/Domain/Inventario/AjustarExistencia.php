<?php

declare(strict_types=1);

namespace App\Domain\Inventario;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\TipoMovimiento;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Conteo físico: deja la existencia de la bodega en lo que se contó. Sirve
 * para cargar el inventario inicial y para corregir (dañados, faltantes).
 * El kárdex guarda la diferencia como "Ajuste por conteo"; el costo no cambia.
 *
 * Si ya estaba en esa cantidad no hace nada y devuelve null.
 */
final class AjustarExistencia
{
    public function __construct(private readonly MoverExistencia $mover) {}

    public function ejecutar(int $bodegaId, Producto $producto, int $contado, User $usuario, ?string $nota = null): ?MovimientoInventario
    {
        if ($contado < 0) {
            throw new ReglaNegocioException('Lo contado no puede ser negativo.');
        }

        return DB::transaction(function () use ($bodegaId, $producto, $contado, $usuario, $nota): ?MovimientoInventario {
            $diferencia = $contado - MoverExistencia::bloquear($bodegaId, $producto->id)->cantidad;

            if ($diferencia === 0) {
                return null;
            }

            return $this->mover->ejecutar($bodegaId, $producto, $diferencia, TipoMovimiento::Ajuste, $usuario, nota: filled($nota) ? trim((string) $nota) : 'CONTEO');
        });
    }
}
