<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Comisiones\Escala;
use App\Models\Categoria;
use App\Models\ComisionGrupo;
use App\Models\Proveedor;
use App\Support\Texto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Grupos de comisión iniciales: las categorías del catálogo (CatalogoSeeder)
 * juntas por tipo de producto, de cualquier proveedor. Las escalas son del
 * MES por vendedor, sin ISV; el % va sobre toda la venta del grupo y nunca
 * se acerca al margen de esas categorías:
 *
 *  - LIMPIEZA (margen 15-20 %): 0.5 % · 1 % desde 80,000 · 2 % desde 100,000.
 *  - UNILEVER (todo Unilever viene en una sola categoría, 16 %):
 *    0.5 % · 1 % desde 60,000 · 1.5 % desde 90,000.
 *  - CUIDADO PERSONAL (14-37 %): 1 % · 1.5 % desde 25,000 · 2 % desde 40,000.
 *  - ALIMENTOS Y DULCES (13-16 %): 0.5 % · 1 % desde 40,000 · 1.5 % desde 60,000.
 *  - BEBÉ (los pañales dejan 3 %): 0.25 % · 0.5 % desde 30,000.
 *
 * Esta es la escala de todas las bodegas. Cada bodega pone sus montos en
 * Grupos de comisión → "Por bodega" (o "Mi bodega", el encargado).
 *
 * Solo CREA lo que falta: un grupo que ya existe (por nombre) conserva su
 * escala y lo que ya tiene, y una categoría que ya está en un grupo no se
 * mueve. Se puede correr otra vez sin pisar lo que se cambió en el panel.
 *
 *   php artisan db:seed --class=ComisionGruposSeeder
 */
class ComisionGruposSeeder extends Seeder
{
    /**
     * Nombre => [descripción, escalones, [código del proveedor => categorías]].
     *
     * Proveedores (data/proveedores.json): 1 Unilever, 500001 DCASA,
     * 500004 Surtidora, 500006 DiLImport, 500008 MERHONSA, 500011 Intercosmo.
     *
     * @var array<string, array{0: string, 1: list<array{desde: int, porcentaje: float}>, 2: array<int, list<string>>}>
     */
    private const GRUPOS = [
        'LIMPIEZA' => [
            'Detergentes, jabón de lavar, suavizantes, cloro y limpieza del hogar.',
            [['desde' => 0, 'porcentaje' => 0.5], ['desde' => 80000, 'porcentaje' => 1.0], ['desde' => 100000, 'porcentaje' => 2.0]],
            [
                500008 => ['JABON BARRA', 'JABON DETERGENTE', 'DETERGENTES', 'CREMAS DETERGENTES', 'DET. INDUSTRIALES', 'DETERGENTES 115G', 'DETERGENTES 2.3KG', 'SUAVIZANTES'],
                500001 => ['HOME CARE'],
                500004 => ['CUIDADO HOGAR'],
                500011 => ['HC'],
            ],
        ],
        'UNILEVER' => [
            'Todo lo de Unilever (en el catálogo viene en una sola categoría).',
            [['desde' => 0, 'porcentaje' => 0.5], ['desde' => 60000, 'porcentaje' => 1.0], ['desde' => 90000, 'porcentaje' => 1.5]],
            [
                1 => ['GENERAL'],
            ],
        ],
        'CUIDADO PERSONAL' => [
            'Jabón de tocador, pasta dental, toallas sanitarias y cuidado del cabello.',
            [['desde' => 0, 'porcentaje' => 1.0], ['desde' => 25000, 'porcentaje' => 1.5], ['desde' => 40000, 'porcentaje' => 2.0]],
            [
                500008 => ['CUIDADO PERSONAL'],
                500001 => ['PERSONAL CARE'],
                500004 => ['CUIDADO PERSONAL BN', 'CUIDADO PERSONAL CL'],
                500011 => ['PC'],
            ],
        ],
        'ALIMENTOS Y DULCES' => [
            'Aceites, bebidas, bebidas en polvo, alimentos y dulces.',
            [['desde' => 0, 'porcentaje' => 0.5], ['desde' => 40000, 'porcentaje' => 1.0], ['desde' => 60000, 'porcentaje' => 1.5]],
            [
                500008 => ['ACEITES', 'BEBIDAS'],
                500001 => ['BEBIDAS POLVO', 'DULCES', 'ALIMENTOS'],
                500006 => ['DULCES'],
            ],
        ],
        'BEBÉ' => [
            'Pañales y toallas húmedas. Comisión baja: los pañales dejan poco margen.',
            [['desde' => 0, 'porcentaje' => 0.25], ['desde' => 30000, 'porcentaje' => 0.5]],
            [
                500011 => ['PAÑALES', 'TOALLAS', 'TOALLAS E'],
                500006 => ['PAÑAL'],
            ],
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::GRUPOS as $nombre => [$descripcion, $escalones, $porProveedor]) {
                $grupo = ComisionGrupo::query()->firstOrCreate(
                    ['nombre' => Texto::mayusculas($nombre)],
                    ['descripcion' => $descripcion, 'escalones' => Escala::de($escalones)->paraGuardar(), 'activo' => true],
                );

                $puestas = 0;
                $enOtro = [];
                $faltan = [];

                foreach ($porProveedor as $codigo => $categorias) {
                    $proveedor = Proveedor::query()->where('codigo', (string) $codigo)->first();

                    if ($proveedor === null) {
                        $faltan[] = "proveedor {$codigo}";

                        continue;
                    }

                    $suyas = Categoria::query()->with('proveedor')->where('proveedor_id', $proveedor->id)->get()
                        ->keyBy(fn (Categoria $c): string => Texto::clave($c->nombre));

                    foreach ($categorias as $nombreCategoria) {
                        $categoria = $suyas->get(Texto::clave($nombreCategoria));

                        if (! $categoria instanceof Categoria) {
                            $faltan[] = $nombreCategoria;
                        } elseif ($categoria->comision_grupo_id === null) {
                            $categoria->update(['comision_grupo_id' => $grupo->id]);
                            $puestas++;
                        } elseif ($categoria->comision_grupo_id !== $grupo->id) {
                            $enOtro[] = $categoria->etiqueta();
                        }
                    }
                }

                $total = $grupo->categorias()->count();
                $this->command?->info("✓ {$grupo->nombre}: {$grupo->escala()->texto()} · {$total} categorías".($puestas > 0 ? " ({$puestas} nuevas)" : ''));

                if ($enOtro !== []) {
                    $this->command?->warn('  Ya estaban en otro grupo, no se movieron: '.implode(', ', $enOtro));
                }

                if ($faltan !== []) {
                    $this->command?->warn('  No se encontraron: '.implode(', ', $faltan));
                }
            }
        });
    }
}
