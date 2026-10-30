<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Facturacion\ImprimirFacturas;
use App\Models\DatosFiscales;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Hoja de uno o varios pedidos, una por página. Solo muestra: las facturas
 * ya las emitió la acción Imprimir (ImprimirFacturas), que manda aquí con
 * un enlace firmado que dice cuáles van como ORIGINAL. Sin firma (enlace
 * copiado, vencido o armado a mano) todas salen COPIA · SIN VALOR FISCAL.
 *
 * Uso: /imprimir/pedidos?ids=12,15,18&originales=12,18&signature=…
 */
class ImprimirPedidosController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 403);

        $ids = self::ids($request->string('ids')->toString());
        abort_if($ids === [] || count($ids) > ImprimirFacturas::MAXIMO, 404, 'No hay pedidos para imprimir.');

        $firmado = $request->hasValidSignature();

        $pedidos = Pedido::query()
            ->visiblePara($usuario)
            ->whereKey($ids)
            ->with(ImprimirPedidoController::RELACIONES)
            ->orderBy('id')
            ->get();

        abort_if($pedidos->isEmpty(), 404, 'No hay pedidos para imprimir.');

        return response()->view('pedidos.imprimir', [
            'pedidos'    => $pedidos,
            'titulo'     => $pedidos->count() === 1 ? 'Pedido '.$pedidos->first()?->numero : $pedidos->count().' facturas',
            'fiscales'   => DatosFiscales::actual(),
            'firmado'    => $firmado,
            'originales' => $firmado ? self::ids($request->string('originales')->toString()) : [],
        ]);
    }

    /**
     * @return list<int>
     */
    private static function ids(string $texto): array
    {
        return array_values(array_unique(array_filter(array_map(intval(...), explode(',', $texto)))));
    }
}
