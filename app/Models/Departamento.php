<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardaEnMayusculas;
use Database\Factories\DepartamentoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Departamento de Honduras (18). Catálogo fijo: DivisionPoliticaSeeder.
 *
 * @property int $id
 * @property string|null $codigo
 * @property string $nombre
 */
class Departamento extends Model
{
    use GuardaEnMayusculas;

    /** @use HasFactory<DepartamentoFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'departamentos';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
    ];

    /**
     * @return HasMany<Municipio, $this>
     */
    public function municipios(): HasMany
    {
        return $this->hasMany(Municipio::class);
    }

    /**
     * Para los selects: [id => nombre], en orden alfabético.
     *
     * @param list<int>|null $soloIds Limita a estos departamentos (null = todos).
     *
     * @return array<int, string>
     */
    public static function opciones(?array $soloIds = null): array
    {
        $query = self::query()->orderBy('nombre');

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
