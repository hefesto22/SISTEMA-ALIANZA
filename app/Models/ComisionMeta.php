<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Comisiones\Escala;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * La escala propia de un vendedor en un grupo de comisión (una ruta más
 * chica, alguien que empieza…). Si no tiene, usa la del grupo.
 *
 * @property int $id
 * @property int $comision_grupo_id
 * @property int $vendedor_id
 * @property list<array{desde: float, porcentaje: float}> $escalones
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ComisionGrupo $grupo
 * @property-read User $vendedor
 */
class ComisionMeta extends Model
{
    protected $table = 'comision_metas';

    /** @var list<string> */
    protected $fillable = [
        'comision_grupo_id',
        'vendedor_id',
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
     * @return BelongsTo<User, $this>
     */
    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }
}
