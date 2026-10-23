<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Precios\CalculadoraPrecio;
use App\Enums\UnidadVenta;
use Database\Factories\ProductoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Producto de un proveedor con su costo y precio vigentes.
 *
 * Costo y precio se guardan POR UNIDAD y SIN ISV. Todo lo demás
 * (precio con ISV, precio de la caja) se calcula al vuelo para que
 * nunca quede desfasado. El precio es el mismo en todas las bodegas.
 *
 * Para cambiar costo/precio usar App\Domain\Precios\ActualizarPrecioProducto:
 * es lo que deja el registro en el historial.
 *
 * @property int $id
 * @property int $proveedor_id
 * @property int $categoria_id
 * @property string $codigo
 * @property string $descripcion
 * @property int $unidades_por_caja
 * @property bool $gravado
 * @property float|null $margen
 * @property float $costo_unidad
 * @property float $precio_unidad
 * @property Carbon|null $precio_actualizado_at
 * @property int|null $precio_actualizado_por
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proveedor $proveedor
 * @property-read Categoria $categoria
 */
class Producto extends Model
{
    /** @use HasFactory<ProductoFactory> */
    use HasFactory;

    protected $table = 'productos';

    /** @var list<string> */
    protected $fillable = [
        'proveedor_id',
        'categoria_id',
        'codigo',
        'descripcion',
        'unidades_por_caja',
        'gravado',
        'margen',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unidades_por_caja'     => 'integer',
            'gravado'               => 'boolean',
            'margen'                => 'float',
            'costo_unidad'          => 'float',
            'precio_unidad'         => 'float',
            'precio_actualizado_at' => 'datetime',
            'activo'                => 'boolean',
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
     * @return BelongsTo<Categoria, $this>
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    /**
     * @return HasMany<CambioPrecio, $this>
     */
    public function cambiosPrecio(): HasMany
    {
        return $this->hasMany(CambioPrecio::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'precio_actualizado_por');
    }

    /**
     * Margen que se usa al calcular: el propio del producto o el de su categoría.
     */
    public function margenAplicado(): float
    {
        return $this->margen ?? $this->categoria->margen;
    }

    public function precioUnidadConIsv(): float
    {
        return CalculadoraPrecio::conIsv($this->precio_unidad, $this->gravado);
    }

    public function precioCaja(): float
    {
        return round($this->precio_unidad * $this->unidades_por_caja, 4);
    }

    public function precioCajaConIsv(): float
    {
        return CalculadoraPrecio::conIsv($this->precioCaja(), $this->gravado);
    }

    /**
     * Precio sin ISV según se venda suelto o por caja.
     */
    public function precioPara(UnidadVenta $unidad): float
    {
        return match ($unidad) {
            UnidadVenta::Unidad => $this->precio_unidad,
            UnidadVenta::Caja   => $this->precioCaja(),
        };
    }

    /**
     * Texto corto para selects y reportes: "40494 · ACEITE EL DORADO 20X750ML".
     */
    public function etiqueta(): string
    {
        return "{$this->codigo} · {$this->descripcion}";
    }

    /**
     * @param Builder<Producto> $query
     *
     * @return Builder<Producto>
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * Busca por código o descripción (sin importar mayúsculas).
     *
     * @param Builder<Producto> $query
     *
     * @return Builder<Producto>
     */
    public function scopeBuscar(Builder $query, string $texto): Builder
    {
        $texto = trim($texto);

        if ($texto === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($texto): void {
            $q->where('codigo', 'ilike', "%{$texto}%")
                ->orWhere('descripcion', 'ilike', "%{$texto}%");
        });
    }
}
