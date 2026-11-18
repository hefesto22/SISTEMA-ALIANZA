<?php

declare(strict_types=1);

use App\Models\Configuracion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Comisiones de los vendedores por grupo de productos, sobre la venta neta
 * facturada (sin ISV, ya con descuentos).
 *
 *  - configuracion: cada cuánto se paga (quincenal o mensual) y el día en
 *    que termina la primera quincena (14 = del 1 al 14 y del 15 a fin de mes).
 *  - comision_grupos: "LIMPIEZA" con su escala para todos, en JSON:
 *    [{"desde": 0, "porcentaje": 0.5}, {"desde": 80000, "porcentaje": 1}, …]
 *    (porcentaje en %, desde en lempiras por periodo). Lo que vende de un
 *    grupo paga el % del escalón que alcanzó, sobre toda esa venta.
 *  - categorias.comision_grupo_id: qué categorías (de cualquier proveedor)
 *    entran en cada grupo; una categoría va en un solo grupo.
 *  - comision_metas: la escala propia de un vendedor en un grupo (en lugar
 *    de la de todos).
 *  - comision_periodos / comision_liquidaciones / comision_facturas: lo que
 *    se pagó al cerrar un periodo. Ya no cambia: si después se anula una
 *    factura que ya se pagó, se descuenta en el siguiente cierre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion', function (Blueprint $table): void {
            $table->string('comision_frecuencia', 10)->default('quincenal');
            $table->unsignedSmallInteger('comision_corte')->default(14);
        });

        // La configuración en caché todavía no trae las columnas nuevas.
        Cache::forget(Configuracion::CACHE);

        Schema::create('comision_grupos', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre', 80)->unique();
            $table->string('descripcion', 255)->nullable();
            $table->json('escalones');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::table('categorias', function (Blueprint $table): void {
            $table->foreignId('comision_grupo_id')->nullable()->after('margen')->constrained('comision_grupos')->nullOnDelete();
        });

        Schema::create('comision_metas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comision_grupo_id')->constrained('comision_grupos')->cascadeOnDelete();
            $table->foreignId('vendedor_id')->constrained('users')->cascadeOnDelete();
            $table->json('escalones');
            $table->timestamps();

            $table->unique(['comision_grupo_id', 'vendedor_id']);
            $table->index('vendedor_id');
        });

        Schema::create('comision_periodos', function (Blueprint $table): void {
            $table->id();
            $table->date('desde');
            $table->date('hasta');
            $table->string('frecuencia', 10);
            $table->decimal('venta', 14, 2)->default(0);
            $table->decimal('comision', 14, 2)->default(0);
            $table->unsignedInteger('facturas')->default(0);
            $table->timestamp('cerrado_at');
            $table->foreignId('cerrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nota', 255)->nullable();
            $table->timestamps();

            $table->unique(['desde', 'hasta']);
            $table->index('hasta');
        });

        Schema::create('comision_liquidaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comision_periodo_id')->constrained('comision_periodos')->cascadeOnDelete();
            $table->foreignId('vendedor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('comision_grupo_id')->constrained('comision_grupos')->restrictOnDelete();
            // Lo que dice el grupo al cerrar (por si después le cambian el nombre).
            $table->string('grupo', 80);
            $table->decimal('venta', 14, 2);
            $table->decimal('anulado', 14, 2)->default(0);
            $table->decimal('base', 14, 2);
            $table->decimal('porcentaje', 7, 4);
            $table->decimal('comision', 14, 2);
            $table->decimal('meta', 14, 2)->nullable();
            $table->json('escalones');
            $table->boolean('escala_propia')->default(false);
            $table->unsignedInteger('facturas')->default(0);
            $table->timestamps();

            $table->unique(['comision_periodo_id', 'vendedor_id', 'comision_grupo_id']);
            $table->index('vendedor_id');
        });

        Schema::create('comision_facturas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comision_periodo_id')->constrained('comision_periodos')->cascadeOnDelete();
            $table->foreignId('factura_id')->constrained('facturas')->restrictOnDelete();
            $table->foreignId('vendedor_id')->constrained('users')->restrictOnDelete();
            // Null: lo de la factura que no es de ningún grupo (cuenta en la venta total, no paga comisión).
            $table->foreignId('comision_grupo_id')->nullable()->constrained('comision_grupos')->restrictOnDelete();
            // venta = se pagó en este cierre; anulada = se descontó porque la anularon después de pagarla.
            $table->string('tipo', 10);
            $table->decimal('neta', 14, 2);
            $table->timestamps();

            $table->index(['factura_id', 'tipo']);
            $table->index('comision_periodo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comision_facturas');
        Schema::dropIfExists('comision_liquidaciones');
        Schema::dropIfExists('comision_periodos');
        Schema::dropIfExists('comision_metas');

        Schema::table('categorias', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('comision_grupo_id');
        });

        Schema::dropIfExists('comision_grupos');

        Schema::table('configuracion', function (Blueprint $table): void {
            $table->dropColumn(['comision_frecuencia', 'comision_corte']);
        });

        Cache::forget(Configuracion::CACHE);
    }
};
