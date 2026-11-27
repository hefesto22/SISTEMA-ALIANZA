<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lo que regresa de una línea del pedido, en su presentación: buenas (vuelven
 * a la bodega) o dañadas (`danado`: no vuelven, quedan para los reportes).
 * En un faltante, lo que no recibió porque se le dio a otro cliente en un
 * cambio (`faltante`): no regresa a la bodega ni es pérdida.
 * `precio` es lo que se cobró por una, neto y sin ISV (lo de un regalo, 0);
 * `costo` es lo que costaba una al devolverla.
 *
 * @property int $id
 * @property int $devolucion_id
 * @property int|null $pedido_item_id
 * @property int $producto_id
 * @property int $presentacion_id
 * @property int $cantidad
 * @property bool $danado
 * @property bool $faltante
 * @property float $precio
 * @property bool $gravado
 * @property float $subtotal
 * @property float $isv
 * @property float $total
 * @property float $costo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Devolucion $devolucion
 * @property-read PedidoItem|null $pedidoItem
 * @property-read Producto $producto
 * @property-read Presentacion $presentacion
 */
class DevolucionItem extends Model
{
    protected $table = 'devolucion_items';

    /** @var list<string> */
    protected $fillable = [
        'pedido_item_id',
        'producto_id',
        'presentacion_id',
        'cantidad',
        'danado',
        'faltante',
        'precio',
        'gravado',
        'subtotal',
        'isv',
        'total',
        'costo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'danado'   => 'boolean',
            'faltante' => 'boolean',
            'precio'   => 'float',
            'gravado'  => 'boolean',
            'subtotal' => 'float',
            'isv'      => 'float',
            'total'    => 'float',
            'costo'    => 'float',
        ];
    }

    /**
     * @return BelongsTo<Devolucion, $this>
     */
    public function devolucion(): BelongsTo
    {
        return $this->belongsTo(Devolucion::class);
    }

    /**
     * @return BelongsTo<PedidoItem, $this>
     */
    public function pedidoItem(): BelongsTo
    {
        return $this->belongsTo(PedidoItem::class);
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
