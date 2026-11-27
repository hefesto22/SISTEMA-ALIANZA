<?php

declare(strict_types=1);

namespace App\Domain\Promociones;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Models\PedidoItem;
use App\Models\PlanPromocion;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Arma o cambia un plan del proveedor con sus líneas, como viene en la hoja.
 * Cada línea es una promoción del plan (gratis, contando unidades, con
 * tope): "24+1 de NÉCTAR MANZANA o PERA, 1,000 unidades". Cada producto
 * cuenta aparte (no se mezclan) y se regala de ese mismo, en su unidad: no
 * se escoge el regalo. Las fechas y las bodegas del plan pasan a sus
 * promociones.
 *
 * Al cambiarlo: las líneas que siguen se actualizan (lo ya regalado sigue
 * contando para su tope), las nuevas se crean y las que se quitan se
 * borran; una que ya se dio en pedidos no se quita (para cerrarla se le baja
 * el tope a lo que ya se regaló).
 *
 * El encargado solo arma planes de su bodega.
 */
final class GuardarPlanPromocion
{
    /**
     * @param array{
     *     proveedor_id: int,
     *     nombre: string,
     *     vigente_desde: string,
     *     vigente_hasta: string,
     *     archivo?: string|null,
     *     notas?: string|null,
     *     bodegas?: list<int>,
     *     lineas: list<array{id?: int|null, productos: list<int>, compra: int, regala: int, tope: int, porcentaje?: float|null, activa?: bool}>
     * } $datos
     */
    public function ejecutar(?PlanPromocion $plan, array $datos, User $usuario): PlanPromocion
    {
        $desde = Carbon::parse($datos['vigente_desde'])->startOfDay();
        $hasta = Carbon::parse($datos['vigente_hasta'])->startOfDay();

        if ($hasta->lt($desde)) {
            throw new ReglaNegocioException('La fecha "hasta" del plan no puede ser antes de "desde".');
        }

        if ($datos['lineas'] === []) {
            throw new ReglaNegocioException('El plan necesita al menos una línea autorizada.');
        }

        $bodegas = $usuario->veTodasLasBodegas()
            ? array_values(array_unique($datos['bodegas'] ?? []))
            : ($usuario->bodega_id !== null ? [$usuario->bodega_id] : throw new ReglaNegocioException('Para armar un plan necesita tener una bodega asignada.'));

        return DB::transaction(function () use ($plan, $datos, $usuario, $desde, $hasta, $bodegas): PlanPromocion {
            $plan ??= new PlanPromocion(['created_by' => $usuario->id]);
            $plan->fill([
                'proveedor_id'  => $datos['proveedor_id'],
                'nombre'        => $datos['nombre'],
                'vigente_desde' => $desde->toDateString(),
                'vigente_hasta' => $hasta->toDateString(),
                'archivo'       => $datos['archivo'] ?? null,
                'notas'         => $datos['notas'] ?? null,
            ])->save();
            $plan->bodegas()->sync($bodegas);

            $actuales = $plan->promociones()->get()->keyBy('id');
            $siguen = [];

            foreach ($datos['lineas'] as $numero => $linea) {
                $id = $linea['id'] ?? null;
                $promocion = $id !== null ? $actuales->get($id) : null;
                $promocion = $this->guardarLinea($plan, $promocion instanceof Promocion ? $promocion : null, $linea, $numero + 1, $usuario);
                $promocion->bodegas()->sync($bodegas);
                $siguen[$promocion->id] = true;
            }

            foreach ($actuales as $id => $promocion) {
                if (isset($siguen[$id])) {
                    continue;
                }

                if (PedidoItem::query()->where('promocion_id', $id)->exists()) {
                    throw new ReglaNegocioException("La línea {$promocion->mecanica()} de {$promocion->productoBeneficio->descripcion} ({$promocion->codigo}) ya se dio en pedidos: no se quita. Para cerrarla, bájele el tope a lo que ya se regaló.");
                }

                $promocion->delete();
            }

            return $plan->refresh();
        });
    }

