<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ClienteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Cliente de la ruta (pulpería, mini súper…).
 *
 * Queda en un municipio: lo ven los vendedores de las rutas que cubren
 * ese municipio (Administración → Rutas).
 *
 * @property int $id
 * @property string|null $codigo
 * @property string $nombre
 * @property string|null $rtn
 * @property string|null $telefono
 * @property string|null $direccion
 * @property int|null $municipio_id
 * @property int|null $bodega_id
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Bodega|null $bodega
 * @property-read Municipio|null $municipio
 */
class Cliente extends Model
{
    /** @use HasFactory<ClienteFactory> */
    use HasFactory;

    protected $table = 'clientes';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'rtn',
        'telefono',
        'direccion',
        'municipio_id',
        'bodega_id',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
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
     * @return BelongsTo<Municipio, $this>
     */
    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class);
    }

    /**
     * "Pulpería Ana · Dolores": el municipio ayuda a no confundir clientes con el mismo nombre.
     */
    public function etiqueta(): string
    {
        return $this->municipio === null ? $this->nombre : "{$this->nombre} · {$this->municipio->nombre}";
    }

    /**
     * @return HasMany<Pedido, $this>
     */
    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class);
    }

    /**
     * Administración ve todos; el vendedor, los de los municipios de sus
     * rutas activas; bodega, los de su bodega.
     *
     * @param Builder<Cliente> $query
     *
     * @return Builder<Cliente>
     */
    public function scopeVisiblePara(Builder $query, User $user): Builder
    {
        return self::filtrarVisibles($query, $user);
    }

    /**
     * La misma regla de scopeVisiblePara para cualquier consulta de clientes
     * (por ejemplo la del select de cliente en el pedido).
     *
     * @template TModel of Model
     *
     * @param Builder<TModel> $query
     *
     * @return Builder<TModel>
     */
    public static function filtrarVisibles(Builder $query, User $user): Builder
    {
        if ($user->veTodasLasBodegas()) {
            return $query;
        }

        if ($user->esVendedor()) {
            return $query->whereIn($query->qualifyColumn('municipio_id'), Ruta::municipiosDe($user));
        }

        return $query->where($query->qualifyColumn('bodega_id'), $user->bodega_id);
    }
}
