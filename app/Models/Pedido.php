<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPedido;
use App\Models\Concerns\GuardaEnMayusculas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Pedido de preventa levantado por un vendedor en ruta.
 *
 * Mientras está Pendiente los precios son informativos (los del día en que
 * se levantó). Al validar el despacho, bodega confirma qué sale y el pedido
 * se recalcula con el precio VIGENTE en ese momento.
 *
 * subtotal = bruto (sin ISV), descuento = promociones, ISV sobre lo neto,
 * total = subtotal − descuento + ISV.
 *
 * Fuera de ruta: el vendedor le vendió a un cliente que no es de sus rutas;
 * se permite, pero queda marcado con el motivo.
 *
 * @property int $id
 * @property string|null $numero
 * @property int $bodega_id
 * @property int $cliente_id
 * @property int $vendedor_id
 * @property EstadoPedido $estado
 * @property Carbon $fecha
 * @property string|null $notas
 * @property bool $fuera_de_ruta
 * @property string|null $motivo_fuera_ruta
 * @property float $subtotal
 * @property float $descuento
 * @property float $isv
 * @property float $total
 * @property int|null $validado_por
 * @property Carbon|null $validado_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Bodega $bodega
 * @property-read Cliente $cliente
 * @property-read User $vendedor
 * @property-read User|null $validador
 */
class Pedido extends Model
{
    use GuardaEnMayusculas;

    protected $table = 'pedidos';

    /** @var list<string> */
    protected $fillable = [
        'numero',
        'bodega_id',
        'cliente_id',
        'vendedor_id',
        'estado',
        'fecha',
        'notas',
        'fuera_de_ruta',
        'motivo_fuera_ruta',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'estado'        => 'pendiente',
        'fuera_de_ruta' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado'        => EstadoPedido::class,
            'fecha'         => 'date',
            'fuera_de_ruta' => 'boolean',
            'subtotal'      => 'float',
            'descuento'     => 'float',
            'isv'           => 'float',
            'total'         => 'float',
            'validado_at'   => 'datetime',
        ];
    }

    /**
     * Número legible por bodega: COP-000123.
     */
    protected static function booted(): void
    {
        static::created(function (Pedido $pedido): void {
            if ($pedido->numero !== null) {
                return;
            }

            $prefijo = (string) (Bodega::query()->whereKey($pedido->bodega_id)->value('codigo') ?? 'PED');
            $pedido->numero = $prefijo.'-'.str_pad((string) $pedido->id, 6, '0', STR_PAD_LEFT);
            $pedido->saveQuietly();
        });
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
    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function validador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validado_por');
    }

    /**
     * @return HasMany<PedidoItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PedidoItem::class);
    }

    public function estaPendiente(): bool
    {
        return $this->estado === EstadoPedido::Pendiente;
    }

    /**
     * Quién ve qué:
     *  - Administración: todos los pedidos.
     *  - Vendedor: solo los que él levantó.
     *  - Bodega: los de su bodega.
     *
     * @param Builder<Pedido> $query
     *
     * @return Builder<Pedido>
     */
    public function scopeVisiblePara(Builder $query, User $user): Builder
    {
        if ($user->veTodasLasBodegas()) {
            return $query;
        }

        if ($user->esVendedor()) {
            return $query->where('vendedor_id', $user->id);
        }

        return $query->where('bodega_id', $user->bodega_id);
    }

    public function esVisiblePara(User $user): bool
    {
        if ($user->veTodasLasBodegas()) {
            return true;
        }

        if ($user->esVendedor()) {
            return $this->vendedor_id === $user->id;
        }

        return $user->bodega_id !== null && $this->bodega_id === $user->bodega_id;
    }

    /**
     * @return list<string>
     */
    protected function camposEnMayusculas(): array
    {
        return ['notas', 'motivo_fuera_ruta'];
    }
}
