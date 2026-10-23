<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Schemas;

use App\Models\Pedido;
use App\Models\PedidoItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PedidoInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Pedido')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('numero')
                            ->label('Número')
                            ->weight('bold')
                            ->copyable(),
                        TextEntry::make('estado')
                            ->badge(),
                        TextEntry::make('fecha')
                            ->date('d/m/Y'),
                        TextEntry::make('cliente.nombre')
                            ->label('Cliente')
                            ->helperText(fn (Pedido $record): ?string => collect([
                                $record->cliente->rtn ? 'RTN '.$record->cliente->rtn : null,
                                $record->cliente->telefono,
                                $record->cliente->direccion,
                            ])->filter()->implode(' · ') ?: null),
                        TextEntry::make('vendedor.name')
                            ->label('Vendedor'),
                        TextEntry::make('bodega.nombre')
                            ->label('Bodega'),
                        TextEntry::make('validador.name')
                            ->label('Despachó')
                            ->placeholder('Sin validar')
                            ->helperText(fn (Pedido $record): ?string => $record->validado_at?->format('d/m/Y H:i')),
                        TextEntry::make('notas')
                            ->placeholder('—')
                            ->columnSpan(2),
                    ]),

                Section::make('Productos')
                    ->description(fn (Pedido $record): string => $record->estaPendiente()
                        ? 'Precios de hoy (estimados). Bodega confirma cantidades y precio al despachar.'
                        : 'Cantidades y precios confirmados por bodega.')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Producto'),
                                TableColumn::make('En'),
                                TableColumn::make('Pidió'),
                                TableColumn::make('Sale'),
                                TableColumn::make('Precio (sin ISV)'),
                                TableColumn::make('Descuento'),
                                TableColumn::make('Total'),
                            ])
                            ->schema([
                                TextEntry::make('producto.descripcion')
                                    ->helperText(fn (PedidoItem $record): string => $record->producto->codigo),
                                TextEntry::make('unidad')
                                    ->badge()
                                    ->color('gray'),
                                TextEntry::make('cantidad'),
                                TextEntry::make('cantidad_despachada')
                                    ->placeholder('—')
                                    ->weight('bold')
                                    ->color(fn (PedidoItem $record): ?string => $record->faltante() > 0 ? 'danger' : null)
                                    ->helperText(fn (PedidoItem $record): ?string => $record->faltante() > 0 ? "No salieron {$record->faltante()}" : null),
                                TextEntry::make('precio')
                                    ->formatStateUsing(fn (float $state): string => lempiras($state)),
                                TextEntry::make('descuento')
                                    ->formatStateUsing(fn (float $state): string => $state > 0 ? '−'.lempiras($state) : '—')
                                    ->color(fn (float $state): ?string => $state > 0 ? 'success' : 'gray')
                                    ->helperText(fn (PedidoItem $record): ?string => $record->promocion?->codigo),
                                TextEntry::make('total')
                                    ->formatStateUsing(fn (float $state): string => lempiras($state))
                                    ->weight('bold'),
                            ]),
                    ]),

                Section::make('Totales')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('subtotal')
                            ->formatStateUsing(fn (float $state): string => lempiras($state)),
                        TextEntry::make('descuento')
                            ->label('Descuento promociones')
                            ->formatStateUsing(fn (float $state): string => $state > 0 ? '−'.lempiras($state) : lempiras(0))
                            ->color(fn (float $state): ?string => $state > 0 ? 'success' : null),
                        TextEntry::make('isv')
                            ->label('ISV')
                            ->formatStateUsing(fn (float $state): string => lempiras($state)),
                        TextEntry::make('total')
                            ->formatStateUsing(fn (float $state): string => lempiras($state))
                            ->weight('bold')
                            ->size('lg'),
                    ]),
            ]);
    }
}
