<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Enums\Rol;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Venta en bodega (mayoreo): la hace un usuario de bodega (User::vendeEnBodega)
 * al cliente que llega a la bodega. Él queda como vendedor del pedido.
 *
 *  - Puede dar un descuento a todo el pedido, hasta el tope que tiene en
 *    Usuarios (`descuento_maximo`). RecalcularPedido lo reparte en las
 *    líneas sin promoción, sin bajar del costo.
 *  - Al guardar queda validada (sale de la bodega; si faltan sueltas, se
 *    abren solas las cajas) y lista para imprimir.
 *  - Se la lleva el cliente (no va en camión) o va en camión (queda en
 *    Impreso para cargarla).
 *  - No cuenta para comisiones.
 *  - No se vende más de lo disponible (lo que hay menos lo apartado en
 *    preventas pendientes), aunque "controlar existencias" esté apagado:
 *    lo que ya se vendió en preventa no se vende otra vez en el mostrador
 *    (LineasPedido::controlaDisponible).
 *
 * Los vendedores de ruta no dan descuento.
 */
final class VentaEnBodega
{
    public function __construct(
        private readonly ValidarDespacho $validar,
    ) {}

    /**
     * Lo del formulario del pedido, revisado según quién vende: si es venta
     * en bodega, el % no pasa del tope del vendedor (ni baja de 0); si no, va
     * sin descuento y en camión. No confía en lo que mandó el formulario.
     *
     * @param array<string, mixed> $data con vendedor_id
     *
     * @return array<string, mixed>
     */
    public static function datos(array $data): array
    {
        $vendedor = is_numeric($data['vendedor_id'] ?? null) ? User::query()->find((int) $data['vendedor_id']) : null;
        $enBodega = $vendedor instanceof User && $vendedor->vendeEnBodega();
        $porcentaje = is_numeric($data['descuento_mayoreo_porcentaje'] ?? null) ? (float) $data['descuento_mayoreo_porcentaje'] : 0.0;

        $data['en_bodega'] = $enBodega;
        $data['descuento_mayoreo_porcentaje'] = $enBodega ? round(max(0.0, min($porcentaje, $vendedor->descuento_maximo)), 2) : 0.0;
        $data['se_lo_lleva'] = $enBodega && filter_var($data['se_lo_lleva'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $data;
    }

    /**
     * ¿El vendedor escogido en el pedido (su id) vende en bodega?
     */
    public static function esDe(mixed $vendedorId): bool
    {
        $vendedor = is_numeric($vendedorId) ? User::query()->find((int) $vendedorId) : null;

        return $vendedor instanceof User && $vendedor->vendeEnBodega();
    }

    /**
     * Los que venden en bodega (User::vendeEnBodega), para que la pantalla
     * del pedido sepa cuándo no deja pasar de lo disponible.
     *
     * @return list<int>
     */
    public static function vendedores(): array
    {
        return array_values(array_map(intval(...), User::query()
            ->whereNotNull('bodega_id')
            ->whereHas('roles', fn (Builder $query): Builder => $query->where('name', Rol::Bodega->value))
            ->whereDoesntHave('roles', fn (Builder $query): Builder => $query->where('name', Rol::Vendedor->value))
            ->pluck('id')
            ->all()));
    }

    /**
     * La deja validada (sale todo lo pedido, con las cajas que haya que
     * abrir) y por imprimir. Si no se puede (por ejemplo, con "controlar
     * existencias" y algo no alcanza), lanza la excepción y el pedido queda
     * pendiente para validarlo a mano.
     */
    public function validar(Pedido $pedido, User $usuario): Pedido
    {
        return $this->validar->ejecutar($pedido, [], $usuario, abrirSolas: true);
    }
}
