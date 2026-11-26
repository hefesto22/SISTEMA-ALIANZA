<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoDevolucion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Devolución de un pedido ya entregado (App\Domain\Devoluciones\RegistrarDevolucion).
 * Lo bueno regresa a la bodega; lo dañado queda marcado (para los reportes
 * de producto dañado). En un cambio se le repone lo mismo al cliente; con
 * crédito se le abona lo que pagó: con nota de crédito (CAI tipo 07) si está
 * encendida en Configuración, o interno si no (la nota se hace a mano).
 *
 * Montos sin ISV salvo `isv` y `total`; los de la nota (`nota_numero`, CAI,
 * rango, fecha límite, cliente) quedan como se imprimieron.
 *
 * @property int $id
 * @property string|null $numero DEV-000001
 * @property int $pedido_id
 * @property int|null $factura_id
 * @property int|null $carga_id la carga en la que iba la factura al registrarla (se descuenta en su liquidación)
 * @property int $bodega_id
 * @property int $cliente_id
 * @property int|null $user_id
 * @property Carbon $fecha
 * @property TipoDevolucion $tipo
 * @property string|null $motivo
 * @property float $subtotal
 * @property float $importe_exento
 * @property float $importe_gravado
 * @property float $isv
 * @property float $total
 * @property string|null $nota_numero
 * @property int|null $cai_id
 * @property int|null $correlativo
 * @property string|null $cai
 * @property string|null $rango_desde
 * @property string|null $rango_hasta
 * @property Carbon|null $fecha_limite
 * @property string|null $cliente_nombre
 * @property string|null $cliente_rtn
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Pedido $pedido
 * @property-read Factura|null $factura
 * @property-read Carga|null $carga
 * @property-read Bodega $bodega
 * @property-read Cliente $cliente
 * @property-read User|null $usuario
 * @property-read Collection<int, DevolucionItem> $items
 */
class Devolucion extends Model
{
    protected $table = 'devoluciones';

    /** @var list<string> */
    protected $fillable = [
        'pedido_id',
        'factura_id',
        'carga_id',
        'bodega_id',
        'cliente_id',
        'user_id',
        'fecha',
        'tipo',
        'motivo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha'           => 'date',
            'tipo'            => TipoDevolucion::class,
            'subtotal'        => 'float',
            'importe_exento'  => 'float',
            'importe_gravado' => 'float',
            'isv'             => 'float',
            'total'           => 'float',
            'correlativo'     => 'integer',
            'fecha_limite'    => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Devolucion $devolucion): void {
            $devolucion->forceFill(['numero' => 'DEV-'.str_pad((string) $devolucion->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();
        });
    }

    /**
     * @return BelongsTo<Pedido, $this>
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    /**
     * @return BelongsTo<Factura, $this>
     */
    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }

    /**
     * La carga en la que iba la factura cuando se registró.
     *
     * @return BelongsTo<Carga, $this>
     */
    public function carga(): BelongsTo
    {
        return $this->belongsTo(Carga::class);
    }

    /**
     * @return BelongsTo<Bodega, $this>
     */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    /**
     * @return BelongsTo<Cliente, $this>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<DevolucionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DevolucionItem::class);
    }

    public function conNota(): bool
    {
        return $this->nota_numero !== null;
    }

    /**
     * Las de los pedidos que esta persona ve: administración todas, bodega
     * las de su bodega, el vendedor las de sus pedidos.
     *
     * @param Builder<Devolucion> $query
     *
     * @return Builder<Devolucion>
     */
    public function scopeVisiblesPara(Builder $query, User $user): Builder
    {
        if ($user->veTodasLasBodegas()) {
            return $query;
        }

        if (! $user->esVendedor() && $user->bodega_id !== null) {
            return $query->where('devoluciones.bodega_id', $user->bodega_id);
        }

        return $query->whereIn('pedido_id', Pedido::query()->visiblePara($user)->select('id'));
    }
}
