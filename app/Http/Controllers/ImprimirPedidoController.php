<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DatosFiscales;
use App\Models\Pedido;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Hoja de un pedido (vista previa). Solo muestra: no emite facturas ni
 * cuenta impresiones; eso lo hace la acción Imprimir (ImprimirFacturas).
 * Si el pedido ya tiene factura, sale marcada COPIA · SIN VALOR FISCAL.
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

    public function __invoke(Request $request, Pedido $pedido): Response
    {
        Gate::authorize('view', $pedido);

        $pedido->load(self::RELACIONES);

        return response()->view('pedidos.imprimir', [
            'pedidos'    => collect([$pedido]),
            'titulo'     => $pedido->factura !== null ? "Factura {$pedido->factura->numero}" : "Pedido {$pedido->numero}",
            'fiscales'   => DatosFiscales::actual(),
            'firmado'    => false,
            'originales' => [],
        ]);
    }
}
