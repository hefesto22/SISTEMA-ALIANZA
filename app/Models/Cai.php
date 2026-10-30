<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardaEnMayusculas;
use Database\Factories\CaiFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Código de Autorización de Impresión (CAI) del SAR con su rango de
 * facturas autorizado y la fecha límite de emisión.
 *
 * A quién le sirve (se configura en Administración > CAI):
 *  - Con bodegas: solo a esas. Si son varias, comparten el correlativo.
 *  - Sin bodegas: es global, para las bodegas que no tengan uno propio vigente.
 *
 * Número de factura: establecimiento-punto de emisión-tipo-correlativo,
 * p. ej. 000-001-01-00000123. `siguiente` es el próximo correlativo a usar.
 *
 * @property int $id
 * @property string $cai
 * @property string $establecimiento
 * @property string $punto_emision
 * @property string $tipo_documento
 * @property int $rango_desde
 * @property int $rango_hasta
 * @property int $siguiente
 * @property Carbon $fecha_limite
 * @property bool $activo
 * @property string|null $notas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Cai extends Model
{
    use GuardaEnMayusculas;

    /** @use HasFactory<CaiFactory> */
    use HasFactory;

    /** 6-6-6-6-6-2 caracteres en hexadecimal, como lo entrega el SAR. */
    public const FORMATO = '/^[0-9A-F]{6}-[0-9A-F]{6}-[0-9A-F]{6}-[0-9A-F]{6}-[0-9A-F]{6}-[0-9A-F]{2}$/';

    public const FACTURA = '01';

    public const CORRELATIVO_MAXIMO = 99999999;

    protected $table = 'cais';

    /** @var list<string> */
    protected $fillable = [
        'cai',
        'establecimiento',
        'punto_emision',
        'tipo_documento',
        'rango_desde',
        'rango_hasta',
        'fecha_limite',
        'activo',
        'notas',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'tipo_documento' => self::FACTURA,
        'activo'         => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rango_desde'  => 'integer',
            'rango_hasta'  => 'integer',
            'siguiente'    => 'integer',
            'fecha_limite' => 'date',
            'activo'       => 'boolean',
        ];
    }

    /**
     * El primer número que se usa es el inicio del rango. Si se corrige el
     * inicio antes de emitir la primera factura, el siguiente se mueve con él
     * (si no, saldrían números fuera del rango autorizado).
     */
    protected static function booted(): void
    {
        static::creating(function (Cai $cai): void {
            if ($cai->getAttribute('siguiente') === null) {
                $cai->siguiente = $cai->rango_desde;
            }
        });

        static::updating(function (Cai $cai): void {
            if ($cai->isDirty('rango_desde') && ! $cai->facturas()->exists()) {
                $cai->siguiente = $cai->rango_desde;
            }
        });
    }

    /**
     * Bodegas a las que sirve; sin bodegas es global.
     *
     * @return BelongsToMany<Bodega, $this>
     */
    public function bodegas(): BelongsToMany
    {
        return $this->belongsToMany(Bodega::class, 'cai_bodega');
    }

    /**
     * @return HasMany<Factura, $this>
     */
    public function facturas(): HasMany
    {
        return $this->hasMany(Factura::class);
    }

    /**
     * Activo, con fecha límite de hoy en adelante y con el siguiente número
     * dentro del rango autorizado.
     *
     * @param Builder<Cai> $query
     *
     * @return Builder<Cai>
     */
    public function scopeVigentes(Builder $query): Builder
    {
        return $query
            ->where('activo', true)
            ->whereDate('fecha_limite', '>=', today())
            ->whereColumn('siguiente', '>=', 'rango_desde')
            ->whereColumn('siguiente', '<=', 'rango_hasta');
    }

    /**
     * El CAI con el que se factura en una bodega: el suyo propio y, si no
     * tiene uno vigente, el global. Primero el que vence antes.
     *
     * Con $bloquear la fila queda tomada hasta terminar la transacción, para
     * que dos impresiones al mismo tiempo no saquen el mismo número.
     */
    public static function paraBodega(int $bodegaId, bool $bloquear = false): ?self
    {
        $propio = self::query()->vigentes()->whereHas('bodegas', fn (Builder $q): Builder => $q->whereKey($bodegaId));
        $global = self::query()->vigentes()->whereDoesntHave('bodegas');

        foreach ([$propio, $global] as $query) {
            $query->orderBy('fecha_limite')->orderBy('id');

            if ($bloquear) {
                $query->lockForUpdate();
            }

            $cai = $query->first();

            if ($cai !== null) {
                return $cai;
            }
        }

        return null;
    }

    /**
     * "000-001-01"
     */
    public function prefijo(): string
    {
        return "{$this->establecimiento}-{$this->punto_emision}-{$this->tipo_documento}";
    }

    /**
     * "000-001-01-00000123"
     */
    public function numero(int $correlativo): string
    {
        return $this->prefijo().'-'.str_pad((string) $correlativo, 8, '0', STR_PAD_LEFT);
    }

    public function totalNumeros(): int
    {
        return $this->rango_hasta - $this->rango_desde + 1;
    }

    public function usados(): int
    {
        return max(0, $this->siguiente - $this->rango_desde);
    }

    public function disponibles(): int
    {
        return max(0, $this->rango_hasta - $this->siguiente + 1);
    }

    public function estaVencido(): bool
    {
        return $this->fecha_limite->lt(today());
    }

    public function estaAgotado(): bool
    {
        return $this->siguiente > $this->rango_hasta;
    }

    public function enRango(int $correlativo): bool
    {
        return $correlativo >= $this->rango_desde && $correlativo <= $this->rango_hasta;
    }

    public function estaVigente(): bool
    {
        return $this->activo && ! $this->estaVencido() && ! $this->estaAgotado();
    }

    /**
     * Vigente, Vencido, Agotado o Inactivo.
     */
    public function situacion(): string
    {
        return match (true) {
            ! $this->activo      => 'Inactivo',
            $this->estaVencido() => 'Vencido',
            $this->estaAgotado() => 'Agotado',
            default              => 'Vigente',
        };
    }

    /**
     * @return list<string>
     */
    protected function camposEnMayusculas(): array
    {
        return ['cai', 'notas'];
    }
}
