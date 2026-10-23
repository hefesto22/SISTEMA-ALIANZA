<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MotivoCambioPrecio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un renglón del historial de precios: qué cambió, quién, cuándo y desde
 * qué bodega. Solo se escribe desde ActualizarPrecioProducto; nunca se edita.
 *
 * @property int $id
 * @property int $producto_id
 * @property int|null $user_id
 * @property int|null $bodega_id
 * @property MotivoCambioPrecio $motivo
 * @property float|null $costo_anterior
 * @property float $costo_nuevo
 * @property float|null $precio_anterior
 * @property float $precio_nuevo
 * @property float $margen
 * @property string|null $nota
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Producto $producto
 * @property-read User|null $usuario
 * @property-read Bodega|null $bodega
 */
class CambioPrecio extends Model
{
    protected $table = 'cambios_precio';

    /** @var list<string> */
    protected $fillable = [
        'producto_id',
        'user_id',
        'bodega_id',
        'motivo',
        'costo_anterior',
        'costo_nuevo',
        'precio_anterior',
        'precio_nuevo',
        'margen',
        'nota',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'motivo'          => MotivoCambioPrecio::class,
            'costo_anterior'  => 'float',
            'costo_nuevo'     => 'float',
            'precio_anterior' => 'float',
            'precio_nuevo'    => 'float',
            'margen'          => 'float',
        ];
    }

    /**
     * @return BelongsTo<Producto, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Bodega, $this>
     */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    /**
     * Variación del precio en % respecto al anterior (null si no había anterior).
     */
    public function variacionPrecio(): ?float
    {
        if ($this->precio_anterior === null || $this->precio_anterior <= 0.0) {
            return null;
        }

        return round((($this->precio_nuevo - $this->precio_anterior) / $this->precio_anterior) * 100, 2);
    }
}
