<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Las que trae el sistema; administración agrega más.
     *
     * @var list<array{codigo: string, nombre: string, plural: string}>
     */
    private const MEDIDAS = [
        ['codigo' => 'UN', 'nombre' => 'UNIDAD', 'plural' => 'UNIDADES'],
        ['codigo' => 'CJ', 'nombre' => 'CAJA', 'plural' => 'CAJAS'],
        ['codigo' => 'FD', 'nombre' => 'FARDO', 'plural' => 'FARDOS'],
        ['codigo' => 'SC', 'nombre' => 'SACO', 'plural' => 'SACOS'],
        ['codigo' => 'BT', 'nombre' => 'BOTE', 'plural' => 'BOTES'],
        ['codigo' => 'PQ', 'nombre' => 'PAQUETE', 'plural' => 'PAQUETES'],
        ['codigo' => 'BL', 'nombre' => 'BOLSA', 'plural' => 'BOLSAS'],
        ['codigo' => 'DS', 'nombre' => 'DISPLAY', 'plural' => 'DISPLAYS'],
        ['codigo' => 'TR', 'nombre' => 'TIRA', 'plural' => 'TIRAS'],
        ['codigo' => 'DC', 'nombre' => 'DOCENA', 'plural' => 'DOCENAS'],
        ['codigo' => 'GL', 'nombre' => 'GALÓN', 'plural' => 'GALONES'],
    ];

    /**
     * Presentaciones: un mismo producto (mismo código) se vende en varias
     * presentaciones, cada una con su precio: la base (UN, 1) y las que
     * traen varias (CJ de 8, CJ de 10, FD de 6, SC de 10…). La existencia y
     * el costo siguen en unidades base; lo que antes era "caja o unidad" en
     * las líneas de pedidos, compras y promociones pasa a ser la presentación.
     *
     * Lo que ya había: cada producto queda con su base (UN, con su precio
     * por unidad) y, si su caja traía más de 1, con la CJ de esas unidades
     * (precio = unidad × UxC, como se cobraba). Las líneas en "caja" apuntan
     * a esa CJ y las demás a la base. El historial de precios queda en la base.
     */
    public function up(): void
    {
        Schema::create('unidades_medida', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 6)->unique();
            $table->string('nombre', 40);
            $table->string('plural', 40);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        $ahora = now();

        DB::table('unidades_medida')->insert(array_map(
            fn (array $medida): array => [...$medida, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            self::MEDIDAS,
        ));

        $un = (int) DB::table('unidades_medida')->where('codigo', 'UN')->value('id');
        $cj = (int) DB::table('unidades_medida')->where('codigo', 'CJ')->value('id');

        // En qué se cuenta la existencia del producto (casi siempre UN).
        Schema::table('productos', function (Blueprint $table): void {
            $table->foreignId('unidad_medida_id')->nullable()->after('descripcion')->constrained('unidades_medida')->restrictOnDelete();
        });

        DB::table('productos')->update(['unidad_medida_id' => $un]);

        Schema::table('productos', function (Blueprint $table): void {
            $table->unsignedBigInteger('unidad_medida_id')->nullable(false)->change();
        });

        Schema::create('presentaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('unidad_medida_id')->constrained('unidades_medida')->restrictOnDelete();
            // Cuántas unidades base trae (la base: 1).
            $table->unsignedInteger('contenido');
            // Precio de venta de la presentación completa, sin ISV.
            $table->decimal('precio', 14, 4)->default(0);
            // Fijo: lo pone la empresa; las compras y los cambios de margen no lo tocan.
            $table->boolean('precio_fijo')->default(false);
            $table->boolean('es_base')->default(false);
            // Se vende: sale en la preventa, al validar y en las compras.
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['producto_id', 'unidad_medida_id', 'contenido']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX presentaciones_una_base ON presentaciones (producto_id) WHERE es_base');
        }

        $columnas = ['producto_id', 'unidad_medida_id', 'contenido', 'precio', 'precio_fijo', 'es_base', 'activo', 'created_at', 'updated_at'];

        DB::table('presentaciones')->insertUsing(
            $columnas,
            DB::table('productos')->selectRaw('id, ?, 1, precio_unidad, precio_fijo, true, true, NOW(), NOW()', [$un]),
        );

        DB::table('presentaciones')->insertUsing(
            $columnas,
            DB::table('productos')
                ->where('unidades_por_caja', '>', 1)
                ->selectRaw('id, ?, unidades_por_caja, ROUND(precio_unidad * unidades_por_caja, 4), precio_fijo, false, true, NOW(), NOW()', [$cj]),
        );

        // Líneas de pedidos, compras y condiciones de promociones: la presentación.
        foreach (['pedido_items', 'compra_items', 'promocion_condiciones'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table): void {
                $table->foreignId('presentacion_id')->nullable()->after('producto_id')->constrained('presentaciones')->restrictOnDelete();
            });

            self::apuntar($tabla, 'presentacion_id', 'producto_id', 'unidad');
        }

        // En las compras el precio nuevo es de la presentación en que entró (antes, por unidad).
        DB::statement('UPDATE compra_items SET precio_nuevo = ROUND(compra_items.precio_nuevo * p.contenido, 4) FROM presentaciones p WHERE p.id = compra_items.presentacion_id AND NOT p.es_base');

        // Unidades base que trae lo pedido en esa línea (como estaba al pedirlo).
        Schema::table('pedido_items', function (Blueprint $table): void {
            $table->unsignedInteger('contenido')->default(1)->after('presentacion_id');
        });

        DB::statement('UPDATE pedido_items SET contenido = p.contenido FROM presentaciones p WHERE p.id = pedido_items.presentacion_id');

        Schema::table('promociones', function (Blueprint $table): void {
            $table->foreignId('beneficio_presentacion_id')->nullable()->after('producto_beneficio_id')->constrained('presentaciones')->restrictOnDelete();
        });

        self::apuntar('promociones', 'beneficio_presentacion_id', 'producto_beneficio_id', 'beneficio_unidad');

        // El historial de precios es de una presentación; lo de antes, de la base.
        Schema::table('cambios_precio', function (Blueprint $table): void {
            $table->foreignId('presentacion_id')->nullable()->after('producto_id')->constrained('presentaciones')->nullOnDelete();
        });

        DB::statement('UPDATE cambios_precio SET presentacion_id = p.id FROM presentaciones p WHERE p.producto_id = cambios_precio.producto_id AND p.es_base');

        foreach (['pedido_items' => 'presentacion_id', 'compra_items' => 'presentacion_id', 'promocion_condiciones' => 'presentacion_id', 'promociones' => 'beneficio_presentacion_id'] as $tabla => $columna) {
            Schema::table($tabla, function (Blueprint $table) use ($columna): void {
                $table->unsignedBigInteger($columna)->nullable(false)->change();
            });
        }

        foreach (['pedido_items' => 'unidad', 'compra_items' => 'unidad', 'promocion_condiciones' => 'unidad', 'promociones' => 'beneficio_unidad'] as $tabla => $columna) {
            Schema::table($tabla, function (Blueprint $table) use ($columna): void {
                $table->dropColumn($columna);
            });
        }

        Schema::table('productos', function (Blueprint $table): void {
            $table->dropColumn(['unidades_por_caja', 'precio_unidad', 'precio_fijo']);
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table): void {
            $table->unsignedInteger('unidades_por_caja')->default(1)->after('descripcion');
            $table->boolean('precio_fijo')->default(false)->after('margen');
            $table->decimal('precio_unidad', 14, 4)->default(0)->after('costo_unidad');
        });

        // La base da el precio por unidad; la presentación más grande, la caja.
        DB::statement('UPDATE productos SET precio_unidad = p.precio, precio_fijo = p.precio_fijo FROM presentaciones p WHERE p.producto_id = productos.id AND p.es_base');
        DB::statement('UPDATE productos SET unidades_por_caja = m.contenido FROM (SELECT producto_id, MAX(contenido) AS contenido FROM presentaciones GROUP BY producto_id) m WHERE m.producto_id = productos.id');

        DB::statement('UPDATE compra_items SET precio_nuevo = ROUND(compra_items.precio_nuevo / p.contenido, 4) FROM presentaciones p WHERE p.id = compra_items.presentacion_id AND NOT p.es_base');

        foreach (['pedido_items' => ['unidad', 'presentacion_id'], 'compra_items' => ['unidad', 'presentacion_id'], 'promocion_condiciones' => ['unidad', 'presentacion_id'], 'promociones' => ['beneficio_unidad', 'beneficio_presentacion_id']] as $tabla => [$unidad, $presentacion]) {
            Schema::table($tabla, function (Blueprint $table) use ($unidad): void {
                $table->string($unidad, 10)->default('unidad');
            });

            DB::statement("UPDATE {$tabla} SET {$unidad} = CASE WHEN p.es_base THEN 'unidad' ELSE 'caja' END FROM presentaciones p WHERE p.id = {$tabla}.{$presentacion}");

            Schema::table($tabla, function (Blueprint $table) use ($presentacion): void {
                $table->dropConstrainedForeignId($presentacion);
            });
        }

        Schema::table('pedido_items', function (Blueprint $table): void {
            $table->dropColumn('contenido');
        });

        Schema::table('cambios_precio', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('presentacion_id');
        });

        Schema::dropIfExists('presentaciones');

        Schema::table('productos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('unidad_medida_id');
        });

        Schema::dropIfExists('unidades_medida');
    }

    /**
     * Pone en $columna la presentación que le toca a cada fila: la de caja
     * si decía "caja" (y el producto la tiene), si no la base.
     */
    private static function apuntar(string $tabla, string $columna, string $producto, string $unidad): void
    {
        DB::statement("UPDATE {$tabla} SET {$columna} = p.id FROM presentaciones p WHERE p.producto_id = {$tabla}.{$producto} AND {$tabla}.{$unidad} = 'caja' AND NOT p.es_base");
        DB::statement("UPDATE {$tabla} SET {$columna} = p.id FROM presentaciones p WHERE {$tabla}.{$columna} IS NULL AND p.producto_id = {$tabla}.{$producto} AND p.es_base");
    }
};
