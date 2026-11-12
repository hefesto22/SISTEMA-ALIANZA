<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Línea de un pedido: un producto en una de sus presentaciones (UN, CJ12,
 * FD6…). `cantidad` es lo que pidió el cliente; `cantidad_despachada` es lo
 * que bodega confirmó que sale (null = sin validar). `contenido` son las
 * unidades base que trae la presentación (se copia al guardar la línea: si
 * después cambia la presentación, lo ya pedido no cambia).
 *
 * @property int $id
 * @property int $pedido_id
 * @property int $producto_id
 * @property int $presentacion_id
 * @property int $contenido
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
 * @property-read Presentacion $presentacion
 * @property-read Promocion|null $promocion
 */
class PedidoItem extends Model
{
    protected $table = 'pedido_items';

    /** @var list<string> */
    protected $fillable = [
        'pedido_id',
        'producto_id',
        'presentacion_id',
        'cantidad',
        'cantidad_despachada',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contenido'           => 'integer',
            'presentacion_id'     => 'integer',
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
     * Al poner (o cambiar) la presentación se copian sus unidades y su
     * producto. Sin presentación, la base del producto.
     */
    protected static function booted(): void
    {
        static::saving(function (PedidoItem $item): void {
            if (empty($item->presentacion_id)) {
                $item->presentacion_id = Presentacion::query()->where('producto_id', $item->producto_id)->where('es_base', true)->valueOrFail('id');
            }

            if ($item->isDirty('presentacion_id') || ! $item->exists) {
                $presentacion = Presentacion::query()->findOrFail($item->presentacion_id);
                $item->contenido = $presentacion->contenido;
                $item->producto_id = $presentacion->producto_id;
                $item->setRelation('presentacion', $presentacion);
            }
        });
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
     * @return BelongsTo<Presentacion, $this>
     */
    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(Presentacion::class);
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

    /**
     * Unidades base que saca la línea (lo que se cobra × lo que trae).
     */
    public function unidades(): int
    {
        return $this->cantidadFacturable() * max(1, $this->contenido);
    }

    public function faltante(): int
    {
        return $this->cantidad_despachada === null ? 0 : max(0, $this->cantidad - $this->cantidad_despachada);
    }

    /**
     * Lo que bodega mandó de más sobre lo pedido (0 si no).
     */
    public function deMas(): int
    {
        return $this->cantidad_despachada === null ? 0 : max(0, $this->cantidad_despachada - $this->cantidad);
    }

    /**
     * Bodega la agregó al validar o al corregir: el cliente no pidió nada de
     * ese producto (en ninguna presentación).
     */
    public function esAgregado(): bool
    {
        return $this->cantidad === 0
            && ! self::query()
                ->where('pedido_id', $this->pedido_id)
                ->where('producto_id', $this->producto_id)
                ->where('cantidad', '>', 0)
                ->exists();
    }

    /**
     * "Va de más: 1 unidad más de lo pedido (pidió 2)" (corto: "+1 unidad,
     * pidió 2"). Si bodega la mandó en otra presentación (pidió 0): "Va de
     * más: 45 unidades (no lo pidió en unidades)". Si el producto no venía en
     * el pedido: "Agregado: 2 cajas de 12 (no venía en el pedido)". Null si
     * no salió de más.
     */
    public function deMasEnPalabras(bool $corto = false): ?string
    {
        $mas = $this->deMas();

        if ($mas === 0) {
            return null;
        }

        $sale = $this->presentacion->enPalabras($mas);

        if ($this->esAgregado()) {
            return $corto ? "+{$sale}, no venía en el pedido" : "Agregado: {$sale} (no venía en el pedido)";
        }

        if ($this->cantidad === 0) {
            $enOtra = 'no lo pidió en '.$this->presentacion->palabra(2);

            return $corto ? "+{$sale}, {$enOtra}" : "Va de más: {$sale} ({$enOtra})";
        }

        return $corto
            ? "+{$sale}, pidió {$this->cantidad}"
            : "Va de más: {$sale} más de lo pedido (pidió {$this->cantidad})";
    }

    /**
     * Lo que no va completo, en palabras para que se sepa en qué
     * presentación: "No sale: pidió 2 cajas de 12" o "No va completo: falta
     * 1 caja de 12 (pidió 3)" (corto: "no sale: pidió 2 cajas de 12", "falta
     * 1 caja de 12, pidió 3"). Null si va completa o todavía no se valida.
     */
    public function faltaEnPalabras(bool $corto = false): ?string
    {
        if ($this->cantidad === 0) {
            return null;
        }

        if ($this->cantidad_despachada === 0) {
            return ($corto ? 'no sale' : 'No sale').': pidió '.$this->presentacion->enPalabras($this->cantidad);
        }

        $falta = $this->faltante();

        if ($falta === 0) {
            return null;
        }

        $falta = ($falta === 1 ? 'falta ' : 'faltan ').$this->presentacion->enPalabras($falta);

        return $corto ? "{$falta}, pidió {$this->cantidad}" : "No va completo: {$falta} (pidió {$this->cantidad})";
    }
}
