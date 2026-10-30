<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardaEnMayusculas;
use App\Support\Texto;
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
 * El código (CLI-000001) se genera solo. Nombres repetidos: ver
 * conElMismoNombre().
 *
 * @property int $id
 * @property string|null $codigo
 * @property string $nombre
 * @property string|null $nombre_clave
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
    use GuardaEnMayusculas;

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
     * Código propio del cliente (CLI-000001) y la clave del nombre para
     * encontrar repetidos.
     */
    protected static function booted(): void
    {
        static::saving(function (Cliente $cliente): void {
            $cliente->nombre_clave = Texto::clave((string) $cliente->nombre);
        });

        static::created(function (Cliente $cliente): void {
            if ($cliente->codigo !== null) {
                return;
            }

            $cliente->codigo = self::codigoPara($cliente->id);
            $cliente->saveQuietly();
        });
    }

    public static function codigoPara(int $id): string
    {
        return 'CLI-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * El cliente que ya existe con ese nombre y con el que chocaría uno nuevo
     * (o uno al que se le cambia el nombre), si hay:
     *
     * - Sin RTN el nombre no se puede repetir en ningún lado.
     * - Con RTN se puede repetir en otro municipio (sucursales), pero no en
     *   el mismo: ahí hay que distinguirlo (barrio, colonia…).
     * - El mismo RTN con otro nombre sí se puede.
     *
     * Se compara sin tildes, signos ni espacios de más (Texto::clave).
     */
    public static function conElMismoNombre(string $nombre, ?string $rtn, ?int $municipioId, ?int $sinContar = null): ?self
    {
        $clave = Texto::clave($nombre);

        if ($clave === '') {
            return null;
        }

        $query = self::query()
            ->with('municipio.departamento')
            ->where('nombre_clave', $clave)
            ->orderBy('id');

        if ($sinContar !== null) {
            $query->whereKeyNot($sinContar);
        }

        if (filled($rtn)) {
            $query->where('municipio_id', $municipioId);
        }

        return $query->first();
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
     * "CLI-000012 · PULPERÍA ANA · DOLORES": código y municipio para no
     * confundir clientes con el mismo nombre.
     */
    public function etiqueta(): string
    {
        return collect([$this->codigo, $this->nombre, $this->municipio?->nombre])
            ->filter()
            ->implode(' · ');
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

    /**
     * @return list<string>
     */
    protected function camposEnMayusculas(): array
    {
        return ['nombre', 'direccion'];
    }
}
