<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Devoluciones\OrdenesDeCambio;
use App\Domain\Devoluciones\Reposiciones;
use App\Models\DatosFiscales;
use App\Models\Reposicion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Órdenes de cambio para imprimir (una hoja por orden), las que se marcaron
 * impresas desde Pedidos (OrdenesDeCambio::imprimir). Solo las de la bodega
 * del usuario y de devoluciones que puede ver.
 *
 * Uso: /cambios/imprimir?ids=1,2,3
 */
class ImprimirOrdenDeCambioController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 403);

        $ids = array_values(array_filter(array_map(intval(...), explode(',', $request->string('ids')->toString()))));

        $ordenes = $ids === [] ? collect() : OrdenesDeCambio::visibles($usuario)
            ->whereKey($ids)
            ->with([...Reposiciones::DATOS, 'bodega', 'devolucion.cliente', 'pedido'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Reposicion $orden): bool => Gate::forUser($usuario)->allows('view', $orden->devolucion))
            ->values();

        abort_if($ordenes->isEmpty(), 404);

        return response()->view('devoluciones.orden-de-cambio', [
            'ordenes'  => $ordenes,
            'fiscales' => DatosFiscales::actual(),
        ]);
    }
}
