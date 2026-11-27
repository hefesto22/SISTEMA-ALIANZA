<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoReposicion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lo que se le debe llevar a un cliente por un cambio por cambio
 * (App\Domain\Devoluciones\Reposiciones): todo lo que regresó en la
 * devolución (bueno y dañado), en la presentación en que regresó.
 *
 * Se le lleva al mismo cliente o, si a ese ya se le dio con producto del
 * pedido de otro, a ese otro (el que se quedó esperando). Sale de la bodega
 * cuando se sube al camión y se marca entregada al liquidar la carga.
 *
 * Es la orden de cambio (CAM-000001, documento interno sin valor fiscal,
 * App\Domain\Devoluciones\OrdenesDeCambio): sale en Pedidos → Por imprimir;
 * ya impresa espera carga (Impreso) y solo así se sube al camión.
 *
 * @property int $id
 * @property string|null $numero CAM-000001
 * @property Carbon|null $impresa_at la primera vez que se imprimió
 * @property int $impresiones
 * @property int $devolucion_id
 * @property int $bodega_id
 * @property int $cliente_id a quién se le lleva
 * @property int|null $pedido_id el pedido de ese cliente (su ruta y su vendedor)
 * @property EstadoReposicion $estado
 * @property string|null $nota
 * @property Carbon|null $entregada_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Devolucion $devolucion
 * @property-read Bodega $bodega
 * @property-read Cliente $cliente
 * @property-read Pedido|null $pedido
 * @property-read Collection<int, CargaReposicion> $renglones
 */
class Reposicion extends Model
{
    protected $table = 'reposiciones';

    /** @var list<string> */
    protected $fillable = [
        'devolucion_id',
        'bodega_id',
        'cliente_id',
        'pedido_id',
        'estado',
        'nota',
        'entregada_at',
        'impresa_at',
        'impresiones',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'estado'      => 'por_entregar',
        'impresiones' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'devolucion_id' => 'integer',
            'bodega_id'     => 'integer',
            'cliente_id'    => 'integer',
            'pedido_id'     => 'integer',
            'estado'        => EstadoReposicion::class,
            'entregada_at'  => 'datetime',
            'impresa_at'    => 'datetime',
            'impresiones'   => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Reposicion $reposicion): void {
            $reposicion->forceFill(['numero' => 'CAM-'.str_pad((string) $reposicion->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();
        });
    }

    /**
     * Ya se imprimió su orden de cambio (solo así se sube al camión).
     */
    public function estaImpresa(): bool
    {
        return $this->impresa_at !== null;
    }

    /**
     * @return BelongsTo<Devolucion, $this>
     */
    public function devolucion(): BelongsTo
    {
        return $this->belongsTo(Devolucion::class);
    }

    /**
     * @return BelongsTo<Bodega, $this>
     */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    /**
     * @return BelongsTo<Cliente, $this>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * @return BelongsTo<Pedido, $this>
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    /**
     * En qué cargas ha ido y cómo terminó en cada una.
     *
     * @return HasMany<CargaReposicion, $this>
     */
    public function renglones(): HasMany
    {
        return $this->hasMany(CargaReposicion::class)->orderBy('id');
    }

    /**
     * Lo que se le lleva: cada presentación con su cantidad (lo bueno y lo
     * dañado de la devolución), en el orden en que regresó.
     *
     * @return list<array{presentacion: Presentacion, cantidad: int}>
     */
    public function productos(): array
    {
        $this->loadMissing(['devolucion.items.presentacion.unidadMedida', 'devolucion.items.presentacion.producto']);

        /** @var array<int, array{presentacion: Presentacion, cantidad: int}> $porPresentacion */
        $porPresentacion = [];

        foreach ($this->devolucion->items->sortBy('id') as $item) {
            $fila = $porPresentacion[$item->presentacion_id] ?? ['presentacion' => $item->presentacion, 'cantidad' => 0];
            $fila['cantidad'] += $item->cantidad;
            $porPresentacion[$item->presentacion_id] = $fila;
        }

        return array_values($porPresentacion);
    }

    /**
     * "1 CJ24 MARGARINA FRANCESSA 24X400GRS, 2 UN ZUKO LIMON 8/12/13G".
     */
    public function texto(): string
    {
        return implode(', ', array_map(
            fn (array $fila): string => number_format($fila['cantidad']).' '.$fila['presentacion']->sigla().' '.$fila['presentacion']->producto->descripcion,
            $this->productos(),
        ));
    }

    /**
     * La ruta y el vendedor del pedido del cliente ("CO22 · ALVARO"; sin
     * ruta, solo el vendedor; sin pedido, null).
     */
    public function deRuta(): ?string
    {
        $pedido = $this->pedido;

        if ($pedido === null) {
            return null;
        }

        $ruta = $pedido->ruta?->codigo();

        return $ruta !== null ? "{$ruta} · {$pedido->vendedor->name}" : $pedido->vendedor->name;
    }

    /**
     * Para escoger en la carga: "CO22 · ALVARO · PULPERIA LA BENDICION: 1 CJ24
     * MARGARINA… (CAM-000003 · DEV-000012)".
     */
    public function etiqueta(): string
    {
        $quien = implode(' · ', array_filter([$this->deRuta(), $this->cliente->nombre]));

        return "{$quien}: {$this->texto()} ({$this->numero} · {$this->devolucion->numero})";
    }
}
