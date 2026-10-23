<?php

declare(strict_types=1);

namespace App\Filament\Resources\Promociones\Pages;

use App\Filament\Resources\Promociones\PromocionResource;
use App\Filament\Resources\Promociones\Schemas\PromocionForm;
use App\Filament\Support\Acceso;
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
                ]),
        ];
    }

    public function getSubheading(): string
    {
        if (Acceso::esVendedor()) {
            return 'Promociones de hoy. Se aplican solas en el pedido cuando el cliente lleva los productos.';
        }

        return 'Cada promoción tiene su código. Actívela o desactívela con el interruptor: los vendedores solo ven las vigentes.';
    }
}
