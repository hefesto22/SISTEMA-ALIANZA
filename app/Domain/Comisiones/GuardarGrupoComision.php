<?php

declare(strict_types=1);

namespace App\Domain\Comisiones;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Models\Categoria;
use App\Models\ComisionGrupo;
use Illuminate\Support\Facades\DB;

/**
 * Crea o cambia un grupo de comisión con su escala y sus categorías.
 *
 * Una categoría va en un solo grupo: si se escoge una que estaba en otro,
 * se pasa a este. Las que se quitan quedan sin grupo (no pagan comisión).
 * Cambiar la escala no toca lo ya pagado (cada cierre guardó la suya).
 */
final class GuardarGrupoComision
{
    /**
     * @param array<string, mixed> $datos nombre, descripcion, activo, escalones (filas del formulario) y categorias (ids)
     */
    public function ejecutar(?ComisionGrupo $grupo, array $datos): ComisionGrupo
    {
        $error = Escala::error($datos['escalones'] ?? []);

        if ($error !== null) {
            throw new ReglaNegocioException($error);
        }

        $nombre = trim(is_string($datos['nombre'] ?? null) ? $datos['nombre'] : '');

        if ($nombre === '') {
            throw new ReglaNegocioException('Escriba el nombre del grupo.');
        }

        $categorias = array_values(array_unique(array_map('intval', array_filter(
            is_array($datos['categorias'] ?? null) ? $datos['categorias'] : [],
            fn (mixed $id): bool => is_numeric($id),
        ))));

        return DB::transaction(function () use ($grupo, $datos, $nombre, $categorias): ComisionGrupo {
            $grupo ??= new ComisionGrupo;
            $descripcion = $datos['descripcion'] ?? null;

            $grupo->fill([
                'nombre'      => $nombre,
                'descripcion' => is_string($descripcion) && trim($descripcion) !== '' ? trim($descripcion) : null,
                'activo'      => (bool) ($datos['activo'] ?? true),
                'escalones'   => Escala::de($datos['escalones'] ?? [])->paraGuardar(),
            ])->save();

            Categoria::query()
                ->where('comision_grupo_id', $grupo->id)
                ->whereNotIn('id', $categorias)
                ->update(['comision_grupo_id' => null]);

            if ($categorias !== []) {
                Categoria::query()->whereIn('id', $categorias)->update(['comision_grupo_id' => $grupo->id]);
            }

            return $grupo;
        });
    }
}
