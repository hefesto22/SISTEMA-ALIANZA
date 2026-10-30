<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MetodoCosto;
use App\Enums\PrecioAlDespachar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Reglas globales de inventario y precios (Sistema → Configuración). Una
 * sola fila, guardada en caché.
 *
 * - metodo_costo: cómo queda el costo al entrar una compra (promedio o último).
 * - precio_al_despachar: qué se cobra si el precio cambió entre el pedido y
 *   el despacho (el del día o el del pedido, sin vender a pérdida).
 * - controlar_existencias: si está encendido, no se despacha más de lo que
 *   hay en la bodega. Se enciende cuando ya está cargado el inventario.
 *
 * @property int $id
 * @property MetodoCosto $metodo_costo
 * @property PrecioAlDespachar $precio_al_despachar
 * @property bool $controlar_existencias
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Configuracion extends Model
{
    private const CACHE = 'configuracion';

    protected $table = 'configuracion';

    /** @var list<string> */
    protected $fillable = [
        'metodo_costo',
        'precio_al_despachar',
        'controlar_existencias',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metodo_costo'          => MetodoCosto::class,
            'precio_al_despachar'   => PrecioAlDespachar::class,
            'controlar_existencias' => 'boolean',
        ];
    }

    public static function actual(): self
    {
        /** @var self $configuracion */
        $configuracion = Cache::rememberForever(self::CACHE, static fn (): self => self::query()->firstOrCreate([], [
            'metodo_costo'          => MetodoCosto::Promedio,
            'precio_al_despachar'   => PrecioAlDespachar::Despacho,
            'controlar_existencias' => false,
        ]));

        return $configuracion;
    }

    protected static function booted(): void
    {
        static::saved(static fn () => Cache::forget(self::CACHE));
    }
}
