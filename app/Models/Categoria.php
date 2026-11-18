<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardaEnMayusculas;
use Database\Factories\CategoriaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Categoría de un proveedor con su % de utilidad (margen sobre venta).
 *
 * Ej.: MERHONSA → ACEITES 13%, SUAVIZANTES 18%. Unilever → GENERAL 16%.
 * El margen se guarda como fracción: 0.13 = 13%.
 *
 * Puede ir en un grupo de comisión ("LIMPIEZA" junta categorías de varios
 * proveedores): lo que se vende de ella paga la comisión de ese grupo.
 *
 * @property int $id
 * @property int $proveedor_id
 * @property string $nombre
 * @property float $margen
 * @property int|null $comision_grupo_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proveedor $proveedor
 * @property-read ComisionGrupo|null $comisionGrupo
 */
class Categoria extends Model
{
    use GuardaEnMayusculas;

    /** @use HasFactory<CategoriaFactory> */
    use HasFactory;

    protected $table = 'categorias';

    /** @var list<string> */
    protected $fillable = [
        'proveedor_id',
        'nombre',
        'margen',
        'comision_grupo_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'margen' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Proveedor, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /**
     * @return BelongsTo<ComisionGrupo, $this>
     */
    public function comisionGrupo(): BelongsTo
    {
        return $this->belongsTo(ComisionGrupo::class);
    }

    /**
     * "MERHONSA · DETERGENTES": la categoría con su proveedor (hay nombres repetidos entre proveedores).
     */
    public function etiqueta(): string
    {
        return $this->proveedor->etiqueta().' · '.$this->nombre;
    }

    /**
     * @return HasMany<Producto, $this>
     */
    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class);
    }

    /**
     * @return list<string>
     */
    protected function camposEnMayusculas(): array
    {
        return ['nombre'];
    }
}
