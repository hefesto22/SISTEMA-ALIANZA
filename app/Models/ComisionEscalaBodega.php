<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Comisiones\Escala;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Los montos de un grupo de comisión en una bodega (en una se vende menos
 * que en otra). Sin esto, la bodega usa la escala del grupo. La meta propia
 * de un vendedor (ComisionMeta) manda sobre esta.
 *
 * @property int $id
 * @property int $comision_grupo_id
 * @property int $bodega_id
 * @property list<array{desde: float, porcentaje: float}> $escalones
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ComisionGrupo $grupo
 * @property-read Bodega $bodega
 */
class ComisionEscalaBodega extends Model
{
    protected $table = 'comision_escalas_bodega';

    /** @var list<string> */
    protected $fillable = [
        'comision_grupo_id',
        'bodega_id',
        'escalones',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'escalones' => 'array',
        ];
    }

    public function escala(): Escala
    {
        return Escala::de($this->escalones);
    }

    /**
     * @return BelongsTo<ComisionGrupo, $this>
     */
    public function grupo(): BelongsTo
    {
        return $this->belongsTo(ComisionGrupo::class, 'comision_grupo_id');
    }

    /**
     * @return BelongsTo<Bodega, $this>
     */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }
}
