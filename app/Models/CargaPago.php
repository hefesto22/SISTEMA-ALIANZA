<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un cheque o una transferencia que trajo el camión: de qué factura (si se
 * sabe), el banco, el número o la referencia y el monto.
 *
 * @property int $id
 * @property int $carga_id
 * @property string $tipo cheque | transferencia
 * @property int|null $factura_id
 * @property string|null $banco
 * @property string|null $referencia
 * @property float $monto
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Carga $carga
 * @property-read Factura|null $factura
 */
class CargaPago extends Model
{
    public const CHEQUE = 'cheque';

    public const TRANSFERENCIA = 'transferencia';

    protected $table = 'carga_pagos';

    /** @var list<string> */
    protected $fillable = [
        'carga_id',
        'tipo',
        'factura_id',
        'banco',
        'referencia',
        'monto',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'carga_id'   => 'integer',
            'factura_id' => 'integer',
            'monto'      => 'float',
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
