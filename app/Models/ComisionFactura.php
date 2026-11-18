<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Qué se pagó de cada factura al cerrar un periodo, por grupo (sin grupo:
 * lo que no paga comisión). tipo "venta" = entró en ese cierre; "anulada"
 * = se descontó (neta negativa) porque la anularon después de pagarla.
 *
 * @property int $id
 * @property int $comision_periodo_id
 * @property int $factura_id
 * @property int $vendedor_id
 * @property int|null $comision_grupo_id
 * @property string $tipo
 * @property float $neta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Factura $factura
 */
class ComisionFactura extends Model
{
    public const VENTA = 'venta';

    public const ANULADA = 'anulada';

    protected $table = 'comision_facturas';

    /** @var list<string> */
    protected $fillable = [
        'comision_periodo_id',
        'factura_id',
        'vendedor_id',
        'comision_grupo_id',
        'tipo',
        'neta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'neta' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Factura, $this>
     */
    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }
}
