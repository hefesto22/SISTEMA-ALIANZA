<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoFactura;
use App\Enums\EstadoPedido;
use App\Models\Concerns\GuardaEnMayusculas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Pedido de preventa levantado por un vendedor en ruta.
 *
 * Mientras está Pendiente los precios son informativos (los del día en que
 * se levantó). Al validar, bodega confirma qué sale y el pedido se recalcula
 * con el precio VIGENTE en ese momento; queda Por imprimir. Al imprimirlo
 * pasa a Impreso y se emite su factura (con CAI). Si se corrige después de
 * impreso, la factura se anula y el pedido vuelve a Por imprimir: al
 * reimprimirlo sale una factura nueva que sustituye a la anulada.
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
 * @property int $impresiones
 * @property Carbon|null $impreso_at
 * @property int|null $impreso_por
 * @property Carbon|null $corregido_at
 * @property int|null $corregido_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Bodega $bodega
 * @property-read Cliente $cliente
 * @property-read User $vendedor
 * @property-read User|null $validador
 * @property-read User|null $impresor
 * @property-read User|null $corrector
 * @property-read Factura|null $factura
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
        'impresiones'   => 0,
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
            'impresiones'   => 'integer',
            'impreso_at'    => 'datetime',
            'corregido_at'  => 'datetime',
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
        // Aunque el usuario se haya eliminado, sus pedidos siguen siendo suyos.
        return $this->belongsTo(User::class, 'vendedor_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function validador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validado_por')->withTrashed();
    }

    /**
     * Quién lo imprimió la última vez.
     *
     * @return BelongsTo<User, $this>
     */
    public function impresor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impreso_por')->withTrashed();
    }

    /**
     * Quién lo corrigió después de validado (la última vez).
     *
     * @return BelongsTo<User, $this>
     */
    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corregido_por')->withTrashed();
    }

    /**
     * @return HasMany<PedidoItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PedidoItem::class);
    }

    /**
     * Todas sus facturas, vigentes y anuladas, de la más vieja a la más nueva.
     *
     * @return HasMany<Factura, $this>
     */
    public function facturas(): HasMany
    {
        return $this->hasMany(Factura::class)->orderBy('id');
    }

    /**
     * La factura que vale (a lo más hay una vigente).
     *
     * @return HasOne<Factura, $this>
     */
    public function factura(): HasOne
    {
        return $this->hasOne(Factura::class)->where('estado', EstadoFactura::Vigente);
    }

    /**
     * La factura anulada que todavía no tiene otra que la sustituya: al
     * reimprimir, la nueva dice que reemplaza a esta y que hay que retirarla.
     */
    public function facturaPorSustituir(): ?Factura
    {
        return $this->facturas()
            ->reorder()
            ->where('estado', EstadoFactura::Anulada)
            ->whereDoesntHave('reemplazo')
            ->latest('id')
            ->first();
    }

    public function estaPendiente(): bool
    {
        return $this->estado === EstadoPedido::Pendiente;
    }

    /**
     * Ya pasó por bodega: por imprimir o impreso.
     */
    public function estaValidado(): bool
    {
        return $this->estado->estaValidado();
    }

    /**
     * Ya se había impreso y lo corrigieron: hay que volver a imprimirlo.
     */
    public function esReimpresion(): bool
    {
        return $this->estado === EstadoPedido::PorImprimir && $this->impresiones > 0;
    }

    /**
     * Quién ve qué:
     *  - Super admin y administración: todos los pedidos, de todas las bodegas.
     *  - Vendedor: solo los que él levantó.
     *  - Encargado de bodega: los de su bodega (la del pedido, que es la del
     *    vendedor cuando lo levantó). Si después mueven al vendedor a otra
     *    bodega, su historial no se va con él.
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

        if ($user->bodega_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('pedidos.bodega_id', $user->bodega_id);
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
