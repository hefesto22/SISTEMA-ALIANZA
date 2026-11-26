<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\ComprasDelPeriodo;
use App\Domain\Reportes\DevolucionesDelPeriodo;
use App\Domain\Reportes\InventarioValorizado;
use App\Domain\Reportes\Periodo;
use App\Domain\Reportes\VentasDelPeriodo;
use App\Filament\Pages\ReporteDevoluciones;
use App\Models\DatosFiscales;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Hojas para imprimir o guardar como PDF los reportes, con los mismos datos
 * que en pantalla. El encargado siempre recibe el de su bodega aunque pida
 * otra en el enlace.
 *
 * Con embebido=1 es la vista de impresión del modal del panel
 * (App\Filament\Support\VistaImpresion): sin la barra de arriba y sin abrir
 * sola la ventana de imprimir.
 *
 * Uso: /reportes/ventas/imprimir?desde=2026-11-01&hasta=2026-11-30&bodega=2&proveedor=5&ver=vendedores
 *      /reportes/compras/imprimir?desde=2026-11-01&hasta=2026-11-14&proveedor=5&ver=productos
 *      /reportes/inventario/imprimir?bodega=2&proveedor=5&buscar=aceite&todos=1
 *      /reportes/devoluciones/imprimir?desde=2026-11-01&hasta=2026-11-30&proveedor=5&ver=perdidas
 */
class ImprimirReporteController extends Controller
{
    public function ventas(Request $request): Response
    {
        $usuario = self::usuario($request, 'View:ReporteVentas');

        $reporte = new VentasDelPeriodo(
            Periodo::de('otro', $request->string('desde')->toString(), $request->string('hasta')->toString()),
            AlcanceReporte::bodega($usuario, $request->input('bodega')),
            AlcanceReporte::proveedor($request->input('proveedor')),
        );
        $ver = VentasDelPeriodo::ver($request->input('ver'));

        return response()->view('reportes.imprimir', [
            'titulo'    => $reporte->titulo($ver),
            'subtitulo' => $reporte->periodo->texto().' · '.$reporte->alcance(),
            'empresa'   => DatosFiscales::actual()->nombreParaMostrar(),
            'usuario'   => $usuario->name,
            'parte'     => 'reportes.partes.ventas',
            'd'         => $reporte->datos($ver),
            'embebido'  => $request->boolean('embebido'),
        ]);
    }

    /**
     * Con un proveedor es la hoja que se le manda: arriba su razón social y su RTN.
     */
    public function compras(Request $request): Response
    {
        $usuario = self::usuario($request, 'View:ReporteCompras');

        $reporte = new ComprasDelPeriodo(
            Periodo::de('otro', $request->string('desde')->toString(), $request->string('hasta')->toString()),
            AlcanceReporte::bodega($usuario, $request->input('bodega')),
            AlcanceReporte::proveedor($request->input('proveedor')),
        );
        $ver = ComprasDelPeriodo::ver($request->input('ver'));

        return response()->view('reportes.imprimir', [
            'titulo'    => $reporte->titulo($ver),
            'subtitulo' => collect([$reporte->fichaProveedor(), $reporte->periodo->texto(), $reporte->nombreBodega()])->filter()->implode(' · '),
            'empresa'   => DatosFiscales::actual()->nombreParaMostrar(),
            'usuario'   => $usuario->name,
            'parte'     => 'reportes.partes.compras',
            'd'         => $reporte->datos($ver),
            'embebido'  => $request->boolean('embebido'),
        ]);
    }

    public function inventario(Request $request): Response
    {
        $usuario = self::usuario($request, 'View:ReporteInventario');
        $proveedor = $request->input('proveedor');

        $reporte = new InventarioValorizado(
            AlcanceReporte::bodega($usuario, $request->input('bodega')),
            is_numeric($proveedor) ? Proveedor::query()->find((int) $proveedor) : null,
            $request->string('buscar')->toString(),
            ! $request->boolean('todos'),
        );

        return response()->view('reportes.imprimir', [
            'titulo'    => 'Inventario valorizado',
            'subtitulo' => $reporte->nombreBodega().' · al '.now()->format('d/m/Y H:i').($reporte->filtrosTexto() === null ? '' : ' · '.$reporte->filtrosTexto()),
            'empresa'   => DatosFiscales::actual()->nombreParaMostrar(),
            'usuario'   => $usuario->name,
            'parte'     => 'reportes.partes.inventario',
            'd'         => $reporte->datos(),
            'embebido'  => $request->boolean('embebido'),
        ]);
    }

    /**
     * Con un proveedor y "Pérdidas" es lo que se le reclama: lo dañado de sus productos.
     */
    public function devoluciones(Request $request): Response
    {
        $usuario = self::usuario($request, ReporteDevoluciones::PERMISO);

        $reporte = new DevolucionesDelPeriodo(
            Periodo::de('otro', $request->string('desde')->toString(), $request->string('hasta')->toString()),
            AlcanceReporte::bodega($usuario, $request->input('bodega')),
            AlcanceReporte::proveedor($request->input('proveedor')),
        );
        $ver = DevolucionesDelPeriodo::ver($request->input('ver'));

        return response()->view('reportes.imprimir', [
            'titulo'    => $reporte->titulo($ver),
            'subtitulo' => $reporte->periodo->texto().' · '.$reporte->alcance(),
            'empresa'   => DatosFiscales::actual()->nombreParaMostrar(),
            'usuario'   => $usuario->name,
            'parte'     => 'reportes.partes.devoluciones',
            'd'         => $reporte->datos($ver),
            'embebido'  => $request->boolean('embebido'),
        ]);
    }

    private static function usuario(Request $request, string $permiso): User
    {
        $usuario = $request->user();

        if (! $usuario instanceof User || ! AlcanceReporte::puedeVer($usuario, $permiso)) {
            abort(403);
        }

        return $usuario;
    }
}
