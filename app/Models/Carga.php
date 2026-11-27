<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EntregaFactura;
use App\Enums\EstadoCarga;
use Database\Factories\CargaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lo que sale en un camión un día y su liquidación al regresar.
 *
 * Al salir: la bodega, la fecha, quién sale (lleva la mercadería y regresa
 * con el dinero; no tiene que ser el que vendió) y las facturas impresas que
 * lleva. La columna camion ya no se pide (queda vacía). El total de la carga es lo que debe regresar en dinero, menos
 * lo que no se entregó y lo devuelto. Todo es al contado.
 *
 * Al liquidar (App\Domain\Cargas\LiquidarCarga) se guardan las cifras:
 *   debe traer = entregado − devuelto
 *   trajo      = efectivo + cheques + transferencias
 *   diferencia = trajo − debe traer (negativa = falta, positiva = sobra)
 *
 * @property int $id
 * @property int $bodega_id
 * @property Carbon $fecha
 * @property int $repartidor_id
 * @property string|null $camion
 * @property EstadoCarga $estado
 * @property float $total_carga
 * @property float|null $entregado
 * @property float|null $no_entregado
 * @property float|null $devuelto
 * @property float|null $debe_traer
 * @property float|null $efectivo
 * @property float|null $cheques
 * @property float|null $transferencias
 * @property float|null $trajo
 * @property float|null $diferencia
 * @property string|null $nota
 * @property Carbon|null $liquidada_at
 * @property int|null $liquidada_por
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Bodega $bodega
 * @property-read User $repartidor
 * @property-read User|null $liquidador
 * @property-read Collection<int, CargaFactura> $renglones
 * @property-read Collection<int, Factura> $facturas
 * @property-read Collection<int, CargaPago> $pagos
 * @property-read Collection<int, CargaReposicion> $cambios
 * @property-read Collection<int, Devolucion> $devoluciones
 */
class Carga extends Model
{
    /** @use HasFactory<CargaFactory> */
    use HasFactory;

    protected $table = 'cargas';

    /** @var list<string> */
    protected $fillable = [
        'bodega_id',
        'fecha',
        'repartidor_id',
        'estado',
        'total_carga',
        'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'estado'      => 'en_ruta',
        'total_carga' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bodega_id'      => 'integer',
            'repartidor_id'  => 'integer',
            'fecha'          => 'date',
            'estado'         => EstadoCarga::class,
            'total_carga'    => 'float',
            'entregado'      => 'float',
            'no_entregado'   => 'float',
            'devuelto'       => 'float',
            'debe_traer'     => 'float',
            'efectivo'       => 'float',
            'cheques'        => 'float',
            'transferencias' => 'float',
            'trajo'          => 'float',
            'diferencia'     => 'float',
            'liquidada_at'   => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Bodega, $this>
     */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    /**
     * Quién sale en el camión y entrega.
     *
     * @return BelongsTo<User, $this>
     */
    public function repartidor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'repartidor_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function liquidador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'liquidada_por')->withTrashed();
    }

    /**
     * Cada factura de la carga con cómo terminó.
     *
     * @return HasMany<CargaFactura, $this>
     */
    public function renglones(): HasMany
    {
        return $this->hasMany(CargaFactura::class)->orderBy('id');
    }

    /**
     * @return BelongsToMany<Factura, $this>
     */
    public function facturas(): BelongsToMany
    {
        return $this->belongsToMany(Factura::class, 'carga_factura')->withPivot('estado')->withTimestamps();
    }

    /**
     * Los cheques y las transferencias que trajo.
     *
     * @return HasMany<CargaPago, $this>
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(CargaPago::class)->orderBy('id');
    }

    /**
     * Los cambios por entregar que lleva (a quién y cómo terminó cada uno).
     *
     * @return HasMany<CargaReposicion, $this>
     */
    public function cambios(): HasMany
    {
        return $this->hasMany(CargaReposicion::class)->orderBy('id');
    }

    /**
     * Las devoluciones que se registraron mientras sus facturas iban en el camión.
     *
     * @return HasMany<Devolucion, $this>
     */
    public function devoluciones(): HasMany
    {
        return $this->hasMany(Devolucion::class);
    }

    /**
     * Administración ve todas; los demás, las de su bodega.
     *
     * @param Builder<Carga> $query
     *
     * @return Builder<Carga>
     */
    public function scopeVisiblesPara(Builder $query, User $user): Builder
    {
        if ($user->veTodasLasBodegas()) {
            return $query;
        }

        return $user->bodega_id === null ? $query->whereRaw('1 = 0') : $query->where('bodega_id', $user->bodega_id);
    }

    /**
     * "CAR-000123".
     */
    public function numero(): string
    {
        return sprintf('CAR-%06d', $this->id);
    }

    public function enRuta(): bool
    {
        return $this->estado === EstadoCarga::EnRuta;
    }

    /**
     * "Cuadra", "Falta L 120.00" o "Sobra L 15.00" (vacío si no se ha liquidado).
     */
    public function diferenciaTexto(): string
    {
        if ($this->diferencia === null) {
            return '';
        }

        return match (true) {
            abs($this->diferencia) < 0.005 => 'Cuadra',
            $this->diferencia < 0          => 'Falta '.lempiras(abs($this->diferencia)),
            default                        => 'Sobra '.lempiras($this->diferencia),
        };
    }

    public function colorDiferencia(): string
    {
        return match (true) {
            $this->diferencia === null     => 'gray',
            abs($this->diferencia) < 0.005 => 'success',
            $this->diferencia < 0          => 'danger',
            default                        => 'warning',
        };
    }

    /**
     * La carga en ruta donde va la factura (null si no va en ninguna).
     */
    public static function enRutaDe(int $facturaId): ?self
    {
        $cargaId = CargaFactura::query()
            ->where('factura_id', $facturaId)
            ->where('estado', EntregaFactura::EnRuta->value)
            ->value('carga_id');

        return is_numeric($cargaId) ? self::query()->find((int) $cargaId) : null;
    }
}
