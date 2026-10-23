<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Pedidos\MarcarImpreso;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Hoja imprimible del pedido (el panel la carga en un iframe oculto e
 * imprime ahí mismo). Si quien la abre es de bodega (o administración) y el
 * pedido ya está validado, cuenta como impresión: pasa a Impreso. A los
 * demás (el vendedor, un pedido pendiente) solo se les muestra la hoja.
 */
class ImprimirPedidoController extends Controller
{
    /** @var list<string> */
    public const RELACIONES = [
        'items.producto',
        'items.promocion.condiciones.producto',
        'items.promocion.productoBeneficio',
        'cliente.municipio.departamento',
        'vendedor',
        'bodega',
        'validador',
    ];

    public function __invoke(Request $request, Pedido $pedido, MarcarImpreso $marcar): View
    {
        Gate::authorize('view', $pedido);

        $usuario = $request->user();

        if ($usuario instanceof User && Gate::allows('imprimir', $pedido)) {
            $marcar->ejecutar($pedido, $usuario);
        }

        $pedido->load(self::RELACIONES);

        return view('pedidos.imprimir', [
            'pedidos' => collect([$pedido]),
            'titulo'  => "Pedido {$pedido->numero}",
        ]);
    }
}
