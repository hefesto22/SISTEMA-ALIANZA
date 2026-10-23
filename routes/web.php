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
use Illuminate\Support\Facades\Route;

// Hoja imprimible del pedido. Requiere sesión iniciada.
Route::middleware('auth')
    ->get('pedidos/{pedido}/imprimir', ImprimirPedidoController::class)
    ->name('pedidos.imprimir');

// Varios pedidos de un solo (?ids=1,2,3), uno por página.
Route::middleware('auth')
    ->get('imprimir/pedidos', ImprimirPedidosController::class)
    ->name('pedidos.imprimir-lote');
