<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardaEnMayusculas;
use Database\Factories\MunicipioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Municipio de Honduras (298). El cliente y la bodega quedan en un
 * municipio; cada ruta cubre uno o varios.
 *
 * @property int $id
 * @property int $departamento_id
 * @property string $nombre
 * @property-read Departamento $departamento
 */
class Municipio extends Model
{
    use GuardaEnMayusculas;

    /** @use HasFactory<MunicipioFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'municipios';

    /** @var list<string> */
    protected $fillable = [
        'departamento_id',
        'nombre',
    ];

    /**
     * @return BelongsTo<Departamento, $this>
     */
    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    /**
     * @return HasMany<Cliente, $this>
     */
    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class);
    }

    /**
     * Rutas que cubren este municipio.
     *
     * @return BelongsToMany<Ruta, $this>
     */
    public function rutas(): BelongsToMany
    {
        return $this->belongsToMany(Ruta::class, 'ruta_municipio');
    }

    /**
     * "Santa Rosa de Copán · Copán": hay nombres repetidos entre departamentos.
     */
    public function etiqueta(): string
    {
        return "{$this->nombre} · {$this->departamento->nombre}";
    }

    /**
     * Municipios de un departamento para el select: [id => nombre].
     *
     * @param list<int>|null $soloIds Limita a estos municipios (null = todos).
     *
     * @return array<int, string>
     */
    public static function opcionesDe(mixed $departamentoId, ?array $soloIds = null): array
    {
        if (blank($departamentoId)) {
            return [];
        }

        $query = self::query()
            ->where('departamento_id', (int) $departamentoId)
            ->orderBy('nombre');

        if ($soloIds !== null) {
            $query->whereKey($soloIds);
        }

        /** @var array<int, string> $opciones */
        $opciones = $query->pluck('nombre', 'id')->all();

        return $opciones;
    }

    /**
     * @return list<string>
     */
    protected function camposEnMayusculas(): array
    {
        return ['nombre'];
    }
}
