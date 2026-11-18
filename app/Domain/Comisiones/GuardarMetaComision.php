<?php

declare(strict_types=1);

namespace App\Domain\Comisiones;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Models\ComisionGrupo;
use App\Models\ComisionMeta;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * La escala propia de un vendedor en un grupo (en lugar de la de todos).
 * El encargado de bodega solo se la pone a vendedores de su bodega.
 */
final class GuardarMetaComision
{
    public function ejecutar(?ComisionMeta $meta, mixed $grupoId, mixed $vendedorId, mixed $escalones, User $usuario): ComisionMeta
    {
        $grupo = is_numeric($grupoId) ? ComisionGrupo::query()->find((int) $grupoId) : null;
        $vendedor = is_numeric($vendedorId) ? User::query()->find((int) $vendedorId) : null;

        if (! $grupo instanceof ComisionGrupo) {
            throw new ReglaNegocioException('Escoja el grupo de comisión.');
        }

        if (! $vendedor instanceof User) {
            throw new ReglaNegocioException('Escoja el vendedor.');
        }

        if (! $usuario->veTodasLasBodegas() && ($usuario->bodega_id === null || $vendedor->bodega_id !== $usuario->bodega_id)) {
            throw new ReglaNegocioException('Solo puede poner metas a los vendedores de su bodega.');
        }

        $error = Escala::error($escalones);

        if ($error !== null) {
            throw new ReglaNegocioException($error);
        }

        $repetida = ComisionMeta::query()
            ->where('comision_grupo_id', $grupo->id)
            ->where('vendedor_id', $vendedor->id)
            ->when($meta !== null, fn (Builder $query): Builder => $query->whereKeyNot($meta?->id))
            ->exists();

        if ($repetida) {
            throw new ReglaNegocioException("{$vendedor->name} ya tiene su meta en {$grupo->nombre}: edite esa.");
        }

        $meta ??= new ComisionMeta;
        $meta->fill([
            'comision_grupo_id' => $grupo->id,
            'vendedor_id'       => $vendedor->id,
            'escalones'         => Escala::de($escalones)->paraGuardar(),
        ])->save();

        return $meta;
    }
}
