<?php

declare(strict_types=1);

namespace App\Domain\Devoluciones;

use App\Models\Carga;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * En la ruta, lo del camión se va sacando cliente por cliente. Si a un
 * cliente se le cambió producto con lo que llevaba el camión (cambio "del
 * camión"), a otro cliente le tuvo que faltar eso mismo, y ese faltante se
 * registra en su factura ("Le faltó producto · se le dio a otro cliente").
 *
 * Al liquidar se revisa, por producto y en unidades, que lo que se dio del
 * camión en cambios sea lo que se anotó que les faltó a otros clientes de la
 * misma carga. Lo que no cuadra sale como aviso (no detiene la liquidación).
 */
final class CambiosDelCamion
{
    private const UNIDADES = 'i.cantidad * GREATEST(pz.contenido, 1)';

    /**
     * Los productos en los que no cuadra, en unidades.
     *
     * @return list<array{producto: string, dado: int, falto: int}>
     */
    public static function sinCuadrar(Carga $carga): array
    {
        $dado = 'SUM(CASE WHEN d.del_camion THEN '.self::UNIDADES.' ELSE 0 END)';
        $falto = 'SUM(CASE WHEN d.falto AND i.faltante THEN '.self::UNIDADES.' ELSE 0 END)';

        /** @var list<stdClass> $filas */
        $filas = DB::select(
            "SELECT p.descripcion AS producto, {$dado} AS dado, {$falto} AS falto"
            .' FROM devolucion_items i'
            .' JOIN devoluciones d ON d.id = i.devolucion_id'
            .' JOIN presentaciones pz ON pz.id = i.presentacion_id'
            .' JOIN productos p ON p.id = i.producto_id'
            .' WHERE d.carga_id = ? AND (d.del_camion OR (d.falto AND i.faltante))'
            .' GROUP BY p.id, p.descripcion'
            ." HAVING {$dado} <> {$falto}"
            .' ORDER BY p.descripcion',
            [$carga->id],
        );

        return array_map(fn (stdClass $f): array => [
            'producto' => is_scalar($f->producto) ? (string) $f->producto : '',
            'dado'     => is_numeric($f->dado) ? (int) $f->dado : 0,
            'falto'    => is_numeric($f->falto) ? (int) $f->falto : 0,
        ], $filas);
    }

    /**
     * "DOVE DEO…: del camión se dieron 3 unidades en cambios y se anotó que
     * les faltó 1 a otros clientes." / "… y no se anotó a quién le faltó."
     *
     * @param array{producto: string, dado: int, falto: int} $fila
     */
    public static function texto(array $fila): string
    {
        $unidades = fn (int $n): string => $n === 1 ? '1 unidad' : "{$n} unidades";

        return "{$fila['producto']}: del camión se dieron {$unidades($fila['dado'])} en cambios y "
            .($fila['falto'] === 0 ? 'no se anotó a quién le faltó.' : "se anotó que les faltó {$fila['falto']} a otros clientes.");
    }
}
