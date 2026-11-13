<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existencia y costo POR PRESENTACIÓN: la CJ48, la CJ32 y las UN sueltas
     * de un mismo producto llevan cada una su existencia en cada bodega y su
     * costo promedio (sin ISV, de la presentación completa). Así una caja
     * que se compró más cara no se mezcla con otra más barata. Cuando se
     * abre una caja, sus unidades pasan a las sueltas con el costo de esa
     * caja y se promedian ahí.
     *
     * Lo que ya había:
     *  - Cada presentación queda con el costo de hoy × lo que trae.
     *  - La existencia (en unidades) queda en cajas de la presentación más
     *    grande que se vende y lo que sobra en sueltas ("2 CJ48 + 5 UN",
     *    como se mostraba). El kárdex lo deja como ajuste. Después conviene
     *    un conteo por presentación.
     *  - El kárdex de antes queda en la base (era en unidades).
     *  - En las compras, el costo de antes y después pasa a ser el de la
     *    presentación en que entró.
     *  - El costo por unidad del producto se quita: ahora es de cada presentación.
     */
    public function up(): void
    {
        Schema::table('presentaciones', function (Blueprint $table): void {
            // Costo promedio de la presentación completa, sin ISV.
            $table->decimal('costo', 14, 4)->default(0)->after('precio');
        });

        DB::statement('UPDATE presentaciones SET costo = ROUND(p.costo_unidad * presentaciones.contenido, 4) FROM productos p WHERE p.id = presentaciones.producto_id');

        // Existencias: una fila por bodega y presentación, en esa presentación.
        Schema::table('existencias', function (Blueprint $table): void {
            $table->foreignId('presentacion_id')->nullable()->after('producto_id')->constrained('presentaciones')->cascadeOnDelete();
        });

        DB::statement('UPDATE existencias SET presentacion_id = p.id FROM presentaciones p WHERE p.producto_id = existencias.producto_id AND p.es_base');

        Schema::table('existencias', function (Blueprint $table): void {
            $table->dropUnique(['bodega_id', 'producto_id']);
            $table->unsignedBigInteger('presentacion_id')->nullable(false)->change();
            $table->unique(['bodega_id', 'presentacion_id']);
        });

        // Kárdex: cada renglón es de una presentación (cantidad, existencia y costo en ella).
        Schema::table('movimientos_inventario', function (Blueprint $table): void {
            $table->foreignId('presentacion_id')->nullable()->after('producto_id')->constrained('presentaciones')->restrictOnDelete();
        });

        DB::statement('UPDATE movimientos_inventario SET presentacion_id = p.id FROM presentaciones p WHERE p.producto_id = movimientos_inventario.producto_id AND p.es_base');

        Schema::table('movimientos_inventario', function (Blueprint $table): void {
            $table->unsignedBigInteger('presentacion_id')->nullable(false)->change();
            $table->index(['presentacion_id', 'bodega_id', 'id']);
        });

        self::repartirEnCajas();

        DB::statement('UPDATE compra_items SET costo_anterior = ROUND(compra_items.costo_anterior * p.contenido, 4), costo_nuevo = ROUND(compra_items.costo_nuevo * p.contenido, 4) FROM presentaciones p WHERE p.id = compra_items.presentacion_id AND p.contenido > 1');

        Schema::table('productos', function (Blueprint $table): void {
            $table->dropColumn('costo_unidad');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table): void {
            $table->decimal('costo_unidad', 14, 4)->default(0)->after('margen');
        });

        DB::statement('UPDATE productos SET costo_unidad = b.costo FROM presentaciones b WHERE b.producto_id = productos.id AND b.es_base');
        DB::statement('UPDATE compra_items SET costo_anterior = ROUND(compra_items.costo_anterior / p.contenido, 4), costo_nuevo = ROUND(compra_items.costo_nuevo / p.contenido, 4) FROM presentaciones p WHERE p.id = compra_items.presentacion_id AND p.contenido > 1');

        // Todo otra vez en unidades base.
        DB::statement('UPDATE movimientos_inventario SET cantidad = movimientos_inventario.cantidad * p.contenido, existencia = movimientos_inventario.existencia * p.contenido, costo_unidad = ROUND(movimientos_inventario.costo_unidad / p.contenido, 4) FROM presentaciones p WHERE p.id = movimientos_inventario.presentacion_id AND p.contenido > 1');

        $totales = DB::select('SELECT e.bodega_id, e.producto_id, SUM(e.cantidad * p.contenido) AS total FROM existencias e JOIN presentaciones p ON p.id = e.presentacion_id GROUP BY e.bodega_id, e.producto_id');

        DB::table('existencias')->delete();

        Schema::table('existencias', function (Blueprint $table): void {
            $table->dropUnique(['bodega_id', 'presentacion_id']);
            $table->dropConstrainedForeignId('presentacion_id');
            $table->unique(['bodega_id', 'producto_id']);
        });

        $ahora = now();

        foreach ($totales as $fila) {
            DB::table('existencias')->insert([
                'bodega_id'   => $fila->bodega_id,
                'producto_id' => $fila->producto_id,
                'cantidad'    => (int) $fila->total,
                'created_at'  => $ahora,
                'updated_at'  => $ahora,
            ]);
        }

        Schema::table('movimientos_inventario', function (Blueprint $table): void {
            $table->dropIndex(['presentacion_id', 'bodega_id', 'id']);
            $table->dropConstrainedForeignId('presentacion_id');
        });

        Schema::table('presentaciones', function (Blueprint $table): void {
            $table->dropColumn('costo');
        });
    }

    /**
     * Lo que había en unidades pasa a cajas de la presentación más grande
     * que se vende ("2 CJ48 + 5 UN", como se mostraba), con su renglón en
     * el kárdex (sale de las sueltas y entra en cajas).
     */
    private static function repartirEnCajas(): void
    {
        $cajas = DB::table('presentaciones as p')
            ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
            ->where('p.activo', true)
            ->where('p.contenido', '>', 1)
            ->orderByDesc('p.contenido')
            ->orderBy('p.id')
            ->get(['p.id', 'p.producto_id', 'p.contenido', 'p.costo', 'u.codigo'])
            ->unique('producto_id')
            ->keyBy('producto_id');

        $filas = DB::table('existencias as e')
            ->join('presentaciones as b', 'b.id', '=', 'e.presentacion_id')
            ->where('e.cantidad', '>', 0)
            ->orderBy('e.id')
            ->get(['e.id', 'e.bodega_id', 'e.producto_id', 'e.presentacion_id', 'e.cantidad', 'b.costo']);

        $ahora = now();

        foreach ($filas as $fila) {
            $caja = $cajas->get($fila->producto_id);

            if ($caja === null) {
                continue;
            }

            $contenido = (int) $caja->contenido;
            $enCajas = intdiv((int) $fila->cantidad, $contenido);

            if ($enCajas < 1) {
                continue;
            }

            $sueltas = (int) $fila->cantidad - $enCajas * $contenido;
            $nota = "EXISTENCIA POR PRESENTACIÓN: {$enCajas} {$caja->codigo}{$contenido}";

            DB::table('existencias')->where('id', $fila->id)->update(['cantidad' => $sueltas, 'updated_at' => $ahora]);

            DB::table('existencias')->insert([
                'bodega_id'       => $fila->bodega_id,
                'producto_id'     => $fila->producto_id,
                'presentacion_id' => $caja->id,
                'cantidad'        => $enCajas,
                'created_at'      => $ahora,
                'updated_at'      => $ahora,
            ]);

            DB::table('movimientos_inventario')->insert([
                [
                    'bodega_id'       => $fila->bodega_id,
                    'producto_id'     => $fila->producto_id,
                    'presentacion_id' => $fila->presentacion_id,
                    'tipo'            => 'ajuste',
                    'cantidad'        => -$enCajas * $contenido,
                    'existencia'      => $sueltas,
                    'costo_unidad'    => $fila->costo,
                    'nota'            => $nota,
                    'created_at'      => $ahora,
                    'updated_at'      => $ahora,
                ],
                [
                    'bodega_id'       => $fila->bodega_id,
                    'producto_id'     => $fila->producto_id,
                    'presentacion_id' => $caja->id,
                    'tipo'            => 'ajuste',
                    'cantidad'        => $enCajas,
                    'existencia'      => $enCajas,
                    'costo_unidad'    => $caja->costo,
                    'nota'            => $nota,
                    'created_at'      => $ahora,
                    'updated_at'      => $ahora,
                ],
            ]);
        }
    }
};
