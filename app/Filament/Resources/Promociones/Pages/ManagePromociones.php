<?php

declare(strict_types=1);

namespace App\Filament\Resources\Promociones\Pages;

use App\Filament\Resources\Promociones\PromocionResource;
use App\Filament\Resources\Promociones\Schemas\PromocionForm;
use App\Filament\Support\Acceso;
use App\Models\Promocion;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManagePromociones extends ManageRecords
{
    protected static string $resource = PromocionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nueva promoción')
                ->modalHeading('Nueva promoción')
                ->modalDescription('El código se genera solo al guardar.')
                ->modalWidth(Width::SevenExtraLarge)
                ->mutateDataUsing(fn (array $data): array => [
                    ...PromocionForm::completar($data),
                    'created_by' => Acceso::usuario()?->id,
                ])
                ->after(function (Promocion $record): void {
                    PromocionForm::enSuBodega($record);
                }),
        ];
    }

    public function getSubheading(): string
    {
        if (Acceso::esVendedor()) {
            return 'Promociones de hoy de su bodega. En la preventa se agregan de un toque y se aplican solas cuando el cliente lleva los productos.';
        }

        if (! Acceso::veTodo()) {
            return 'Las de su bodega las crea y las cambia usted; las de varias bodegas o de todas las maneja administración.';
        }

        return 'Cada promoción tiene su código y vale en las bodegas que escoja (ninguna = todas). Actívela o desactívela con el interruptor: los vendedores solo ven las vigentes de su bodega.';
    }
}
