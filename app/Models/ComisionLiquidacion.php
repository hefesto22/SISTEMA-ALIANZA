<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Comisiones\Escala;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lo que se le pagó a un vendedor en un grupo al cerrar un periodo: la
 * venta (sin ISV), lo que se descontó por facturas ya pagadas que se
 * anularon, la base, el % del escalón y la comisión, con la escala que
 * tenía ese día.
 *
 * El escalón es del mes: en la segunda quincena, base_antes es lo que ya
 * llevaba el mes y pagado_antes el adelanto de la primera; el % sale de
 * base + base_antes y comision es lo del mes menos pagado_antes.
 *
 * @property int $id
 * @property int $comision_periodo_id
 * @property int $vendedor_id
 * @property int $comision_grupo_id
 * @property string $grupo
 * @property float $venta
 * @property float $anulado
 * @property float $base
 * @property float $base_antes
 * @property float $pagado_antes
 * @property float $porcentaje
 * @property float $comision
 * @property float|null $meta
 * @property list<array{desde: float, porcentaje: float}> $escalones
 * @property bool $escala_propia
 * @property bool $escala_bodega
 * @property int $facturas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ComisionPeriodo $periodo
 * @property-read User $vendedor
 */
class ComisionLiquidacion extends Model
{
    protected $table = 'comision_liquidaciones';

    /** @var list<string> */
    protected $fillable = [
        'comision_periodo_id',
        'vendedor_id',
        'comision_grupo_id',
        'grupo',
        'venta',
        'anulado',
        'base',
        'base_antes',
        'pagado_antes',
        'porcentaje',
        'comision',
        'meta',
        'escalones',
        'escala_propia',
        'escala_bodega',
        'facturas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'venta'         => 'float',
            'anulado'       => 'float',
            'base'          => 'float',
            'base_antes'    => 'float',
            'pagado_antes'  => 'float',
            'porcentaje'    => 'float',
            'comision'      => 'float',
            'meta'          => 'float',
            'escalones'     => 'array',
            'escala_propia' => 'boolean',
            'escala_bodega' => 'boolean',
            'facturas'      => 'integer',
        ];
    }

    public function escala(): Escala
    {
        return Escala::de($this->escalones);
    }

    /**
     * @return BelongsTo<ComisionPeriodo, $this>
     */
    public function periodo(): BelongsTo
    {
        return $this->belongsTo(ComisionPeriodo::class, 'comision_periodo_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }
}
