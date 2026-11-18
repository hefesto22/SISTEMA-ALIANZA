<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Comisiones\PeriodoComision;
use App\Enums\FrecuenciaComision;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Un periodo de comisiones ya cerrado (pagado): lo que se le pagó a cada
 * vendedor en cada grupo (liquidaciones) y de qué facturas salió. Ya no
 * cambia aunque después se corrijan facturas o se cambien las escalas.
 *
 * @property int $id
 * @property Carbon $desde
 * @property Carbon $hasta
 * @property FrecuenciaComision $frecuencia
 * @property float $venta
 * @property float $comision
 * @property int $facturas
 * @property Carbon $cerrado_at
 * @property int|null $cerrado_por
 * @property string|null $nota
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $cerradoPor
 */
class ComisionPeriodo extends Model
{
    protected $table = 'comision_periodos';

    /** @var list<string> */
    protected $fillable = [
        'desde',
        'hasta',
        'frecuencia',
        'venta',
        'comision',
        'facturas',
        'cerrado_at',
        'cerrado_por',
        'nota',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'desde'      => 'date',
            'hasta'      => 'date',
            'frecuencia' => FrecuenciaComision::class,
            'venta'      => 'float',
            'comision'   => 'float',
            'facturas'   => 'integer',
            'cerrado_at' => 'datetime',
        ];
    }

    public static function de(PeriodoComision $periodo): ?self
    {
        return self::query()
            ->whereDate('desde', $periodo->desde->toDateString())
            ->whereDate('hasta', $periodo->hasta->toDateString())
            ->first();
    }

    public function periodo(): PeriodoComision
    {
        return new PeriodoComision(CarbonImmutable::parse($this->desde->toDateString()), CarbonImmutable::parse($this->hasta->toDateString()), $this->frecuencia);
    }

    /**
     * El último que se cerró (el único que se puede reabrir).
     */
    public function esElUltimo(): bool
    {
        return ! self::query()->where('desde', '>', $this->desde->toDateString())->exists();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cerradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por');
    }

    /**
     * @return HasMany<ComisionLiquidacion, $this>
     */
    public function liquidaciones(): HasMany
    {
        return $this->hasMany(ComisionLiquidacion::class);
    }

    /**
     * @return HasMany<ComisionFactura, $this>
     */
    public function detalleFacturas(): HasMany
    {
        return $this->hasMany(ComisionFactura::class);
    }
}
