<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una línea de la compra: en qué presentación entró (CJ de 48, UN…),
 * cuántas, a qué costo y cómo quedó esa presentación después de la compra
 * (su costo, de la presentación completa sin ISV, antes y después, y su
 * precio). costo_unidad es lo que salió cada unidad base, sin ISV.
 *
 * @property int $id
 * @property int $compra_id
 * @property int $producto_id
 * @property int $presentacion_id
 * @property int $cantidad
 * @property int $unidades
 * @property float $costo_ingresado
 * @property float $costo_unidad
 * @property bool $gravado
 * @property float $subtotal
 * @property float $isv
 * @property float $total
 * @property float|null $costo_anterior
 * @property float $costo_nuevo
 * @property float $precio_nuevo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Compra $compra
 * @property-read Producto $producto
 * @property-read Presentacion $presentacion
 */
class CompraItem extends Model
{
    protected $table = 'compra_items';

    /** @var list<string> */
    protected $fillable = [
        'compra_id',
        'producto_id',
        'presentacion_id',
        'cantidad',
        'unidades',
        'costo_ingresado',
        'costo_unidad',
        'gravado',
        'subtotal',
        'isv',
        'total',
        'costo_anterior',
        'costo_nuevo',
        'precio_nuevo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'presentacion_id' => 'integer',
            'cantidad'        => 'integer',
            'unidades'        => 'integer',
            'costo_ingresado' => 'float',
            'costo_unidad'    => 'float',
            'gravado'         => 'boolean',
            'subtotal'        => 'float',
            'isv'             => 'float',
            'total'           => 'float',
            'costo_anterior'  => 'float',
            'costo_nuevo'     => 'float',
            'precio_nuevo'    => 'float',
        ];
    }

    /**
     * @return BelongsTo<Compra, $this>
     */
    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    /**
     * @return BelongsTo<Producto, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * @return BelongsTo<Presentacion, $this>
     */
    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(Presentacion::class);
    }
}
