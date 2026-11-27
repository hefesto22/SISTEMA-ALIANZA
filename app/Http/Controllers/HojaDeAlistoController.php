<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Pedidos\HojaDeAlisto;
use App\Models\DatosFiscales;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Hoja de alisto de unos pedidos pendientes ("Hoja de alisto" de un día de
 * la pestaña Pendiente o de los marcados), para sacar y apartar lo que
 * llevan antes de validarlos. Con embebido=1 es la vista de impresión del
 * modal (sin la barra de arriba). Solo van los pendientes que el usuario ve.
 *
 * Uso: /alisto/pedidos?ids=1,2,3 (no va en pedidos/..., que es del panel)
 *
 * @phpstan-import-type HojaBodega from HojaDeAlisto
 */
class HojaDeAlistoController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User && $usuario->can('Validar:Pedido'), 403);

        $pedidos = array_values(array_filter(array_map(intval(...), explode(',', $request->string('ids')->toString()))));
        $ids = $pedidos === [] ? [] : array_values(array_map(
            intval(...),
            Pedido::query()->visiblePara($usuario)->whereKey($pedidos)->pluck('pedidos.id')->all(),
        ));
        $hoja = HojaDeAlisto::datos($ids);

        return response()->view('reportes.imprimir', [
            'titulo'    => 'Hoja de alisto',
            'subtitulo' => self::subtitulo($hoja),
            'empresa'   => DatosFiscales::actual()->nombreParaMostrar(),
            'usuario'   => $usuario->name,
            'parte'     => 'reportes.partes.alisto',
            'd'         => ['bodegas' => $hoja],
            'embebido'  => $request->boolean('embebido'),
        ]);
    }

    /**
     * "12 pedidos del 19/11/2026 · SANTA ROSA DE COPÁN" (o "del 18/11/2026 al
     * 19/11/2026"; con varias bodegas, sin el nombre).
     *
     * @param list<HojaBodega> $hoja
     */
    private static function subtitulo(array $hoja): string
    {
        if ($hoja === []) {
            return 'Sin pedidos pendientes';
        }

        $cuantos = array_sum(array_map(fn (array $bodega): int => count($bodega['pedidos']), $hoja));
        $desde = Carbon::parse(min(array_column($hoja, 'desde')))->format('d/m/Y');
        $hasta = Carbon::parse(max(array_column($hoja, 'hasta')))->format('d/m/Y');

        return ($cuantos === 1 ? '1 pedido' : "{$cuantos} pedidos")
            .($desde === $hasta ? " del {$desde}" : " del {$desde} al {$hasta}")
            .(count($hoja) === 1 ? ' · '.$hoja[0]['bodega'] : '');
    }
}