    /**
     * Una línea de la hoja: la promoción gratis que cuenta unidades, con su
     * tope. El regalo que queda guardado es el primer producto, en su unidad
     * (para el nombre y la sigla); el motor regala del que se compró.
     *
     * @param array{id?: int|null, productos: list<int>, compra: int, regala: int, tope: int, porcentaje?: float|null, activa?: bool} $linea
     */
    private function guardarLinea(PlanPromocion $plan, ?Promocion $promocion, array $linea, int $numero, User $usuario): Promocion
    {
        $productos = Producto::query()->whereKey(array_values(array_unique($linea['productos'])))->with('presentaciones')->get();

        if ($productos->isEmpty()) {
            throw new ReglaNegocioException("Línea {$numero}: escoja los productos que compra el cliente.");
        }

        if ($linea['compra'] < 1 || $linea['regala'] < 1) {
            throw new ReglaNegocioException("Línea {$numero}: la mecánica es cuántas compra y cuántas se regalan (por ejemplo 24 + 1).");
        }

        if ($linea['tope'] < 0) {
            throw new ReglaNegocioException("Línea {$numero}: el tope son las unidades de regalo que autorizó el proveedor (0 o más).");
        }

        $compra = $productos->sortBy(fn (Producto $p): int => (int) array_search($p->id, $linea['productos'], true))->values();
        $regalo = $compra->first();
        $baseRegalo = $regalo?->presentaciones->first(fn (Presentacion $p): bool => $p->es_base);

        if (! $regalo instanceof Producto || ! $baseRegalo instanceof Presentacion) {
            throw new ReglaNegocioException("Línea {$numero}: {$regalo?->descripcion} no tiene unidad base.");
        }
        $nombre = "{$linea['compra']}+{$linea['regala']} {$compra->first()?->descripcion}".($compra->count() > 1 ? ' Y '.($compra->count() - 1).' MÁS' : '');

        $promocion ??= new Promocion(['created_by' => $usuario->id, 'activa' => true]);
        $promocion->fill([
            'plan_id'                   => $plan->id,
            'nombre'                    => Str::limit($nombre, 250, ''),
            'descripcion'               => null,
            'producto_beneficio_id'     => $regalo->id,
            'beneficio_presentacion_id' => $baseRegalo->id,
            'cantidad_beneficio'        => $linea['regala'],
            'descuento_porcentaje'      => 100,
            'compra_unidades'           => $linea['compra'],
            'tope_unidades'             => $linea['tope'],
            'porcentaje_autorizado'     => $linea['porcentaje'] ?? null,
            'vigente_desde'             => $plan->vigente_desde->toDateString(),
            'vigente_hasta'             => $plan->vigente_hasta->toDateString(),
        ]);

        if (array_key_exists('activa', $linea)) {
            $promocion->activa = $linea['activa'];
        }

        $promocion->save();

        // Los productos que cuentan (cada uno en su unidad base).
        $promocion->condiciones()->delete();

        foreach ($compra as $producto) {
            $base = $producto->presentaciones->first(fn (Presentacion $p): bool => $p->es_base);

            if (! $base instanceof Presentacion) {
                throw new ReglaNegocioException("Línea {$numero}: {$producto->descripcion} no tiene unidad base.");
            }

            $promocion->condiciones()->create([
                'producto_id'     => $producto->id,
                'presentacion_id' => $base->id,
                'cantidad'        => $linea['compra'],
            ]);
        }

        return $promocion;
    }

    /**
     * Lo que vale al costo la unidad del regalo de una línea: como cada
     * producto regala del mismo, el promedio de sus unidades (las que tienen
     * costo). Null si ninguna tiene.
     *
     * @param iterable<Presentacion|null> $bases
     */
    public static function costoDelRegalo(iterable $bases): ?float
    {
        $costos = [];

        foreach ($bases as $base) {
            if ($base instanceof Presentacion && $base->costo > 0) {
                $costos[] = $base->costo;
            }
        }

        return $costos === [] ? null : round(array_sum($costos) / count($costos), 4);
    }

    /**
     * El % que da la mecánica al costo: lo que vale el regalo ÷ lo que vale
     * lo que compra. "24+1" del mismo producto = 4.17 %. Null si falta el costo.
     */
    public static function porcentaje(int $compra, int $regala, ?Presentacion $baseCompra, ?Presentacion $baseRegalo): ?float
    {
        if ($compra < 1 || $regala < 1 || $baseCompra === null || $baseRegalo === null || $baseCompra->costo <= 0 || $baseRegalo->costo <= 0) {
            return null;
        }

        return round($regala * $baseRegalo->costo / ($compra * $baseCompra->costo) * 100, 2);
    }
}
