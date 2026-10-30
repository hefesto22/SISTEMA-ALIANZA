<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoMovimiento;
use App\Models\Concerns\GuardaEnMayusculas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un renglón del kárdex: qué entró o salió de una bodega, cuánto quedó y
 * de dónde vino (compra, pedido, ajuste). Cantidades en UNIDADES; costo por
 * unidad y sin ISV.
 *
 * @property int $id
 * @property int $bodega_id
 * @property int $producto_id
 * @property TipoMovimiento $tipo
 * @property int $cantidad
 * @property int $existencia
 * @property float $costo_unidad
 * @property int|null $compra_id
 * @property int|null $pedido_id
 * @property int|null $user_id
 * @property string|null $nota
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Bodega $bodega
 * @property-read Producto $producto
 * @property-read Compra|null $compra
 * @property-read Pedido|null $pedido
 * @property-read User|null $usuario
 */
class MovimientoInventario extends Model
{
    use GuardaEnMayusculas;

    protected $table = 'movimientos_inventario';

    /** @var list<string> */
    protected $fillable = [
        'bodega_id',
        'producto_id',
        'tipo',
        'cantidad',
        'existencia',
        'costo_unidad',
        'compra_id',
        'pedido_id',
        'user_id',
        'nota',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo'         => TipoMovimiento::class,
            'cantidad'     => 'integer',
            'existencia'   => 'integer',
            'costo_unidad' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Bodega, $this>
     */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    /**
     * @return BelongsTo<Producto, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * @return BelongsTo<Compra, $this>
     */
    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    /**
     * @return BelongsTo<Pedido, $this>
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return list<string>
     */
    protected function camposEnMayusculas(): array
    {
        return ['nota'];
    }
}
