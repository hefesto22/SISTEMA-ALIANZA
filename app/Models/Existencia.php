<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cuánto hay de una presentación de un producto en una bodega, contado en
 * ESA presentación: 2 (CJ48), 3 (CJ32), 5 (UN sueltas). Solo se cambia con
 * App\Domain\Inventario\MoverExistencia (deja el renglón en el kárdex).
 *
 * @property int $id
 * @property int $bodega_id
 * @property int $producto_id
 * @property int $presentacion_id
 * @property int $cantidad
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Bodega $bodega
 * @property-read Producto $producto
 * @property-read Presentacion $presentacion
 */
class Existencia extends Model
{
    protected $table = 'existencias';

    /** @var list<string> */
    protected $fillable = [
        'bodega_id',
        'producto_id',
        'presentacion_id',
        'cantidad',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bodega_id'       => 'integer',
            'producto_id'     => 'integer',
            'presentacion_id' => 'integer',
            'cantidad'        => 'integer',
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
     * @return BelongsTo<Producto, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * @return BelongsTo<Presentacion, $this>
     */
    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(Presentacion::class);
    }

    /**
     * Cuánto hay de esa presentación en la bodega, en ella (0 si nunca ha entrado).
     */
    public static function de(int $bodegaId, int $presentacionId): int
    {
        return (int) self::query()
            ->where('bodega_id', $bodegaId)
            ->where('presentacion_id', $presentacionId)
            ->value('cantidad');
    }

    /**
     * Todo lo que hay del producto en la bodega (o en todas), en unidades
     * base: cada presentación × lo que trae.
     */
    public static function en(?int $bodegaId, int $productoId): int
    {
        return (int) self::query()
            ->join('presentaciones', 'presentaciones.id', '=', 'existencias.presentacion_id')
            ->where('existencias.producto_id', $productoId)
            ->when($bodegaId !== null, fn (Builder $query): Builder => $query->where('existencias.bodega_id', $bodegaId))
            ->sum(DB::raw('existencias.cantidad * presentaciones.contenido'));
    }

    /**
     * Lo que hay de cada presentación del producto en la bodega (o en
     * todas): [presentacion_id => cantidad en esa presentación].
     *
     * @return array<int, int>
     */
    public static function porPresentacion(?int $bodegaId, int $productoId): array
    {
        /** @var array<int, int> $hay */
        $hay = self::query()
            ->where('producto_id', $productoId)
            ->when($bodegaId !== null, fn (Builder $query): Builder => $query->where('bodega_id', $bodegaId))
            ->groupBy('presentacion_id')
            ->selectRaw('presentacion_id, SUM(cantidad) as total')
            ->orderBy('presentacion_id')
            ->pluck('total', 'presentacion_id')
            ->map(fn (mixed $cantidad): int => (int) $cantidad)
            ->all();

        return $hay;
    }

    /**
     * Unidades como se cuentan en bodega: "2 CJ + 5 UN", "3 CJ", "5 UN", "0".
     * Con la sigla de la presentación: "2 CJ12 + 5 UN", "3 SC10".
     * (Para un producto: Producto::existenciaEnTexto().)
     */
    public static function enCajas(int $unidades, int $porCaja, string $caja = 'CJ', string $base = 'UN'): string
    {
        if ($unidades === 0) {
            return '0';
        }

        $signo = $unidades < 0 ? '-' : '';
        $resto = abs($unidades);

        if ($porCaja <= 1) {
            return "{$signo}{$resto} {$base}";
        }

        $cajas = intdiv($resto, $porCaja);
        $sueltas = $resto % $porCaja;

        $partes = array_filter([
            $cajas > 0 ? "{$cajas} {$caja}" : null,
            $sueltas > 0 ? "{$sueltas} {$base}" : null,
        ]);

        return $signo.implode(' + ', $partes);
    }
}
