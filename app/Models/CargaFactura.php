<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EntregaFactura;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una factura de una carga y cómo terminó: en ruta, entregada, anulada o
 * que sale en otra carga. Una factura va en ruta (o queda entregada) en una
 * sola carga a la vez.
 *
 * @property int $id
 * @property int $carga_id
 * @property int $factura_id
 * @property EntregaFactura $estado
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Carga $carga
 * @property-read Factura $factura
 */
class CargaFactura extends Model
{
    protected $table = 'carga_factura';

    /** @var list<string> */
    protected $fillable = [
        'carga_id',
        'factura_id',
        'estado',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'estado' => 'en_ruta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'carga_id'   => 'integer',
            'factura_id' => 'integer',
            'estado'     => EntregaFactura::class,
        ];
    }

    /**
     * @return BelongsTo<Carga, $this>
     */
    public function carga(): BelongsTo
    {
        return $this->belongsTo(Carga::class);
    }

    /**
     * @return BelongsTo<Factura, $this>
     */
    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }
}
