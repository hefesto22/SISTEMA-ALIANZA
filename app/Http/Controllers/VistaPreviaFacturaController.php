<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Facturacion\EmitirFactura;
use App\Domain\Facturacion\ImprimirFacturas;
use App\Enums\FormatoFactura;
use App\Models\Cai;
use App\Models\DatosFiscales;
use App\Models\Pedido;
use App\Models\User;
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
 *
 * lote(): varios de un solo (Imprimir las N de un día), una por página y
 * con los números seguidos, en el orden en que se emiten.
 * Uso: /vista-previa/pedidos?ids=12,15,18&hoja=continuo
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

    public function lote(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 403);

        $ids = array_slice(array_values(array_unique(array_filter(array_map(intval(...), explode(',', $request->string('ids')->toString()))))), 0, ImprimirFacturas::MAXIMO);

        $pedidos = Pedido::query()
            ->visiblePara($usuario)
            ->whereKey($ids)
            ->with(ImprimirPedidoController::RELACIONES)
            ->orderBy('id')
            ->get()
            ->filter(fn (Pedido $pedido): bool => Gate::forUser($usuario)->allows('imprimir', $pedido))
            ->values();

        abort_if($pedidos->isEmpty(), 404, 'No hay pedidos para imprimir.');

        // Los números siguen por CAI: el primero toma el siguiente, el otro el que sigue…
        $caiDe = [];
        $usados = [];

        foreach ($pedidos as $pedido) {
            if ($pedido->factura !== null) {
                continue;
            }

            $cai = $caiDe[$pedido->bodega_id] ??= Cai::paraBodega($pedido->bodega_id)->id ?? 0;
            $borrador = EmitirFactura::borrador($pedido, $usados[$cai] ?? 0);

            if ($borrador !== null) {
                $pedido->setRelation('factura', $borrador);
                $usados[$cai] = ($usados[$cai] ?? 0) + 1;
            }
        }

        return response()->view('pedidos.imprimir', [
            'pedidos'    => $pedidos,
            'titulo'     => 'Vista previa',
            'fiscales'   => DatosFiscales::actual(),
            'firmado'    => true,
            'originales' => $pedidos->pluck('id')->all(),
            'formato'    => FormatoFactura::para($request->query('hoja'), $pedidos->firstOrFail()->bodega),
            'previa'     => true,
        ]);
    }
}
