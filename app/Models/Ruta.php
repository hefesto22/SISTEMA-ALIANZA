<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardaEnMayusculas;
use Database\Factories\RutaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ruta de una bodega: su número (único en la bodega; con el prefijo de la
 * bodega es el código que sale en la factura: CO22), los municipios que
 * cubre y los vendedores que la trabajan. Un
 * vendedor ve los clientes de los municipios de sus rutas activas; para
 * cubrir a otro (vacaciones, suplencia) se le agrega a la ruta.
 * Si se crea sin número, toma el siguiente libre de la bodega.
 *
 * @property int $id
 * @property int $bodega_id
 * @property int $numero
 * @property string $nombre
 * @property bool $activa
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Bodega $bodega
 */
class Ruta extends Model
{
    use GuardaEnMayusculas;

    /** @use HasFactory<RutaFactory> */
    use HasFactory;

    protected $table = 'rutas';

    /** @var list<string> */
    protected $fillable = [
        'bodega_id',
        'numero',
        'nombre',
        'activa',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'activa' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'activa' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Ruta $ruta): void {
            if (blank($ruta->getAttribute('numero'))) {
                $ruta->numero = self::siguienteNumero($ruta->bodega_id);
            }
        });
    }

    /**
     * El siguiente número libre de la bodega (el más alto más uno).
     */
    public static function siguienteNumero(?int $bodegaId): int
    {
        return (int) self::query()->when($bodegaId !== null, fn (Builder $q): Builder => $q->where('bodega_id', $bodegaId))->max('numero') + 1;
    }

    /**
     * El código de la ruta: prefijo de la bodega y número ("CO22").
     */
    public function codigo(): string
    {
        return $this->bodega->prefijoDeRutas().$this->numero;
    }

    /**
     * @return BelongsTo<Bodega, $this>
     */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    /**
     * @return BelongsToMany<Municipio, $this>
     */
    public function municipios(): BelongsToMany
    {
        return $this->belongsToMany(Municipio::class, 'ruta_municipio');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function vendedores(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ruta_vendedor');
    }

    /**
     * @param Builder<Ruta> $query
     *
     * @return Builder<Ruta>
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }

    /**
     * Subconsulta con los municipios de las rutas activas del vendedor
     * (para whereIn sin traer los ids a PHP).
     */
    public static function municipiosDe(User $vendedor): QueryBuilder
    {
        return DB::table('ruta_municipio')
            ->join('rutas', 'rutas.id', '=', 'ruta_municipio.ruta_id')
            ->join('ruta_vendedor', 'ruta_vendedor.ruta_id', '=', 'rutas.id')
            ->where('rutas.activa', true)
            ->where('ruta_vendedor.user_id', $vendedor->id)
            ->select('ruta_municipio.municipio_id');
    }

    /**
     * Clientes activos de los municipios de la ruta.
     */
    public function totalClientes(): int
    {
        return Cliente::query()
            ->where('activo', true)
            ->whereIn('municipio_id', DB::table('ruta_municipio')->where('ruta_id', $this->id)->select('municipio_id'))
            ->count();
    }

    /**
     * La ruta del pedido, para la factura: la del vendedor que lleva el
     * municipio del cliente; si ninguna lo lleva (fuera de ruta), la primera
     * del vendedor; si no tiene, ninguna.
     */
    public static function delPedido(Pedido $pedido): ?self
    {
        $rutas = self::query()
            ->where('activa', true)
            ->whereHas('vendedores', fn (Builder $vendedores): Builder => $vendedores->whereKey($pedido->vendedor_id))
            ->with('municipios')
            ->orderBy('nombre')
            ->get();

        return $rutas->first(fn (self $ruta): bool => $ruta->municipios->contains('id', $pedido->cliente->municipio_id)) ?? $rutas->first();
    }

    /**
     * @return list<string>
     */
    protected function camposEnMayusculas(): array
    {
        return ['nombre'];
    }
}
