<?php

declare(strict_types=1);

namespace App\Domain\Reportes;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Models\Bodega;
use App\Models\User;

/**
 * De qué bodega puede sacar reportes cada quien: administración, de la que
 * escoja o de todas; el encargado, solo de la suya (aunque pida otra).
 */
final class AlcanceReporte
{
    /**
     * Tiene el permiso y, si no es de administración, tiene bodega asignada.
     */
    public static function puedeVer(?User $usuario, string $permiso): bool
    {
        return $usuario !== null
            && $usuario->can($permiso)
            && ($usuario->veTodasLasBodegas() || $usuario->bodega_id !== null);
    }

    /**
     * La bodega del reporte (null = todas, solo para administración).
     *
     * @throws ReglaNegocioException si no ve todas y no tiene bodega
     */
    public static function bodega(User $usuario, mixed $pedida): ?Bodega
    {
        if ($usuario->veTodasLasBodegas()) {
            return is_numeric($pedida) ? Bodega::query()->find((int) $pedida) : null;
        }

        $bodega = $usuario->bodega;

        if (! $bodega instanceof Bodega) {
            throw new ReglaNegocioException('Pida a administración que le asigne su bodega en Usuarios.');
        }

        return $bodega;
    }
}
