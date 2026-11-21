<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Facturacion\ResumenDelDia;
use App\Domain\Reportes\Periodo;
use App\Models\DatosFiscales;
use App\Models\Factura;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Hoja del resumen de facturas de un día (botón "Resumen del día" de la
 * pestaña Impresos de Pedidos), para imprimir o guardar como PDF. Con
 * embebido=1 es la vista de impresión del modal (sin la barra de arriba).
 *
 * Uso: /facturas/resumen-dia?dia=2026-11-18&bodega=2
 */
class ResumenDiaController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User && $usuario->can('viewAny', Factura::class), 403);

        $dia = Periodo::dia($request->query('dia')) ?? abort(404);
        $bodega = $request->query('bodega');
        $d = ResumenDelDia::datos($usuario, $dia, is_numeric($bodega) ? (int) $bodega : null);

        return response()->view('reportes.imprimir', [
            'titulo'    => 'Facturas del día',
            'subtitulo' => $d['fecha'].' · '.$d['bodega'],
            'empresa'   => DatosFiscales::actual()->nombreParaMostrar(),
            'usuario'   => $usuario->name,
            'parte'     => 'reportes.partes.resumen-dia',
            'd'         => $d,
            'embebido'  => $request->boolean('embebido'),
        ]);
    }
}
