<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Facturacion\EmitirFactura;
use App\Enums\FormatoFactura;
use App\Models\DatosFiscales;
use App\Models\Pedido;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * La hoja dentro del modal de Imprimir (ImprimirPedidoAction): la factura
 * como va a salir, en el papel escogido (?hoja=continuo|carta). Solo
 * muestra: no emite ni gasta números.
 *
 *  - ?modo=original: si todavía no tiene factura, la que se va a emitir,
 *    con el siguiente número del CAI (EmitirFactura::borrador), marcada
 *    VISTA PREVIA porque el número se confirma al imprimir.
 *  - ?modo=copia: la factura que ya tiene, con el sello REIMPRESIÓN · COPIA.
 */
class VistaPreviaFacturaController extends Controller
{
    public function __invoke(Request $request, Pedido $pedido): Response
    {
        Gate::authorize('imprimir', $pedido);

        $pedido->load(ImprimirPedidoController::RELACIONES);
        $copia = $request->query('modo') === 'copia';

        if (! $copia && $pedido->factura === null) {
            $borrador = EmitirFactura::borrador($pedido);

            if ($borrador !== null) {
                $pedido->setRelation('factura', $borrador);
            }
        }

        return response()->view('pedidos.imprimir', [
            'pedidos'    => collect([$pedido]),
            'titulo'     => 'Vista previa',
            'fiscales'   => DatosFiscales::actual(),
            'firmado'    => true,
            'originales' => $copia ? [] : [$pedido->id],
            'formato'    => FormatoFactura::para($request->query('hoja'), $pedido->bodega),
            'previa'     => true,
        ]);
    }
}
