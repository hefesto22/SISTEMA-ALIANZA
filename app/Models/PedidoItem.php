<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UnidadVenta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Línea de un pedido. `cantidad` es lo que pidió el cliente;
 * `cantidad_despachada` es lo que bodega confirmó que sale (null = sin validar).
 *
 * @property int $id
 * @property int $pedido_id
 * @property int $producto_id
 * @property UnidadVenta $unidad
 * @property int $cantidad
 * @property int|null $cantidad_despachada
 * @property float $precio
 * @property bool $gravado
 * @property float $subtotal
 * @property float $descuento
 * @property int|null $promocion_id
 * @property float $isv
 * @property float $total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Pedido $pedido
 * @property-read Producto $producto
 * @property-read Promocion|null $promocion
 */
class PedidoItem extends Model
{
    protected $table = 'pedido_items';

    /** @var list<string> */
    protected $fillable = [
        'pedido_id',
        'producto_id',
        'unidad',
        'cantidad',
        'cantidad_despachada',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'unidad' => 'unidad',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unidad'              => UnidadVenta::class,
            'cantidad'            => 'integer',
            'cantidad_despachada' => 'integer',
            'precio'              => 'float',
            'gravado'             => 'boolean',
            'subtotal'            => 'float',
            'descuento'           => 'float',
            'isv'                 => 'float',
            'total'               => 'float',
        ];
    }

    /**
     * @return BelongsTo<Pedido, $this>
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    /**
     * @return BelongsTo<Producto, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * Promoción que le dio el descuento a esta línea (si hubo).
     *
     * @return BelongsTo<Promocion, $this>
     */
    public function promocion(): BelongsTo
    {
        return $this->belongsTo(Promocion::class);
    }

    /**
     * Cantidad que se cobra: lo despachado si bodega ya validó; si no, lo pedido.
     */
    public function cantidadFacturable(): int
    {
        return $this->cantidad_despachada ?? $this->cantidad;
    }

    public function faltante(): int
    {
        return $this->cantidad_despachada === null ? 0 : max(0, $this->cantidad - $this->cantidad_despachada);
    }
}
