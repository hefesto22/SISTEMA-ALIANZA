<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Comisiones\Escala;
use App\Models\Concerns\GuardaEnMayusculas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Grupo de comisión ("LIMPIEZA"): junta categorías de cualquier proveedor
 * y tiene la escala de comisión para todos los vendedores (escalones con
 * "desde" y %). Un vendedor puede tener la suya (ComisionMeta).
 *
 * Desactivado deja de pagar comisión (lo vendido de sus categorías cuenta
 * como venta sin comisión). Si ya se pagó alguna vez no se borra.
 *
 * @property int $id
 * @property string $nombre
 * @property string|null $descripcion
 * @property list<array{desde: float, porcentaje: float}> $escalones
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ComisionGrupo extends Model
{
    use GuardaEnMayusculas;

    protected $table = 'comision_grupos';

    /** @var list<string> */
    protected $fillable = [
        'nombre',
        'descripcion',
        'escalones',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'escalones' => 'array',
            'activo'    => 'boolean',
        ];
    }

    public function escala(): Escala
    {
        return Escala::de($this->escalones);
    }

    /**
     * @return HasMany<Categoria, $this>
     */
    public function categorias(): HasMany
    {
        return $this->hasMany(Categoria::class);
    }

    /**
     * @return HasMany<ComisionMeta, $this>
     */
    public function metas(): HasMany
    {
        return $this->hasMany(ComisionMeta::class);
    }

    /**
     * Ya se pagó en algún cierre: no se borra, se desactiva.
     */
    public function seUso(): bool
    {
        return ComisionLiquidacion::query()->where('comision_grupo_id', $this->id)->exists()
            || ComisionFactura::query()->where('comision_grupo_id', $this->id)->exists();
    }

    /**
     * @param Builder<ComisionGrupo> $query
     *
     * @return Builder<ComisionGrupo>
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * @return list<string>
     */
    protected function camposEnMayusculas(): array
    {
        return ['nombre'];
    }
}
