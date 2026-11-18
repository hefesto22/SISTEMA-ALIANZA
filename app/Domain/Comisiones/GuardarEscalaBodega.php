<?php

declare(strict_types=1);

namespace App\Domain\Comisiones;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Models\Bodega;
use App\Models\ComisionEscalaBodega;
use App\Models\ComisionGrupo;
use App\Models\User;

/**
 * Los montos de un grupo de comisión en una bodega. Sin escalones (null),
 * la bodega vuelve a usar la escala del grupo. El encargado solo cambia la
 * de su bodega; administración, la de cualquiera.
 */
final class GuardarEscalaBodega
{
    public function ejecutar(mixed $grupoId, mixed $bodegaId, mixed $escalones, User $usuario): ?ComisionEscalaBodega
    {
        $grupo = is_numeric($grupoId) ? ComisionGrupo::query()->find((int) $grupoId) : null;
        $bodega = is_numeric($bodegaId) ? Bodega::query()->find((int) $bodegaId) : null;

        if (! $grupo instanceof ComisionGrupo) {
            throw new ReglaNegocioException('Escoja el grupo de comisión.');
        }

        if (! $bodega instanceof Bodega) {
            throw new ReglaNegocioException('Escoja la bodega.');
        }

        if (! $usuario->veTodasLasBodegas() && $usuario->bodega_id !== $bodega->id) {
            throw new ReglaNegocioException('Solo puede cambiar la escala de su bodega.');
        }

        if ($escalones === null) {
            ComisionEscalaBodega::query()->where('comision_grupo_id', $grupo->id)->where('bodega_id', $bodega->id)->delete();

            return null;
        }

        $error = Escala::error($escalones);

        if ($error !== null) {
            throw new ReglaNegocioException($error);
        }

        return ComisionEscalaBodega::query()->updateOrCreate(
            ['comision_grupo_id' => $grupo->id, 'bodega_id' => $bodega->id],
            ['escalones' => Escala::de($escalones)->paraGuardar()],
        );
    }
}
