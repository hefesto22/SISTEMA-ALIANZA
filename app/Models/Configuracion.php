<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FrecuenciaComision;
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
 * - notas_credito: encendido (de fábrica), una devolución con crédito emite
 *   su nota de crédito (CAI tipo 07); apagado, queda como crédito interno.
 * - comision_frecuencia y comision_corte: cada cuánto se pagan las
 *   comisiones; con quincenal, la primera quincena va del 1 al día de corte.
 *
 * @property int $id
 * @property MetodoCosto $metodo_costo
 * @property PrecioAlDespachar $precio_al_despachar
 * @property bool $controlar_existencias
 * @property bool $notas_credito
 * @property FrecuenciaComision $comision_frecuencia
 * @property int $comision_corte
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Configuracion extends Model
{
    /**
     * Va con versión: lo que quedó en caché antes de agregar columnas (con una
     * migración) no las trae. Súbale el número al agregar una columna.
     */
    public const CACHE = 'configuracion:v3';

    protected $table = 'configuracion';

    /** @var list<string> */
    protected $fillable = [
        'metodo_costo',
        'precio_al_despachar',
        'controlar_existencias',
        'notas_credito',
        'comision_frecuencia',
        'comision_corte',
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
            'notas_credito'         => 'boolean',
            'comision_frecuencia'   => FrecuenciaComision::class,
            'comision_corte'        => 'integer',
        ];
    }

    public static function actual(): self
    {
        /** @var self $configuracion */
        $configuracion = Cache::rememberForever(self::CACHE, static fn (): self => self::query()->firstOrCreate([], [
            'metodo_costo'          => MetodoCosto::Promedio,
            'precio_al_despachar'   => PrecioAlDespachar::Despacho,
            'controlar_existencias' => false,
            'notas_credito'         => true,
            'comision_frecuencia'   => FrecuenciaComision::Quincenal,
            'comision_corte'        => 14,
        ]));

        return $configuracion;
    }

    protected static function booted(): void
    {
        static::saved(static fn () => Cache::forget(self::CACHE));
    }
}
