<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardaEnMayusculas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Unidad de medida en que entra y se vende un producto: UN (unidad), CJ
 * (caja), FD (fardo), SC (saco), BT (bote)… Con ella se arman las
 * presentaciones de cada producto ("CJ de 12", "FD de 6").
 *
 * El sistema trae las más comunes (migración 2026_11_12_100000);
 * administración agrega las que falten. No se borran: se desactivan.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property string $plural
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UnidadMedida extends Model
{
    use GuardaEnMayusculas;

    /** La que se usa como base si no se escoge otra. */
    public const BASE = 'UN';

    protected $table = 'unidades_medida';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'plural',
        'activo',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'activo' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * UN: la base de casi todos los productos.
     */
    public static function base(): self
    {
        return self::query()->where('codigo', self::BASE)->firstOrFail();
    }

    /**
     * Para selects: [id => "CJ · CAJA"] de las activas (y la que ya tenga escogida).
     *
     * @return array<int, string>
     */
    public static function opciones(?int $incluir = null): array
    {
        /** @var array<int, string> $opciones */
        $opciones = self::query()
            ->where(fn (Builder $query): Builder => $query->where('activo', true)->when($incluir !== null, fn (Builder $q): Builder => $q->orWhere('id', $incluir)))
            ->orderByRaw('CASE WHEN codigo = ? THEN 0 ELSE 1 END', [self::BASE])
            ->orderBy('codigo')
            ->get()
            ->mapWithKeys(fn (UnidadMedida $medida): array => [$medida->id => "{$medida->codigo} · {$medida->nombre}"])
            ->all();

        return $opciones;
    }

    /**
     * @param Builder<UnidadMedida> $query
     *
     * @return Builder<UnidadMedida>
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * @return list<string>
     */
    protected function camposEnMayusculas(): array
    {
        return ['codigo', 'nombre', 'plural'];
    }
}
