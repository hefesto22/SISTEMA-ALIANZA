<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Precios\CalculadoraPrecio;
use App\Models\Concerns\GuardaEnMayusculas;
use Database\Factories\ProductoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Producto de un proveedor con su costo vigente y sus presentaciones.
 *
 * La existencia y el costo van en unidades BASE (casi siempre UN) y el
 * costo SIN ISV. Se vende en presentaciones (App\Models\Presentacion): la
 * base y las que traen varias (CJ de 8, CJ de 12, FD de 6…), cada una con
 * su precio. El costo y los precios son los mismos en todas las bodegas.
 *
 * Para cambiar costo/precios usar App\Domain\Precios\ActualizarPrecioProducto:
 * es lo que deja el registro en el historial.
 *
 * El costo es el promedio ponderado de lo comprado (Configuración). El
 * precio de cada presentación sale de ese costo con el % de utilidad, salvo
 * que sea precio fijo: ahí lo pone la empresa a mano y las compras o los
 * cambios de margen no lo tocan.
 *
 * @property int $id
 * @property int $proveedor_id
 * @property int $categoria_id
 * @property string $codigo
 * @property string $descripcion
 * @property int $unidad_medida_id
 * @property bool $gravado
 * @property float|null $margen
 * @property float $costo_unidad
 * @property Carbon|null $precio_actualizado_at
 * @property int|null $precio_actualizado_por
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proveedor $proveedor
 * @property-read Categoria $categoria
 * @property-read UnidadMedida $unidadMedida
 * @property-read Collection<int, Presentacion> $presentaciones
 */
class Producto extends Model
{
    use GuardaEnMayusculas;

    /** @use HasFactory<ProductoFactory> */
    use HasFactory;

    protected $table = 'productos';

    /** @var list<string> */
    protected $fillable = [
        'proveedor_id',
        'categoria_id',
        'codigo',
        'descripcion',
        'unidad_medida_id',
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
            'gravado'               => 'boolean',
            'unidad_medida_id'      => 'integer',
            'margen'                => 'float',
            'costo_unidad'          => 'float',
            'precio_actualizado_at' => 'datetime',
            'activo'                => 'boolean',
        ];
    }

    /**
     * Sin medida escogida, la base es UN. Todo producto nace con su
     * presentación base (trae 1, sin precio todavía).
     */
    protected static function booted(): void
    {
        static::creating(function (Producto $producto): void {
            if (empty($producto->unidad_medida_id)) {
                $producto->unidad_medida_id = UnidadMedida::base()->id;
            }
        });

        static::created(function (Producto $producto): void {
            Presentacion::query()->create([
                'producto_id'      => $producto->id,
                'unidad_medida_id' => $producto->unidad_medida_id,
                'contenido'        => 1,
                'es_base'          => true,
            ]);
        });
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
     * En qué se cuenta la existencia (la medida de la presentación base).
     *
     * @return BelongsTo<UnidadMedida, $this>
     */
    public function unidadMedida(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class);
    }

    /**
     * La base primero y después de la más chica a la más grande.
     *
     * @return HasMany<Presentacion, $this>
     */
    public function presentaciones(): HasMany
    {
        return $this->hasMany(Presentacion::class)->enOrden();
    }

    /**
     * @return HasMany<CambioPrecio, $this>
     */
    public function cambiosPrecio(): HasMany
    {
        return $this->hasMany(CambioPrecio::class);
    }

    /**
     * Cuánto hay en cada bodega (en unidades).
     *
     * @return HasMany<Existencia, $this>
     */
    public function existencias(): HasMany
    {
        return $this->hasMany(Existencia::class);
    }

    /**
     * Kárdex: cada entrada y salida en cada bodega.
     *
     * @return HasMany<MovimientoInventario, $this>
     */
    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class);
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

    public function costoUnidadConIsv(): float
    {
        return CalculadoraPrecio::conIsv($this->costo_unidad, $this->gravado);
    }

    /**
     * La presentación base (UN, trae 1): en ella se cuenta la existencia.
     */
    public function presentacionBase(): Presentacion
    {
        $base = $this->presentaciones->first(fn (Presentacion $presentacion): bool => $presentacion->es_base);

        if (! $base instanceof Presentacion) {
            throw new LogicException("El producto {$this->codigo} no tiene presentación base.");
        }

        return $base;
    }

    /**
     * Las que se venden (y se compran), la base primero.
     *
     * @return Collection<int, Presentacion>
     */
    public function presentacionesActivas(): Collection
    {
        return $this->presentaciones->filter(fn (Presentacion $presentacion): bool => $presentacion->activo)->values();
    }

    /**
     * La que más trae de las activas (la "caja" para contar la existencia);
     * null si solo tiene la base.
     */
    public function presentacionMayor(): ?Presentacion
    {
        $mayor = $this->presentacionesActivas()
            ->filter(fn (Presentacion $presentacion): bool => $presentacion->contenido > 1)
            ->sortByDesc('contenido')
            ->first();

        return $mayor instanceof Presentacion ? $mayor : null;
    }

    /**
     * Unidades base como se cuentan en bodega, con la presentación más
     * grande: "2 CJ12 + 5 UN", "3 SC10", "5 UN", "0".
     */
    public function existenciaEnTexto(int $unidades): string
    {
        $mayor = $this->presentacionMayor();
        $base = $this->presentacionBase()->sigla();

        return $mayor === null
            ? Existencia::enCajas($unidades, 1, base: $base)
            : Existencia::enCajas($unidades, $mayor->contenido, $mayor->sigla(), $base);
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

    /**
     * @return list<string>
     */
    protected function camposEnMayusculas(): array
    {
        return ['descripcion'];
    }
}
