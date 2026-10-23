<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MotivoCambioPrecio;
use App\Models\CambioPrecio;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Proveedor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Categorías (con su % de utilidad) y productos (con costo y precio) de los
 * Excel "Cálculo de precios" de cada proveedor, ya normalizados en
 * database/seeders/data/catalogo.json:
 *
 *  - costo_unidad: costo por unidad sin ISV, ya con descuentos.
 *  - precio_unidad: el precio por unidad sin ISV que mostraba el Excel.
 *  - margen: solo si en el Excel el % estaba puesto a mano en esa fila.
 *
 * Solo CREA lo que no existe: si se vuelve a correr no pisa precios que
 * ya se cambiaron en el sistema.
 */
class CatalogoSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string, array{archivo: string, categorias: list<array{nombre: string, margen: float}>, productos: list<array{codigo: string, descripcion: string, unidades_por_caja: int, categoria: string, gravado: bool, costo_unidad: float, precio_unidad: float, margen: float|null}>}> $catalogo */
        $catalogo = json_decode(File::get(database_path('seeders/data/catalogo.json')), true, flags: JSON_THROW_ON_ERROR);

        $creados = 0;

        DB::transaction(function () use ($catalogo, &$creados): void {
            foreach ($catalogo as $codigoProveedor => $datos) {
                $proveedor = Proveedor::query()->where('codigo', (string) $codigoProveedor)->first();

                if ($proveedor === null) {
                    $this->command?->warn("  Proveedor {$codigoProveedor} no existe; se salta {$datos['archivo']}.");

                    continue;
                }

                // El nombre del Excel sirve como nombre corto (Unilever, MERHONSA…).
                if (blank($proveedor->nombre_corto)) {
                    $proveedor->forceFill(['nombre_corto' => $datos['archivo']])->save();
                }

                $categorias = [];

                foreach ($datos['categorias'] as $c) {
                    $categorias[$c['nombre']] = Categoria::query()->firstOrCreate(
                        ['proveedor_id' => $proveedor->id, 'nombre' => $c['nombre']],
                        ['margen' => $c['margen']],
                    );
                }

                $existentes = Producto::query()->where('proveedor_id', $proveedor->id)->pluck('codigo')->flip();
                $historial = [];
                $ahora = now();

                foreach ($datos['productos'] as $p) {
                    if ($existentes->has($p['codigo']) || ! isset($categorias[$p['categoria']])) {
                        continue;
                    }

                    $categoria = $categorias[$p['categoria']];

                    $producto = new Producto([
                        'proveedor_id'      => $proveedor->id,
                        'categoria_id'      => $categoria->id,
                        'codigo'            => $p['codigo'],
                        'descripcion'       => $p['descripcion'],
                        'unidades_por_caja' => max(1, $p['unidades_por_caja']),
                        'gravado'           => $p['gravado'],
                        'margen'            => $p['margen'],
                        'activo'            => true,
                    ]);
                    $producto->forceFill([
                        'costo_unidad'          => $p['costo_unidad'],
                        'precio_unidad'         => $p['precio_unidad'],
                        'precio_actualizado_at' => $ahora,
                    ])->save();

                    $historial[] = [
                        'producto_id'     => $producto->id,
                        'user_id'         => null,
                        'bodega_id'       => null,
                        'motivo'          => MotivoCambioPrecio::CargaInicial->value,
                        'costo_anterior'  => null,
                        'costo_nuevo'     => $p['costo_unidad'],
                        'precio_anterior' => null,
                        'precio_nuevo'    => $p['precio_unidad'],
                        'margen'          => $p['margen'] ?? $categoria->margen,
                        'nota'            => "Excel de {$datos['archivo']}",
                        'created_at'      => $ahora,
                        'updated_at'      => $ahora,
                    ];
                    $creados++;
                }

                foreach (array_chunk($historial, 500) as $lote) {
                    CambioPrecio::query()->insert($lote);
                }

                $this->command?->info("  {$proveedor->nombre}: ".count($categorias).' categorías, '.count($historial).' productos nuevos');
            }
        });

        $this->command?->info("✓ Catálogo cargado ({$creados} productos nuevos).");
    }
}
