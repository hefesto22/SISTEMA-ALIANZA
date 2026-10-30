<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardaEnMayusculas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Compra a un proveedor que entra a UNA bodega. Se registra con
 * App\Domain\Inventario\RegistrarCompra: suma a la existencia de esa
 * bodega y actualiza costo y precio de cada producto. Número propio:
 * COM-000001.
 *
 * @property int $id
 * @property string|null $numero
 * @property int $bodega_id
 * @property int $proveedor_id
 * @property string|null $factura_proveedor
 * @property Carbon $fecha
 * @property float $subtotal
 * @property float $isv
 * @property float $total
 * @property string|null $notas
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Bodega $bodega
 * @property-read Proveedor $proveedor
 * @property-read User|null $usuario
 */
class Compra extends Model
{
    use GuardaEnMayusculas;

    protected $table = 'compras';

    /** @var list<string> */
    protected $fillable = [
        'numero',
        'bodega_id',
        'proveedor_id',
        'factura_proveedor',
        'fecha',
        'subtotal',
        'isv',
        'total',
        'notas',
        'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha'    => 'date',
            'subtotal' => 'float',
            'isv'      => 'float',
            'total'    => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Compra $compra): void {
            if ($compra->numero !== null) {
                return;
            }

            $compra->numero = 'COM-'.str_pad((string) $compra->id, 6, '0', STR_PAD_LEFT);
            $compra->saveQuietly();
        });
    }

    /**
     * @return BelongsTo<Bodega, $this>
     */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    /**
     * @return BelongsTo<Proveedor, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<CompraItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CompraItem::class);
    }

    /**
     * Administración ve todas; los demás, las de su bodega.
     *
     * @param Builder<Compra> $query
     *
     * @return Builder<Compra>
     */
    public function scopeVisiblePara(Builder $query, User $user): Builder
    {
        if ($user->veTodasLasBodegas()) {
            return $query;
        }

        return $query->where('bodega_id', $user->bodega_id);
    }

    /**
     * @return list<string>
     */
    protected function camposEnMayusculas(): array
    {
        return ['factura_proveedor', 'notas'];
    }
}
