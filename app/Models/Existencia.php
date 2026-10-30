<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cuánto hay de un producto en una bodega, en UNIDADES. Solo se cambia con
 * App\Domain\Inventario\MoverExistencia (deja el renglón en el kárdex).
 *
 * @property int $id
 * @property int $bodega_id
 * @property int $producto_id
 * @property int $cantidad
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Bodega $bodega
 * @property-read Producto $producto
 */
class Existencia extends Model
{
    protected $table = 'existencias';

    /** @var list<string> */
    protected $fillable = [
        'bodega_id',
        'producto_id',
        'cantidad',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
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
     * Cuánto hay en la bodega (0 si nunca ha entrado).
     */
    public static function en(int $bodegaId, int $productoId): int
    {
        return (int) self::query()
            ->where('bodega_id', $bodegaId)
            ->where('producto_id', $productoId)
            ->value('cantidad');
    }

    /**
     * Unidades como se cuentan en bodega: "2 CJ + 5 UN", "3 CJ", "5 UN", "0".
     */
    public static function enCajas(int $unidades, int $porCaja): string
    {
        if ($unidades === 0) {
            return '0';
        }

        $signo = $unidades < 0 ? '-' : '';
        $resto = abs($unidades);

        if ($porCaja <= 1) {
            return "{$signo}{$resto} UN";
        }

        $cajas = intdiv($resto, $porCaja);
        $sueltas = $resto % $porCaja;

        $partes = array_filter([
            $cajas > 0 ? "{$cajas} CJ" : null,
            $sueltas > 0 ? "{$sueltas} UN" : null,
        ]);

        return $signo.implode(' + ', $partes);
    }
}
