<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Cargas\HojaDeCarga;
use App\Models\Carga;
use App\Models\DatosFiscales;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Las hojas de una carga del camión, para imprimir o guardar como PDF
 * (botón "Imprimir" de la carga). Con embebido=1 es la vista de impresión
 * del modal (sin la barra de arriba).
 *
 *   ?hoja=carga        lo que se sube al camión, para revisar y firmar al salir
 *   ?hoja=cobro        las facturas, para anotar lo que se cobró en cada una
 *   ?hoja=liquidacion  el cuadre al regresar, para firmar (ya liquidada)
 *
 * Uso: /cargas/12/imprimir?hoja=carga
 */
class ImprimirCargaController extends Controller
{
    public const HOJAS = [
        'carga'       => 'Hoja de carga',
        'cobro'       => 'Hoja de cobro',
        'liquidacion' => 'Liquidación de la carga',
    ];

    public function __invoke(Request $request, Carga $carga): Response
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User && $usuario->can('view', $carga), 403);

        $hoja = $request->string('hoja', 'carga')->toString();
        abort_unless(array_key_exists($hoja, self::HOJAS), 404);
        abort_if($hoja === 'liquidacion' && $carga->enRuta(), 404);

        $d = HojaDeCarga::datos($carga);

        return response()->view('reportes.imprimir', [
            'titulo'    => self::HOJAS[$hoja],
            'subtitulo' => $d['numero'].' · '.$d['fecha'].' · '.$d['bodega'],
            'empresa'   => DatosFiscales::actual()->nombreParaMostrar(),
            'usuario'   => $usuario->name,
            'parte'     => 'reportes.partes.carga-'.$hoja,
            'd'         => $d,
            'embebido'  => $request->boolean('embebido'),
        ]);
    }
}
