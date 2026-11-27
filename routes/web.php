<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Filament v5 toma control de "/" porque el panel está configurado con
| ->path('/') en AdminPanelProvider. NO definir aquí Route::get('/') —
| Filament lo perderá si la ruta web tiene mayor prioridad.
|
| Este archivo queda disponible para rutas custom adicionales (webhooks,
| callbacks OAuth, endpoints públicos puntuales) que NO conflictúen con
| las rutas de Filament.
|
| Las rutas internas del panel (/login, /dashboard, /users, /shield/roles,
| /horizon, etc.) las gestiona Filament automáticamente.
*/

use App\Http\Controllers\HojaDeAlistoController;
use App\Http\Controllers\ImprimirCargaController;
use App\Http\Controllers\ImprimirDevolucionController;
use App\Http\Controllers\ImprimirOrdenDeCambioController;
use App\Http\Controllers\ImprimirPedidoController;
use App\Http\Controllers\ImprimirPedidosController;
use App\Http\Controllers\ImprimirReporteController;
use App\Http\Controllers\ResumenDiaController;
use App\Http\Controllers\VistaPreviaFacturaController;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

// Hojas para imprimir. Solo muestran (no emiten facturas): eso lo hace la
// acción Imprimir. Pasan por el mismo acceso que el panel, así un usuario
// desactivado ya no entra aunque tenga la sesión abierta.
Route::middleware(['panel:admin', Authenticate::class, AuthenticateSession::class, 'throttle:120,1'])->group(function (): void {
    // Vista previa de un pedido.
    Route::get('pedidos/{pedido}/imprimir', ImprimirPedidoController::class)
        ->name('pedidos.imprimir');

    // La factura como va a salir, dentro del modal de Imprimir (?modo=original|copia&hoja=continuo|carta).
    Route::get('pedidos/{pedido}/vista-previa', VistaPreviaFacturaController::class)
        ->name('pedidos.vista-previa');

    // Varias de un solo (?ids=1,2,3), con los números seguidos: Imprimir las N de un día.
    Route::get('vista-previa/pedidos', [VistaPreviaFacturaController::class, 'lote'])
        ->name('pedidos.vista-previa-lote');

    // Lo que se saca de la bodega para unos pendientes (?ids=1,2,3), para apartarlo antes de validar.
    Route::get('alisto/pedidos', HojaDeAlistoController::class)
        ->name('pedidos.alisto');

    // Resumen de las facturas de un día (?dia=2026-11-18&bodega=2), para cuadrar.
    Route::get('facturas/resumen-dia', ResumenDiaController::class)
        ->name('facturas.resumen-dia');

    // Nota de crédito o constancia de una devolución.
    Route::get('devoluciones/{devolucion}/imprimir', ImprimirDevolucionController::class)
        ->name('devoluciones.imprimir');

    // Órdenes de cambio (?ids=1,2,3): lo que se le lleva al cliente por un cambio por cambio.
    Route::get('cambios/imprimir', ImprimirOrdenDeCambioController::class)
        ->name('cambios.imprimir');

    // Hojas de una carga del camión (?hoja=carga|cobro|liquidacion).
    Route::get('cargas/{carga}/imprimir', ImprimirCargaController::class)
        ->name('cargas.imprimir');

    // Uno o varios de un solo (?ids=1,2,3), uno por página.
    Route::get('imprimir/pedidos', ImprimirPedidosController::class)
        ->name('pedidos.imprimir-lote');

    // Reportes para imprimir o guardar como PDF (los mismos datos que en pantalla).
    Route::get('reportes/ventas/imprimir', [ImprimirReporteController::class, 'ventas'])
        ->name('reportes.ventas.imprimir');
    Route::get('reportes/compras/imprimir', [ImprimirReporteController::class, 'compras'])
        ->name('reportes.compras.imprimir');
    Route::get('reportes/inventario/imprimir', [ImprimirReporteController::class, 'inventario'])
        ->name('reportes.inventario.imprimir');
    Route::get('reportes/devoluciones/imprimir', [ImprimirReporteController::class, 'devoluciones'])
        ->name('reportes.devoluciones.imprimir');
    Route::get('reportes/devoluciones/acta', [ImprimirReporteController::class, 'actaDeBaja'])
        ->name('reportes.devoluciones.acta');
});
