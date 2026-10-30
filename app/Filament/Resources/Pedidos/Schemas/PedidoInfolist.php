<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Schemas;

use App\Models\Pedido;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Ver pedido, pensado para el teléfono:
 *  - Resumen: cliente, estado, quién lo levantó, validó, imprimió y corrigió,
 *    avisos (fuera de ruta, factura por retirar, notas) y el total.
 *  - Productos: la misma fila que en la preventa (cantidad, nombre, total),
 *    lo que no salió y abajo los totales.
 *  - Facturas: solo si ya tiene.
 *
 * Las vistas están en resources/views/filament/pedidos/ver.
 */
class PedidoInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'pv'])
                    ->schema([
                        View::make('filament.pedidos.ver.resumen'),
                    ]),

                Section::make('Productos')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->description(fn (Pedido $record): string => $record->estaPendiente()
                        ? 'Precios de hoy, sin ISV. Bodega confirma cantidades y precios al validar.'
                        : 'Lo que confirmó bodega. Precios sin ISV.')
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'pv'])
                    ->schema([
                        View::make('filament.pedidos.ver.productos'),
                    ]),

                Section::make('Facturas')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->description('Al corregir un pedido ya impreso, su factura se anula y al reimprimir sale otra. La anulada se retira al cliente.')
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'pv'])
                    ->visible(fn (Pedido $record): bool => $record->facturas()->exists())
                    ->schema([
                        View::make('filament.pedidos.ver.facturas'),
                    ]),
            ]);
    }
}
