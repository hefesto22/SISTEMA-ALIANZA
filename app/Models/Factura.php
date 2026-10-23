<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoFactura;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Factura de un pedido, con número del CAI de su bodega.
 *
 * Se emite la primera vez que bodega imprime el pedido; las copias usan el
 * mismo número. Si el pedido se corrige después de impreso, esta factura
 * queda Anulada (hay que retirar la hoja y marcarla ANULADA) y al imprimir
 * de nuevo se emite otra que la sustituye (reemplaza_a_id).
 *
 * Los datos del CAI, del cliente y los montos son una copia del momento en
 * que se emitió: el historial no cambia aunque después cambie el pedido.
 *
 * @property int $id
 * @property int $pedido_id
 * @property int $bodega_id
 * @property int $cai_id
 * @property string $numero
 * @property int $correlativo
 * @property string $cai
 * @property string $rango_desde
 * @property string $rango_hasta
 * @property Carbon $fecha_limite
 * @property Carbon $fecha_emision
 * @property string $cliente_nombre
 * @property string|null $cliente_rtn
 * @property float $subtotal
 * @property float $descuento
 * @property float $importe_exento
 * @property float $importe_gravado
 * @property float $isv
 * @property float $total
 * @property int $impresiones
 * @property EstadoFactura $estado
 * @property int|null $reemplaza_a_id
 * @property int|null $emitida_por
 * @property Carbon|null $anulada_at
 * @property int|null $anulada_por
 * @property string|null $motivo_anulacion
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Pedido $pedido
 * @property-read Bodega $bodega
 * @property-read Cai $autorizacion
 * @property-read Factura|null $reemplazaA
 * @property-read Factura|null $reemplazo
 * @property-read User|null $emisor
 * @property-read User|null $anulador
 */
class Factura extends Model
{
    protected $table = 'facturas';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'estado'      => 'vigente',
        'impresiones' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'correlativo'     => 'integer',
            'fecha_limite'    => 'date',
            'fecha_emision'   => 'datetime',
            'subtotal'        => 'float',
            'descuento'       => 'float',
            'importe_exento'  => 'float',
            'importe_gravado' => 'float',
            'isv'             => 'float',
            'total'           => 'float',
            'impresiones'     => 'integer',
            'estado'          => EstadoFactura::class,
            'anulada_at'      => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Pedido, $this>
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    /**
     * @return BelongsTo<Bodega, $this>
     */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    /**
     * El CAI con el que se emitió.
     *
     * @return BelongsTo<Cai, $this>
     */
    public function autorizacion(): BelongsTo
    {
        return $this->belongsTo(Cai::class, 'cai_id');
    }

    /**
     * La factura anulada que esta sustituye.
     *
     * @return BelongsTo<Factura, $this>
     */
    public function reemplazaA(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reemplaza_a_id');
    }

    /**
     * La factura que se emitió en lugar de esta (si se anuló).
     *
     * @return HasOne<Factura, $this>
     */
    public function reemplazo(): HasOne
    {
        return $this->hasOne(self::class, 'reemplaza_a_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function emisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emitida_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function anulador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por');
    }

    /**
     * Las facturas de los pedidos que esta persona puede ver.
     *
     * @param Builder<Factura> $query
     *
     * @return Builder<Factura>
     */
    public function scopeVisiblesPara(Builder $query, User $user): Builder
    {
        return $query->whereIn('pedido_id', Pedido::query()->visiblePara($user)->select('id'));
    }

    public function estaAnulada(): bool
    {
        return $this->estado === EstadoFactura::Anulada;
    }

    /**
     * Rango autorizado como va impreso: "000-001-01-00000001 al 000-001-01-00005000".
     */
    public function rangoAutorizado(): string
    {
        return "{$this->rango_desde} al {$this->rango_hasta}";
    }
}
