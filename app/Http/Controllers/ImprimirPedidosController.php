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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Varios pedidos de un solo, uno por página ("Imprimir todos" en la pestaña
 * Por imprimir o "Imprimir seleccionados"). Solo entran los que esta persona
 * puede imprimir; todos pasan a Impreso con su factura.
 *
 * Todo o nada: si alguno no se puede facturar (su bodega no tiene CAI
 * vigente…) no se imprime ninguno ni se gasta ningún número.
 *
 * Uso: /imprimir/pedidos?ids=12,15,18
 */
class ImprimirPedidosController extends Controller
{
    public function __invoke(Request $request, MarcarImpreso $marcar): Response
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 403);

        $ids = array_values(array_filter(array_map(intval(...), explode(',', $request->string('ids')->toString()))));

        $pedidos = Pedido::query()
            ->visiblePara($usuario)
            ->whereKey($ids)
            ->with(ImprimirPedidoController::RELACIONES)
            ->orderBy('id')
            ->get()
            ->filter(fn (Pedido $pedido): bool => Gate::forUser($usuario)->allows('imprimir', $pedido))
            ->values();

        abort_if($pedidos->isEmpty(), 404, 'No hay pedidos para imprimir.');

        try {
            DB::transaction(function () use ($pedidos, $marcar, $usuario): void {
                foreach ($pedidos as $pedido) {
                    $marcar->ejecutar($pedido, $usuario);
                }
            });
        } catch (ReglaNegocioException $e) {
            return ImprimirPedidoController::sinImprimir($e->getMessage());
        }

        return response()->view('pedidos.imprimir', [
            'pedidos'  => $pedidos,
            'titulo'   => $pedidos->count().' facturas',
            'fiscales' => DatosFiscales::actual(),
        ]);
    }
}
