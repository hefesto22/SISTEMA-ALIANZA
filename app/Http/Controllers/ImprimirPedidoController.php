<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Pedidos\MarcarImpreso;
use App\Models\DatosFiscales;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Hoja imprimible del pedido (el panel la carga en un iframe oculto e
 * imprime ahí mismo). Si quien la abre es de bodega (o administración) y el
 * pedido ya está validado, cuenta como impresión: pasa a Impreso y sale con
 * su factura (la primera vez se emite con el CAI). A los demás (el
 * vendedor, un pedido pendiente) solo se les muestra la hoja.
 *
 * Si no se puede facturar (bodega sin CAI vigente, faltan datos fiscales)
 * responde 422 con el motivo y no cambia nada.
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
        'bodega.municipio.departamento',
        'validador',
        'factura.reemplazaA',
    ];

    public function __invoke(Request $request, Pedido $pedido, MarcarImpreso $marcar): Response
    {
        Gate::authorize('view', $pedido);

        $usuario = $request->user();

        if ($usuario instanceof User && Gate::allows('imprimir', $pedido)) {
            try {
                $marcar->ejecutar($pedido, $usuario);
            } catch (ReglaNegocioException $e) {
                return self::sinImprimir($e->getMessage());
            }
        }

        $pedido->load(self::RELACIONES);

        return response()->view('pedidos.imprimir', [
            'pedidos'  => collect([$pedido]),
            'titulo'   => $pedido->factura !== null ? "Factura {$pedido->factura->numero}" : "Pedido {$pedido->numero}",
            'fiscales' => DatosFiscales::actual(),
        ]);
    }

    /**
     * Página sin hoja, con el motivo (el panel lo muestra como aviso).
     */
    public static function sinImprimir(string $mensaje): Response
    {
        return response()->view('pedidos.sin-imprimir', ['mensaje' => $mensaje], 422);
    }
}
