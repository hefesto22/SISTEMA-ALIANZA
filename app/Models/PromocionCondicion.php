<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UnidadVenta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un producto que el cliente tiene que comprar para que aplique la promoción
 * ("2 cajas de BUTTER TOFFEE"). Una promoción puede pedir varios.
 *
 * @property int $id
 * @property int $promocion_id
 * @property int $producto_id
 * @property int $cantidad
 * @property UnidadVenta $unidad
 * @property-read Promocion $promocion
 * @property-read Producto $producto
 */
class PromocionCondicion extends Model
{
    protected $table = 'promocion_condiciones';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'promocion_id',
        'producto_id',
        'cantidad',
        'unidad',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'cantidad' => 1,
        'unidad'   => 'unidad',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'producto_id' => 'integer',
            'cantidad'    => 'integer',
            'unidad'      => UnidadVenta::class,
        ];
    }

    /**
     * @return BelongsTo<Promocion, $this>
     */
    public function promocion(): BelongsTo
    {
        return $this->belongsTo(Promocion::class);
    }

    /**
     * @return BelongsTo<Producto, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * "2 cajas de BUTTER TOFFEE 12X100G".
     */
    public function texto(): string
    {
        return Promocion::cantidadTexto($this->cantidad, $this->unidad).' de '.$this->producto->descripcion;
    }
}
