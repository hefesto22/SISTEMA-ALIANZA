<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Precios\CalculadoraPrecio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Una forma de vender un producto (mismo código): la base (UN, trae 1) o un
 * empaque que trae varias (CJ de 8, CJ de 12, FD de 6, SC de 10…).
 *
 * Cada presentación lleva su existencia en cada bodega (en ella: cuántas
 * CJ48, cuántas UN sueltas) y su costo: el promedio de lo que se ha
 * comprado de ELLA, de la presentación completa y sin ISV. Las UN sueltas
 * también entran cuando se abre una caja (con el costo de esa caja). El
 * precio es de la presentación completa y SIN ISV: calculado desde su
 * costo con el % de utilidad (el suyo o, si no tiene, el del producto o su
 * categoría; se recalcula cuando cambia su costo) o fijo (lo pone la
 * empresa a mano). Costo y precio se cambian por
 * App\Domain\Precios\ActualizarPrecioProducto, que deja el historial.
 *
 * Lo que trae no se cambia cuando ya se usó en pedidos o compras: se
 * desactiva y se crea otra. Una desactivada ya no se vende ni se compra.
 *
 * @property int $id
 * @property int $producto_id
 * @property int $unidad_medida_id
 * @property int $contenido
 * @property float $precio
 * @property float $costo costo promedio de la presentación completa, sin ISV
 * @property float|null $margen % de utilidad propio (0.10 = 10 %); null = el del producto
 * @property bool $precio_fijo
 * @property bool $es_base
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Producto $producto
 * @property-read UnidadMedida $unidadMedida
 * @property-read Collection<int, Existencia> $existencias
 */
class Presentacion extends Model
{
    protected $table = 'presentaciones';

    /** @var list<string> */
    protected $fillable = [
        'producto_id',
        'unidad_medida_id',
        'contenido',
        'precio',
        'costo',
        'margen',
        'precio_fijo',
        'es_base',
        'activo',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'precio'      => 0,
        'costo'       => 0,
        'precio_fijo' => false,
        'es_base'     => false,
        'activo'      => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'producto_id'      => 'integer',
            'unidad_medida_id' => 'integer',
            'contenido'        => 'integer',
            'precio'           => 'float',
            'costo'            => 'float',
            'margen'           => 'float',
            'precio_fijo'      => 'boolean',
            'es_base'          => 'boolean',
            'activo'           => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Producto, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * @return BelongsTo<UnidadMedida, $this>
     */
    public function unidadMedida(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class);
    }

    /**
     * Cuánto hay de ella en cada bodega (en esta presentación).
     *
     * @return HasMany<Existencia, $this>
     */
    public function existencias(): HasMany
    {
        return $this->hasMany(Existencia::class);
    }

    /**
     * Corto, para cantidades y listas: "UN", "CJ12", "FD6", "BT".
     */
    public function sigla(): string
    {
        $codigo = $this->unidadMedida->codigo;

        return $this->contenido > 1 ? $codigo.$this->contenido : $codigo;
    }

    /**
     * Para mostrar: "Unidad", "Caja de 12", "Fardo de 6".
     */
    public function nombre(): string
    {
        return mb_ucfirst($this->palabra(1));
    }

    /**
     * "SIGLA · Nombre" para selects: "CJ12 · Caja de 12".
     */
    public function etiqueta(): string
    {
        return $this->sigla().' · '.$this->nombre();
    }

    /**
     * Sin el número: "unidad"/"unidades", "caja de 12"/"cajas de 12".
     */
    public function palabra(int $cantidad): string
    {
        $medida = mb_strtolower($cantidad === 1 ? $this->unidadMedida->nombre : $this->unidadMedida->plural);

        return $this->contenido > 1 ? "{$medida} de {$this->contenido}" : $medida;
    }

    /**
     * "1 unidad", "5 unidades", "1 caja de 12", "3 cajas de 12".
     */
    public function enPalabras(int $cantidad): string
    {
        return $cantidad.' '.$this->palabra($cantidad);
    }

    public function costoConIsv(): float
    {
        return CalculadoraPrecio::conIsv($this->costo, $this->producto->gravado);
    }

    /**
     * Lo que le sale cada unidad base de esta presentación (sin ISV): 312 ÷ 48.
     */
    public function costoPorUnidad(): float
    {
        return $this->contenido > 0 ? round($this->costo / $this->contenido, 4) : 0.0;
    }

    public function precioConIsv(): float
    {
        return CalculadoraPrecio::conIsv($this->precio, $this->producto->gravado);
    }

    /**
     * El % de utilidad con que se calcula: el suyo o, si no tiene, el del producto (o su categoría).
     */
    public function margenAplicado(): float
    {
        return $this->margen ?? $this->producto->margenAplicado();
    }

    /**
     * Precio que da su % de utilidad con su costo (o con el que se mande), sin ISV.
     */
    public function precioCalculado(?float $costo = null): float
    {
        return CalculadoraPrecio::precioVenta(round($costo ?? $this->costo, 4), $this->margenAplicado());
    }

    /**
     * Ya se usó en pedidos, compras, promociones o el kárdex: lo que trae no se cambia.
     */
    public function enUso(): bool
    {
        return PedidoItem::query()->where('presentacion_id', $this->id)->exists()
            || MovimientoInventario::query()->where('presentacion_id', $this->id)->exists()
            || CompraItem::query()->where('presentacion_id', $this->id)->exists()
            || PromocionCondicion::query()->where('presentacion_id', $this->id)->exists()
            || Promocion::query()->where('beneficio_presentacion_id', $this->id)->exists();
    }

    /**
     * La base primero y después de la más chica a la más grande.
     *
     * @param Builder<Presentacion> $query
     *
     * @return Builder<Presentacion>
     */
    public function scopeEnOrden(Builder $query): Builder
    {
        return $query->orderByDesc('es_base')->orderBy('contenido')->orderBy('id');
    }

    /**
     * @param Builder<Presentacion> $query
     *
     * @return Builder<Presentacion>
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activo', true);
    }
}
