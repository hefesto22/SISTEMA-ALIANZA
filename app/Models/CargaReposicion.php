<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EntregaFactura;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un cambio por entregar que va en una carga y cómo terminó: en ruta,
 * entregado o que sale en otra carga (no se entregó: regresó a la bodega).
 *
 * @property int $id
 * @property int $carga_id
 * @property int $reposicion_id
 * @property EntregaFactura $estado
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Carga $carga
 * @property-read Reposicion $reposicion
 */
class CargaReposicion extends Model
{
    protected $table = 'carga_reposiciones';

    /** @var list<string> */
    protected $fillable = [
        'carga_id',
        'reposicion_id',
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
            'carga_id'      => 'integer',
            'reposicion_id' => 'integer',
            'estado'        => EntregaFactura::class,
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
     * @return BelongsTo<Reposicion, $this>
     */
    public function reposicion(): BelongsTo
    {
        return $this->belongsTo(Reposicion::class);
    }
}
