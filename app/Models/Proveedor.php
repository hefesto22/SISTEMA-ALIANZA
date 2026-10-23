<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardaEnMayusculas;
use Database\Factories\ProveedorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Proveedor (Unilever, MERHONSA, DCASA…). Cada uno tiene sus propias
 * categorías con su % de utilidad y sus propios productos.
 *
 * @property int $id
 * @property string|null $codigo
 * @property string $nombre
 * @property string|null $nombre_corto
 * @property string|null $razon_social
 * @property string|null $rtn
 * @property string|null $telefono
 * @property string|null $direccion
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Proveedor extends Model
{
    use GuardaEnMayusculas;

    /** @use HasFactory<ProveedorFactory> */
    use HasFactory;

    protected $table = 'proveedores';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'nombre_corto',
        'razon_social',
        'rtn',
        'telefono',
        'direccion',
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
     * @return HasMany<Categoria, $this>
     */
    public function categorias(): HasMany
    {
        return $this->hasMany(Categoria::class);
    }

    /**
     * @return HasMany<Producto, $this>
     */
    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class);
    }

    /**
     * @param Builder<Proveedor> $query
     *
     * @return Builder<Proveedor>
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * Nombre para pestañas y listados: el corto si existe, si no el completo.
     */
    public function etiqueta(): string
    {
        return $this->nombre_corto ?: $this->nombre;
    }

    /**
     * @return list<string>
     */
    protected function camposEnMayusculas(): array
    {
        return ['nombre', 'nombre_corto', 'razon_social', 'direccion'];
    }
}
