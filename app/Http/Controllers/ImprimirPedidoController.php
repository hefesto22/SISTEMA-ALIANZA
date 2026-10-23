<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Pedido;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * Hoja imprimible del pedido (nota de despacho / preventa) para entregarle
 * al cliente o pasarla a facturación. Se abre en otra pestaña y se imprime
 * con Ctrl+P (o "Guardar como PDF").
 */
class ImprimirPedidoController extends Controller
{
    public function __invoke(Pedido $pedido): View
    {
        Gate::authorize('view', $pedido);

        $pedido->load(['items.producto', 'items.promocion.productoCondicion', 'items.promocion.productoBeneficio', 'cliente', 'vendedor', 'bodega', 'validador']);

        return view('pedidos.imprimir', ['pedido' => $pedido]);
    }
}
