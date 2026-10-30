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

use App\Http\Controllers\ImprimirPedidoController;
use App\Http\Controllers\ImprimirPedidosController;
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

    // Uno o varios de un solo (?ids=1,2,3), uno por página.
    Route::get('imprimir/pedidos', ImprimirPedidosController::class)
        ->name('pedidos.imprimir-lote');
});
