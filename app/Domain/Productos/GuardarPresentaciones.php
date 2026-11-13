<?php

declare(strict_types=1);

namespace App\Domain\Productos;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\UnidadMedida;
use Illuminate\Support\Facades\DB;

/**
 * Deja las presentaciones de un producto como vienen del formulario:
 *
 *  - La base (lo que se cuenta en existencia: la medida del producto,
 *    trae 1) siempre está; solo se dice si se vende suelta.
 *  - Las demás traen 2 o más unidades base (CJ de 12, FD de 6, SC de 10…)
 *    y no se repiten. Las que ya están se actualizan y las nuevas entran
 *    sin precio (el precio lo pone quien llama: ActualizarPrecioProducto)
 *    y con un costo estimado (el de referencia del producto × lo que
 *    traen) hasta su primera compra.
 *  - Lo que trae una presentación que ya se usó en pedidos, compras o
 *    promociones no se cambia: se desactiva y se agrega otra.
 *  - Las que ya no vienen se borran; si ya se usaron, quedan desactivadas.
 *
 * Los precios no se tocan aquí.
 */
final class GuardarPresentaciones
{
    /**
     * @param list<array{id?: int|null, unidad_medida_id: int, contenido: int, activo: bool}> $otras
     *
     * @return array{base: Presentacion, otras: list<Presentacion>} en el mismo orden en que vinieron
     */
    public function ejecutar(Producto $producto, bool $baseActiva, array $otras): array
    {
        $error = self::revisar($producto->unidad_medida_id, $otras);

        if ($error !== null) {
            throw new ReglaNegocioException($error);
        }

        return DB::transaction(function () use ($producto, $baseActiva, $otras): array {
            $actuales = $producto->presentaciones()->with('unidadMedida')->get();
            $producto->setRelation('presentaciones', $actuales);
            $referencia = $producto->costoReferencia();

            /** @var Presentacion $base */
            $base = $actuales->first(fn (Presentacion $presentacion): bool => $presentacion->es_base)
                ?? Presentacion::query()->create([
                    'producto_id'      => $producto->id,
                    'unidad_medida_id' => $producto->unidad_medida_id,
                    'contenido'        => 1,
                    'es_base'          => true,
                ]);

            $base->forceFill(['unidad_medida_id' => $producto->unidad_medida_id, 'contenido' => 1, 'activo' => $baseActiva])->save();

            $quedan = [$base->id];
            $guardadas = [];

            foreach ($otras as $fila) {
                $presentacion = $this->guardar($producto, $actuales->all(), $quedan, $fila, $referencia);
                $quedan[] = $presentacion->id;
                $guardadas[] = $presentacion;
            }

            foreach ($actuales as $presentacion) {
                if (in_array($presentacion->id, $quedan, true)) {
                    continue;
                }

                $presentacion->enUso()
                    ? $presentacion->forceFill(['activo' => false])->save()
                    : $presentacion->delete();
            }

            $producto->unsetRelation('presentaciones');

            return ['base' => $base, 'otras' => $guardadas];
        });
    }

    /**
     * Lo que está mal antes de guardar (null si nada): cada una trae 2 o más
     * y no se repite (ni con la base).
     *
     * @param list<array{id?: int|null, unidad_medida_id: int, contenido: int, activo: bool}> $otras
     */
    public static function revisar(int $baseMedidaId, array $otras): ?string
    {
        $vistas = ["{$baseMedidaId}-1" => true];
        $codigos = UnidadMedida::query()->pluck('codigo', 'id');

        foreach ($otras as $fila) {
            $codigo = (string) ($codigos[$fila['unidad_medida_id']] ?? '');

            if ($fila['contenido'] < 2) {
                return "La presentación {$codigo} tiene que traer 2 o más unidades (la de 1 es la base).";
            }

            $clave = "{$fila['unidad_medida_id']}-{$fila['contenido']}";

            if (isset($vistas[$clave])) {
                return "La presentación {$codigo}{$fila['contenido']} está repetida.";
            }

            $vistas[$clave] = true;
        }

        return null;
    }

    /**
     * Actualiza la que ya estaba (por su id, o una igual que se había quitado)
     * o crea una nueva sin precio y con el costo estimado.
     *
     * @param array<int, Presentacion> $actuales
     * @param list<int> $quedan las que ya se guardaron
     * @param array{id?: int|null, unidad_medida_id: int, contenido: int, activo: bool} $fila
     * @param float $referencia costo por unidad base sin ISV para estimar el de una nueva
     */
    private function guardar(Producto $producto, array $actuales, array $quedan, array $fila, float $referencia): Presentacion
    {
        $libres = array_filter($actuales, fn (Presentacion $presentacion): bool => ! $presentacion->es_base && ! in_array($presentacion->id, $quedan, true));
        $id = isset($fila['id']) ? (int) $fila['id'] : null;

        $presentacion = null;

        foreach ($libres as $libre) {
            if ($libre->id === $id) {
                $presentacion = $libre;
            }
        }

        // Sin id: si ya había una igual (desactivada o que se quitó), es esa.
        if ($presentacion === null) {
            foreach ($libres as $libre) {
                if ($libre->unidad_medida_id === $fila['unidad_medida_id'] && $libre->contenido === $fila['contenido']) {
                    $presentacion = $libre;
                }
            }
        }

        if ($presentacion === null) {
            return Presentacion::query()->create([
                'producto_id'      => $producto->id,
                'unidad_medida_id' => $fila['unidad_medida_id'],
                'contenido'        => $fila['contenido'],
                'costo'            => round($referencia * $fila['contenido'], 4),
                'activo'           => $fila['activo'],
            ]);
        }

        $cambia = $presentacion->unidad_medida_id !== $fila['unidad_medida_id'] || $presentacion->contenido !== $fila['contenido'];

        if ($cambia && $presentacion->enUso()) {
            throw new ReglaNegocioException("La {$presentacion->sigla()} ya se usó en pedidos, compras o promociones: lo que trae no se cambia. Desactívela y agregue otra.");
        }

        $choca = array_filter($actuales, fn (Presentacion $otra): bool => $otra->id !== $presentacion->id
            && $otra->unidad_medida_id === $fila['unidad_medida_id']
            && $otra->contenido === $fila['contenido']);

        if ($cambia && $choca !== []) {
            throw new ReglaNegocioException('Ya hay otra presentación igual en este producto (puede estar desactivada): active esa en vez de cambiar esta.');
        }

        $presentacion->forceFill([
            'unidad_medida_id' => $fila['unidad_medida_id'],
            'contenido'        => $fila['contenido'],
            'activo'           => $fila['activo'],
            // Si cambió lo que trae (nunca se usó), su costo estimado también.
            'costo' => $cambia ? round($referencia * $fila['contenido'], 4) : $presentacion->costo,
        ])->save();

        return $presentacion;
    }
}
