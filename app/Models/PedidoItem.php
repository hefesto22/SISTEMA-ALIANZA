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

    /**
     * Lo que bodega mandó de más sobre lo pedido (0 si no).
     */
    public function deMas(): int
    {
        return $this->cantidad_despachada === null ? 0 : max(0, $this->cantidad_despachada - $this->cantidad);
    }

    /**
     * "Va de más: 1 unidad más de lo pedido (pidió 2)" (corto: "+1 unidad,
     * pidió 2"). Si bodega la agregó en la otra unidad (pidió 0): "Va de
     * más: 45 unidades (no lo pidió en unidades)". Null si no salió de más.
     */
    public function deMasEnPalabras(bool $corto = false): ?string
    {
        $mas = $this->deMas();

        if ($mas === 0) {
            return null;
        }

        if ($this->cantidad === 0) {
            $enOtra = 'no lo pidió en '.($this->unidad === UnidadVenta::Caja ? 'cajas' : 'unidades');

            return $corto
                ? '+'.self::enPalabras($mas, $this->unidad).', '.$enOtra
                : 'Va de más: '.self::enPalabras($mas, $this->unidad).' ('.$enOtra.')';
        }

        return $corto
            ? '+'.self::enPalabras($mas, $this->unidad).', pidió '.$this->cantidad
            : 'Va de más: '.self::enPalabras($mas, $this->unidad).' más de lo pedido (pidió '.$this->cantidad.')';
    }

    /**
     * Lo que no va completo, en palabras para que se sepa si fue la caja o la
     * unidad: "No sale: pidió 2 cajas" o "No va completo: falta 1 caja de 3"
     * (corto: "no sale: pidió 2 cajas", "falta 1 caja de 3"). Null si va
     * completa o todavía no se valida.
     */
    public function faltaEnPalabras(bool $corto = false): ?string
    {
        if ($this->cantidad === 0) {
            return null;
        }

        if ($this->cantidad_despachada === 0) {
            return ($corto ? 'no sale' : 'No sale').': pidió '.self::enPalabras($this->cantidad, $this->unidad);
        }

        $falta = $this->faltante();

        if ($falta === 0) {
            return null;
        }

        $texto = ($falta === 1 ? 'falta ' : 'faltan ').self::enPalabras($falta, $this->unidad).' de '.$this->cantidad;

        return $corto ? $texto : 'No va completo: '.$texto;
    }

    /**
     * "1 caja", "3 cajas", "1 unidad", "5 unidades".
     */
    public static function enPalabras(int $cantidad, UnidadVenta $unidad): string
    {
        $palabra = match ($unidad) {
            UnidadVenta::Caja   => $cantidad === 1 ? 'caja' : 'cajas',
            UnidadVenta::Unidad => $cantidad === 1 ? 'unidad' : 'unidades',
        };

        return "{$cantidad} {$palabra}";
    }
}
