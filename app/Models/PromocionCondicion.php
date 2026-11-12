<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un producto que el cliente tiene que comprar para que aplique la promoción
 * ("2 cajas de 12 de BUTTER TOFFEE"), en una de sus presentaciones. Una
 * promoción puede pedir varios.
 *
 * @property int $id
 * @property int $promocion_id
 * @property int $producto_id
 * @property int $presentacion_id
 * @property int $cantidad
 * @property-read Promocion $promocion
 * @property-read Producto $producto
 * @property-read Presentacion $presentacion
 */
class PromocionCondicion extends Model
{
    protected $table = 'promocion_condiciones';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'promocion_id',
        'producto_id',
        'presentacion_id',
        'cantidad',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'cantidad' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'producto_id'     => 'integer',
            'presentacion_id' => 'integer',
            'cantidad'        => 'integer',
        ];
    }

    /**
     * Sin presentación, la base del producto.
     */
    protected static function booted(): void
    {
        static::saving(function (PromocionCondicion $condicion): void {
            if (empty($condicion->presentacion_id)) {
                $condicion->presentacion_id = Presentacion::query()->where('producto_id', $condicion->producto_id)->where('es_base', true)->valueOrFail('id');
            }
        });
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
     * @return BelongsTo<Presentacion, $this>
     */
    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(Presentacion::class);
    }

    /**
     * "2 cajas de 12 de BUTTER TOFFEE 12X100G".
     */
    public function texto(): string
    {
        return $this->presentacion->enPalabras($this->cantidad).' de '.$this->producto->descripcion;
    }
}
