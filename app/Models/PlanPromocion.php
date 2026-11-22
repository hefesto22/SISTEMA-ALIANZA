<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardaEnMayusculas;
use Carbon\CarbonInterface;
use Database\Factories\PlanPromocionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Plan de promociones que manda el proveedor para un mes (los "OPL"): una
 * hoja con las líneas que autoriza, por ejemplo "24+1 de NÉCTAR 200 (4
 * sabores), se regala NÉCTAR MANZANA, 1,000 unidades". Cada línea queda
 * como una promoción del plan (Promocion con plan_id) que cuenta unidades y
 * tiene su tope: no se regala más de lo autorizado, porque eso no lo
 * reconoce el proveedor.
 *
 * Las fechas y las bodegas del plan pasan a sus promociones (ninguna =
 * todas). Se arma y se cambia con App\Domain\Promociones\GuardarPlanPromocion.
 *
 * @property int $id
 * @property int $proveedor_id
 * @property string $nombre
 * @property Carbon $vigente_desde
 * @property Carbon $vigente_hasta
 * @property string|null $archivo la hoja que mandó el proveedor (disco public)
 * @property string|null $notas
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proveedor $proveedor
 * @property-read Collection<int, Promocion> $promociones
 * @property-read Collection<int, Bodega> $bodegas
 * @property-read User|null $creador
 */
class PlanPromocion extends Model
{
    use GuardaEnMayusculas;

    /** @use HasFactory<PlanPromocionFactory> */
    use HasFactory;

    /** Disco y carpeta de las hojas que manda el proveedor. */
    public const DISCO = 'public';

    public const CARPETA = 'planes-promocion';

    protected $table = 'planes_promocion';

    /** @var list<string> */
    protected $fillable = [
        'proveedor_id',
        'nombre',
        'vigente_desde',
        'vigente_hasta',
        'archivo',
        'notas',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proveedor_id'  => 'integer',
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Proveedor, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /**
     * Sus líneas: una promoción por cada una, en el orden de la hoja.
     *
     * @return HasMany<Promocion, $this>
     */
    public function promociones(): HasMany
    {
        return $this->hasMany(Promocion::class, 'plan_id')->orderBy('id');
    }

    /**
     * Bodegas donde vale; sin ninguna, vale en todas.
     *
     * @return BelongsToMany<Bodega, $this>
     */
    public function bodegas(): BelongsToMany
    {
        return $this->belongsToMany(Bodega::class, 'plan_promocion_bodega');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Administración ve todos; los demás, los que valen en su bodega.
     *
     * @param Builder<PlanPromocion> $query
     *
     * @return Builder<PlanPromocion>
     */
    public function scopeVisiblesPara(Builder $query, User $user): Builder
    {
        if ($user->veTodasLasBodegas()) {
            return $query;
        }

        $bodega = $user->bodega_id;

        return $query->where(function (Builder $q) use ($bodega): void {
            $q->whereDoesntHave('bodegas');

            if ($bodega !== null) {
                $q->orWhereHas('bodegas', fn (Builder $b): Builder => $b->where('bodegas.id', $bodega));
            }
        });
    }

    public function esVisiblePara(User $user): bool
    {
        if ($user->veTodasLasBodegas() || $this->bodegas->isEmpty()) {
            return true;
        }

        return $user->bodega_id !== null && $this->bodegas->contains('id', $user->bodega_id);
    }

    /**
     * Administración cambia todos; el encargado, solo los que son únicamente
     * de su bodega.
     */
    public function esEditablePor(User $user): bool
    {
        if ($user->veTodasLasBodegas()) {
            return true;
        }

        return $user->bodega_id !== null
            && $this->bodegas->count() === 1
            && $this->bodegas->first()?->id === $user->bodega_id;
    }

    /**
     * Alguna de sus promociones ya se usó en un pedido: el plan no se borra.
     */
    public function seUso(): bool
    {
        return PedidoItem::query()->whereIn('promocion_id', Promocion::query()->where('plan_id', $this->id)->select('id'))->exists();
    }

    /**
     * "Todas las bodegas" o "COPÁN, OCOTEPEQUE".
     */
    public function bodegasTexto(): string
    {
        return $this->bodegas->isEmpty() ? 'Todas las bodegas' : $this->bodegas->pluck('nombre')->sort()->implode(', ');
    }

    /**
     * "01/11/2026 al 30/11/2026".
     */
    public function vigenciaTexto(): string
    {
        return $this->vigente_desde->format('d/m/Y').' al '.$this->vigente_hasta->format('d/m/Y');
    }

    /**
     * Vigente, Programado (empieza después) o Vencido.
     */
    public function estado(?CarbonInterface $fecha = null): string
    {
        $dia = ($fecha ?? today())->toDateString();

        return match (true) {
            $this->vigente_desde->toDateString() > $dia => 'Programado',
            $this->vigente_hasta->toDateString() < $dia => 'Vencido',
            default                                     => 'Vigente',
        };
    }

    public function colorEstado(): string
    {
        return match ($this->estado()) {
            'Vigente'    => 'success',
            'Programado' => 'info',
            default      => 'gray',
        };
    }

    /**
     * @return list<string>
     */
    protected function camposEnMayusculas(): array
    {
        return ['nombre', 'notas'];
    }
}
