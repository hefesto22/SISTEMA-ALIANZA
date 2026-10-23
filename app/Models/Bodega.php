<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BodegaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Bodega / centro de distribución (Santa Rosa, Ocotepeque, Lucerna…).
 *
 * Los precios NO dependen de la bodega: son los mismos en todas.
 * La bodega define qué pedidos valida cada encargado y de dónde sale
 * cada usuario cuando cambia un precio (queda en el historial).
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $direccion
 * @property int|null $municipio_id
 * @property string|null $telefono
 * @property bool $activa
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Municipio|null $municipio
 */
class Bodega extends Model
{
    /** @use HasFactory<BodegaFactory> */
    use HasFactory;

    protected $table = 'bodegas';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'direccion',
        'municipio_id',
        'telefono',
        'activa',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Pedido, $this>
     */
    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class);
    }

    /**
     * @return HasMany<Cliente, $this>
     */
    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class);
    }

    /**
     * Dónde queda la bodega.
     *
     * @return BelongsTo<Municipio, $this>
     */
    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class);
    }

    /**
     * @return HasMany<Ruta, $this>
     */
    public function rutas(): HasMany
    {
        return $this->hasMany(Ruta::class);
    }

    /**
     * @param Builder<Bodega> $query
     *
     * @return Builder<Bodega>
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }
}
