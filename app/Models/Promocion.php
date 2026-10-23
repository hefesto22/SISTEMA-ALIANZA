<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UnidadVenta;
use Carbon\CarbonInterface;
use Database\Factories\PromocionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Promoción "si compra X, Y sale gratis / a mitad / con N% de descuento".
 *
 * Ejemplo: por cada 1 unidad de BUTTER TOFFEE, 1 unidad de BABY NUTRINE a
 * mitad de precio. Si condición y beneficio son el mismo producto queda
 * como "compre 2 y lleve 1 más gratis".
 *
 * El código (PROMO-0001) se genera solo. Solo cuenta en los pedidos
 * mientras esté activa y dentro de sus fechas; el cálculo vive en
 * App\Domain\Promociones\MotorPromociones.
 *
 * @property int $id
 * @property string|null $codigo
 * @property string $nombre
 * @property string|null $descripcion
 * @property int $producto_condicion_id
 * @property int $cantidad_condicion
 * @property UnidadVenta $condicion_unidad
 * @property int $producto_beneficio_id
 * @property int $cantidad_beneficio
 * @property UnidadVenta $beneficio_unidad
 * @property float $descuento_porcentaje
 * @property bool $activa
 * @property Carbon|null $vigente_desde
 * @property Carbon|null $vigente_hasta
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Producto $productoCondicion
 * @property-read Producto $productoBeneficio
 * @property-read User|null $creador
 */
class Promocion extends Model
{
    /** @use HasFactory<PromocionFactory> */
    use HasFactory;

    protected $table = 'promociones';

    /** @var list<string> */
    protected $fillable = [
        'nombre',
        'descripcion',
        'producto_condicion_id',
        'cantidad_condicion',
        'condicion_unidad',
        'producto_beneficio_id',
        'cantidad_beneficio',
        'beneficio_unidad',
        'descuento_porcentaje',
        'activa',
        'vigente_desde',
        'vigente_hasta',
        'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'cantidad_condicion'   => 1,
        'condicion_unidad'     => 'unidad',
        'cantidad_beneficio'   => 1,
        'beneficio_unidad'     => 'unidad',
        'descuento_porcentaje' => 100,
        'activa'               => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cantidad_condicion'   => 'integer',
            'condicion_unidad'     => UnidadVenta::class,
            'cantidad_beneficio'   => 'integer',
            'beneficio_unidad'     => UnidadVenta::class,
            'descuento_porcentaje' => 'float',
            'activa'               => 'boolean',
            'vigente_desde'        => 'date',
            'vigente_hasta'        => 'date',
        ];
    }

    /**
     * Código propio de la promoción: PROMO-0001.
     */
    protected static function booted(): void
    {
        static::created(function (Promocion $promocion): void {
            if ($promocion->codigo !== null) {
                return;
            }

            $promocion->codigo = 'PROMO-'.str_pad((string) $promocion->id, 4, '0', STR_PAD_LEFT);
            $promocion->saveQuietly();
        });
    }

    /**
     * @return BelongsTo<Producto, $this>
     */
    public function productoCondicion(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_condicion_id');
    }

    /**
     * @return BelongsTo<Producto, $this>
     */
    public function productoBeneficio(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_beneficio_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Líneas de pedido donde se aplicó.
     *
     * @return HasMany<PedidoItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PedidoItem::class);
    }

    /**
     * Activas y dentro de sus fechas (sin fecha = sin límite).
     *
     * @param Builder<Promocion> $query
     *
     * @return Builder<Promocion>
     */
    public function scopeVigentes(Builder $query, ?CarbonInterface $fecha = null): Builder
    {
        $dia = ($fecha ?? today())->toDateString();

        return $query
            ->where('activa', true)
            ->where(function (Builder $q) use ($dia): void {
                $q->whereNull('vigente_desde')->orWhere('vigente_desde', '<=', $dia);
            })
            ->where(function (Builder $q) use ($dia): void {
                $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $dia);
            });
    }

    public function estaVigente(?CarbonInterface $fecha = null): bool
    {
        return $this->estado($fecha) === 'Vigente';
    }

    /**
     * Vigente, Programada (empieza después), Vencida o Inactiva.
     */
    public function estado(?CarbonInterface $fecha = null): string
    {
        $dia = ($fecha ?? today())->toDateString();

        return match (true) {
            ! $this->activa                                                              => 'Inactiva',
            $this->vigente_desde !== null && $this->vigente_desde->toDateString() > $dia => 'Programada',
            $this->vigente_hasta !== null && $this->vigente_hasta->toDateString() < $dia => 'Vencida',
            default                                                                      => 'Vigente',
        };
    }

    public function colorEstado(): string
    {
        return match ($this->estado()) {
            'Vigente'    => 'success',
            'Programada' => 'info',
            'Vencida'    => 'danger',
            default      => 'gray',
        };
    }

    /**
     * gratis (100%), mitad (50%) u otro porcentaje.
     */
    public function tipo(): string
    {
        return self::tipoPara($this->descuento_porcentaje);
    }

    public static function tipoPara(float $porcentaje): string
    {
        return match (true) {
            abs($porcentaje - 100) < 0.001 => 'gratis',
            abs($porcentaje - 50) < 0.001  => 'mitad',
            default                        => 'otro',
        };
    }

    public function esMismoProducto(): bool
    {
        return $this->producto_condicion_id === $this->producto_beneficio_id;
    }

    /**
     * "gratis", "a mitad de precio" o "con 30% de descuento".
     */
    public function beneficioTexto(): string
    {
        return match ($this->tipo()) {
            'gratis' => 'gratis',
            'mitad'  => 'a mitad de precio',
            default  => 'con '.self::porcentajeTexto($this->descuento_porcentaje).' de descuento',
        };
    }

    /**
     * Lo que se le explica al cliente, por ejemplo:
     * "Compra 1 unidad de BUTTER TOFFEE → 1 unidad de BABY NUTRINE a mitad de precio".
     */
    public function resumen(): string
    {
        $condicion = self::cantidadTexto($this->cantidad_condicion, $this->condicion_unidad);
        $beneficio = self::cantidadTexto($this->cantidad_beneficio, $this->beneficio_unidad);

        if ($this->esMismoProducto()) {
            return "Compra {$condicion} de {$this->productoCondicion->descripcion} y lleva {$beneficio} más {$this->beneficioTexto()}";
        }

        return "Compra {$condicion} de {$this->productoCondicion->descripcion} → {$beneficio} de {$this->productoBeneficio->descripcion} {$this->beneficioTexto()}";
    }

    /**
     * "1 unidad", "3 unidades", "1 caja", "2 cajas".
     */
    public static function cantidadTexto(int $cantidad, UnidadVenta $unidad): string
    {
        $palabra = match ($unidad) {
            UnidadVenta::Unidad => $cantidad === 1 ? 'unidad' : 'unidades',
            UnidadVenta::Caja   => $cantidad === 1 ? 'caja' : 'cajas',
        };

        return "{$cantidad} {$palabra}";
    }

    public static function porcentajeTexto(float $porcentaje): string
    {
        return rtrim(rtrim(number_format($porcentaje, 2), '0'), '.').'%';
    }
}
