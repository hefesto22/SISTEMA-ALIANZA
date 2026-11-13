<?php

declare(strict_types=1);

namespace App\Domain\Inventario;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Precios\ActualizarPrecioProducto;
use App\Enums\MotivoCambioPrecio;
use App\Enums\TipoMovimiento;
use App\Models\Configuracion;
use App\Models\Existencia;
use App\Models\Presentacion;
use App\Models\User;

/**
 * Abre cajas (o fardos, sacos…) en una bodega para vender sueltas: sale
 * la caja de su existencia y sus unidades entran a las sueltas (la base)
 * con el costo de esa caja (312 ÷ 48 = 6.50 cada una). El costo de las
 * sueltas queda promediado con las que ya había, sin importar de qué caja
 * salió cada una (o el de la caja, con "último costo" en Configuración), y
 * el precio de la unidad se recalcula con su % (ActualizarPrecioProducto).
 *
 * Qué caja se abre lo dice quien despacha (ValidarDespacho y
 * CorregirPedido). Deja los dos renglones en el kárdex como "Caja abierta".
 * Se llama dentro de una transacción.
 */
final class AbrirCaja
{
    /** Más cajas que esto de una vez es un error de dedo. */
    public const MAXIMO = 9999;

    public function __construct(
        private readonly MoverExistencia $mover,
        private readonly ActualizarPrecioProducto $precios,
    ) {}

    public function ejecutar(int $bodegaId, Presentacion $caja, int $cajas, ?User $usuario = null, ?int $pedidoId = null, ?string $nota = null): void
    {
        // Con su costo de este momento: una compra de hace un rato lo pudo cambiar.
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
        $unidades = $cajas * $caja->contenido;
        $costoSuelta = $caja->costoPorUnidad();

        // Las sueltas que había en todas las bodegas, antes de que entren estas.
        $habia = (int) Existencia::query()->where('presentacion_id', $base->id)->sum('cantidad');
        $costoNuevo = RegistrarCompra::costoNuevo(Configuracion::actual()->metodo_costo, $habia, $base->costo, $unidades, $costoSuelta);
        $nota ??= "SE ABREN {$cajas} {$caja->sigla()}";

        $this->mover->ejecutar($bodegaId, $caja, -$cajas, TipoMovimiento::Apertura, $usuario, pedidoId: $pedidoId, nota: $nota);

        $this->precios->ejecutar($producto, MotivoCambioPrecio::CajaAbierta, $usuario, nota: $nota, costos: [$base->id => $costoNuevo]);
        $base->refresh();
        $base->setRelation('producto', $producto);

        $this->mover->ejecutar($bodegaId, $base, $unidades, TipoMovimiento::Apertura, $usuario, pedidoId: $pedidoId, costo: $costoSuelta, nota: $nota);
    }
}
