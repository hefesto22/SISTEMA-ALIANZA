<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Roles del sistema (además de super_admin y panel_user de Shield).
 *
 *  Administrador → todo: catálogo, precios, márgenes, pedidos de todas las bodegas.
 *  Bodega        → actualiza precios y valida el despacho de los pedidos de SU bodega.
 *  Vendedor      → consulta precios y levanta preventa; solo ve SUS pedidos.
 */
enum Rol: string
{
    case Administrador = 'administrador';
    case Bodega = 'bodega';
    case Vendedor = 'vendedor';
}
