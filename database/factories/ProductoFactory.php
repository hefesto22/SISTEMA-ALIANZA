<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\UnidadMedida;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Crea el producto con costos y precios ya cargados. Trae su base (UN, que
 * nace con el producto) y una CJ de 12; cada presentación con costo y
 * precio = los de la UN × lo que trae (UN: costo 50, precio 59.5238):
 *
 *   Producto::factory()->precio(80.0, 100.0)->conCaja(10)->create();  // UN cuesta 80 y vale 100; CJ10, 800 y 1000
 *   Producto::factory()->conCaja(1)->create();                       // solo la UN
 *   Producto::factory()->precioFijo()->create();                     // las compras no mueven sus precios
 *
 * @extends Factory<Producto>
 */
class ProductoFactory extends Factory
{
    /** Precio sin ISV de la UN si no se dice otro. */
    private const PRECIO = 59.5238;

    /** Costo sin ISV de la UN si no se dice otro. */
    private const COSTO = 50.0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'categoria_id'          => Categoria::factory(),
            'proveedor_id'          => fn (array $attributes): int => (int) Categoria::query()->whereKey($attributes['categoria_id'])->value('proveedor_id'),
            'codigo'                => (string) fake()->unique()->numerify('##########'),
            'descripcion'           => mb_strtoupper(fake()->word().' '.fake()->word()).' 12X100G',
            'gravado'               => true,
            'margen'                => null,
            'precio_actualizado_at' => now(),
            'activo'                => true,
        ];
    }

    /**
     * La UN con su costo y precio, y una CJ de 12.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Producto $producto): void {
            Presentacion::query()->where('producto_id', $producto->id)->where('es_base', true)->update(['precio' => self::PRECIO, 'costo' => self::COSTO]);

            Presentacion::query()->create([
                'producto_id'      => $producto->id,
                'unidad_medida_id' => self::medida('CJ'),
                'contenido'        => 12,
                'precio'           => round(self::PRECIO * 12, 4),
                'costo'            => round(self::COSTO * 12, 4),
            ]);
        });
    }

    public function exento(): static
    {
        return $this->state(fn (array $attributes): array => ['gravado' => false]);
    }

    /**
     * Costo y precio de la UN (sin ISV); cada presentación, esos × lo que trae.
     */
    public function precio(float $costo, float $precio): static
    {
        return $this->afterCreating(function (Producto $producto) use ($costo, $precio): void {
            foreach (Presentacion::query()->where('producto_id', $producto->id)->get() as $presentacion) {
                $presentacion->forceFill([
                    'precio' => round($precio * $presentacion->contenido, 4),
                    'costo'  => round($costo * $presentacion->contenido, 4),
                ])->save();
            }
        });
    }

    /**
     * Todas sus presentaciones con precio fijo (las compras no lo mueven).
     */
    public function precioFijo(): static
    {
        return $this->afterCreating(function (Producto $producto): void {
            Presentacion::query()->where('producto_id', $producto->id)->update(['precio_fijo' => true]);
        });
    }

    /**
     * La CJ trae $unidades (con 1, el producto se queda solo con la UN).
     */
    public function conCaja(int $unidades): static
    {
        return $this->afterCreating(function (Producto $producto) use ($unidades): void {
            $caja = Presentacion::query()->where('producto_id', $producto->id)->where('es_base', false)->where('unidad_medida_id', self::medida('CJ'))->first();

            if (! $caja instanceof Presentacion) {
                return;
            }

            if ($unidades <= 1) {
                $caja->delete();

                return;
            }

            $base = Presentacion::query()->where('producto_id', $producto->id)->where('es_base', true)->firstOrFail();
            $caja->forceFill(['contenido' => $unidades, 'precio' => round($base->precio * $unidades, 4), 'costo' => round($base->costo * $unidades, 4)])->save();
        });
    }

    /**
     * Otra presentación (FD de 6, SC de 10…) con costo y precio = los de la UN × lo que trae.
     */
    public function conPresentacion(string $codigo, int $unidades): static
    {
        return $this->afterCreating(function (Producto $producto) use ($codigo, $unidades): void {
            $base = Presentacion::query()->where('producto_id', $producto->id)->where('es_base', true)->firstOrFail();

            Presentacion::query()->create([
                'producto_id'      => $producto->id,
                'unidad_medida_id' => self::medida($codigo),
                'contenido'        => $unidades,
                'precio'           => round($base->precio * $unidades, 4),
                'costo'            => round($base->costo * $unidades, 4),
            ]);
        });
    }

    private static function medida(string $codigo): int
    {
        return (int) UnidadMedida::query()->where('codigo', $codigo)->valueOrFail('id');
    }
}
