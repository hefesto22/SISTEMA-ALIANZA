<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormatoFactura;
use App\Models\Concerns\GuardaEnMayusculas;
use Database\Factories\BodegaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
 * @property string|null $prefijo_ruta lo que va antes del número de sus rutas (CO → CO1, CO2…)
 * @property string $nombre
 * @property string|null $direccion
 * @property int|null $municipio_id
 * @property string|null $telefono
 * @property FormatoFactura $formato_factura en qué papel imprime las facturas
 * @property bool $activa
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Municipio|null $municipio
 */
class Bodega extends Model
{
    use GuardaEnMayusculas;

    /** @use HasFactory<BodegaFactory> */
    use HasFactory;

    protected $table = 'bodegas';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'prefijo_ruta',
        'nombre',
        'direccion',
        'municipio_id',
        'telefono',
        'formato_factura',
        'activa',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activa'          => 'boolean',
            'formato_factura' => FormatoFactura::class,
        ];
    }

    /**
     * Lo que va antes del número en el código de sus rutas (CO → CO1, CO2…):
     * el prefijo de la bodega o, si no tiene, las dos primeras letras del código.
     */
    public function prefijoDeRutas(): string
    {
        return filled($this->prefijo_ruta) ? (string) $this->prefijo_ruta : mb_strtoupper(mb_substr($this->codigo, 0, 2));
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
     * CAI asignados a esta bodega (sin contar los globales).
     *
     * @return BelongsToMany<Cai, $this>
     */
    public function cais(): BelongsToMany
    {
        return $this->belongsToMany(Cai::class, 'cai_bodega');
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

    /**
     * @return list<string>
     */
    protected function camposEnMayusculas(): array
    {
        return ['codigo', 'prefijo_ruta', 'nombre', 'direccion'];
    }
}
