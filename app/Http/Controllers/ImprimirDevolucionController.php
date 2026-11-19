<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DatosFiscales;
use App\Models\Devolucion;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Hoja de una devolución: la nota de crédito (si se emitió) o la constancia
 * de devolución, sin valor fiscal, para que firme quien recibe.
 */
class ImprimirDevolucionController extends Controller
{
    public function __invoke(Devolucion $devolucion): Response
    {
        Gate::authorize('view', $devolucion);

        $devolucion->load(['items.producto', 'items.presentacion.unidadMedida', 'pedido.vendedor', 'factura', 'cliente', 'bodega', 'usuario']);

        return response()->view('devoluciones.imprimir', [
            'devolucion' => $devolucion,
            'fiscales'   => DatosFiscales::actual(),
        ]);
    }
}
