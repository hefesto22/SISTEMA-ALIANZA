<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property int $id
 * @property int $proveedor_id
 * @property string $nombre
 * @property float $margen
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proveedor $proveedor
 */
class Categoria extends Model
{
    /** @use HasFactory<CategoriaFactory> */
    use HasFactory;

    protected $table = 'categorias';

    /** @var list<string> */
    protected $fillable = [
        'proveedor_id',
        'nombre',
        'margen',
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
     * @return HasMany<Producto, $this>
     */
    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class);
    }
}
