<?php

declare(strict_types=1);

namespace App\Domain\Inventario;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\TipoMovimiento;
use App\Models\Presentacion;
use App\Models\User;

/**
 * Abre cajas (o fardos, sacos…) en una bodega para vender sueltas: sale
 * la caja de su existencia y sus unidades entran a las sueltas (la base).
 * El costo es uno por unidad del producto (ActualizarPrecioProducto), así
 * que abrir una caja no cambia costos ni precios: cada suelta vale lo mismo
 * salga de la caja que salga (312 ÷ 48 = 6.50).
 *
 * Qué caja se abre lo dice quien despacha (ValidarDespacho y
 * CorregirPedido). Deja los dos renglones en el kárdex como "Caja abierta".
 * Se llama dentro de una transacción.
 */
final class AbrirCaja
{
    /** Más cajas que esto de una vez es un error de dedo. */
    public const MAXIMO = 9999;

    public function __construct(private readonly MoverExistencia $mover) {}

    public function ejecutar(int $bodegaId, Presentacion $caja, int $cajas, ?User $usuario = null, ?int $pedidoId = null, ?string $nota = null): void
    {
        // Con su costo de este momento (el kárdex lo guarda).
        $caja->refresh();
        $caja->loadMissing(['producto.categoria', 'producto.presentaciones.unidadMedida', 'unidadMedida']);
        $producto = $caja->producto;

        if ($caja->es_base || $caja->contenido < 2) {
            throw new ReglaNegocioException("{$caja->sigla()} de {$producto->descripcion} no es una caja: no se abre.");
        }

        if ($cajas < 1 || $cajas > self::MAXIMO) {
            throw new ReglaNegocioException("Las {$caja->sigla()} que se abren de {$producto->descripcion} deben ser de 1 a ".self::MAXIMO.'.');
        }

        $base = $producto->presentacionBase();
        $base->setRelation('producto', $producto);
        $caja->setRelation('producto', $producto);
        $nota ??= "SE ABREN {$cajas} {$caja->sigla()}";

        $this->mover->ejecutar($bodegaId, $caja, -$cajas, TipoMovimiento::Apertura, $usuario, pedidoId: $pedidoId, nota: $nota);
        $this->mover->ejecutar($bodegaId, $base, $cajas * $caja->contenido, TipoMovimiento::Apertura, $usuario, pedidoId: $pedidoId, costo: $caja->costoPorUnidad(), nota: $nota);
    }
}
