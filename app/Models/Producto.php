<?php

declare(strict_types=1);

namespace App\Models;

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
 * Producto de un proveedor con sus presentaciones.
 *
 * Se vende y se compra en presentaciones (App\Models\Presentacion): la base
 * (casi siempre UN) y las que traen varias (CJ de 32, CJ de 48, FD de 6…).
 * CADA presentación lleva su existencia en cada bodega y su costo promedio
 * (sin ISV): la CJ48 no se mezcla con la CJ32 ni con las UN sueltas. Al
 * abrir una caja, sus unidades pasan a las sueltas con el costo de esa
 * caja. Costo y precios son los mismos en todas las bodegas.
 *
 * Para cambiar costos/precios usar App\Domain\Precios\ActualizarPrecioProducto:
 * es lo que deja el registro en el historial.
 *
 * El precio de cada presentación sale de su costo con el % de utilidad,
 * salvo que sea precio fijo: ahí lo pone la empresa a mano y las compras o
 * los cambios de margen no lo tocan.
 *
 * @property int $id
 * @property int $proveedor_id
 * @property int $categoria_id
 * @property string $codigo
 * @property string $descripcion
 * @property int $unidad_medida_id
 * @property bool $gravado
 * @property float|null $margen
 * @property Carbon|null $precio_actualizado_at
 * @property int|null $precio_actualizado_por
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proveedor $proveedor
 * @property-read Categoria $categoria
 * @property-read UnidadMedida $unidadMedida
 * @property-read Collection<int, Presentacion> $presentaciones
 * @property-read Collection<int, Existencia> $existencias
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
     * Cuánto hay en cada bodega, por presentación.
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

    /**
     * Costo por unidad base (sin ISV) para estimar el de una presentación que
     * todavía no tiene: el de la base si tiene; si no, el más alto por unidad
     * de las otras (para no quedar por debajo del costo). 0 si ninguna tiene.
     */
    public function costoReferencia(): float
    {
        $base = $this->presentaciones->first(fn (Presentacion $presentacion): bool => $presentacion->es_base);

        if ($base instanceof Presentacion && $base->costo > 0) {
            return round($base->costo, 4);
        }

        $porUnidad = $this->presentaciones
            ->filter(fn (Presentacion $presentacion): bool => $presentacion->costo > 0)
            ->map(fn (Presentacion $presentacion): float => $presentacion->costoPorUnidad());

        return $porUnidad->isEmpty() ? 0.0 : (float) $porUnidad->max();
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
     * Unidades base en la presentación más grande: "2 CJ12 + 5 UN", "3
     * SC10", "5 UN", "0". Solo para totales en unidades (lo que hay de verdad
     * en cada presentación: existenciaPorPresentacion()).
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
     * Lo que hay en cada presentación, la más grande primero: "2 CJ48 · 3
     * CJ32 · 5 UN"; "0" si no hay nada. Las que están en 0 no salen.
     *
     * @param array<int, int> $cantidades [presentacion_id => cantidad en esa presentación]
     */
    public function existenciaPorPresentacion(array $cantidades): string
    {
        $partes = [];

        foreach ($this->presentaciones->sortByDesc('contenido') as $presentacion) {
            $cantidad = $cantidades[$presentacion->id] ?? 0;

            if ($cantidad !== 0) {
                $partes[] = $cantidad.' '.$presentacion->sigla();
            }
        }

        return $partes === [] ? '0' : implode(' · ', $partes);
    }

    /**
     * Lo que hay de cada presentación en la bodega (o en todas):
     * [presentacion_id => cantidad en esa presentación].
     *
     * @return array<int, int>
     */
    public function existenciaEn(?int $bodegaId): array
    {
        return Existencia::porPresentacion($bodegaId, $this->id);
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
