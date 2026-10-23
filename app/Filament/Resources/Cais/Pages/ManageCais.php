<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cais\Pages;

use App\Domain\ValueObjects\RTN;
use App\Filament\Resources\Cais\CaiResource;
use App\Filament\Support\Acceso;
use App\Models\DatosFiscales;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ManageCais extends ManageRecords
{
    protected static string $resource = CaiResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('datosFiscales')
                ->label('Datos fiscales')
                ->icon(Heroicon::OutlinedBuildingOffice2)
                ->color('gray')
                ->visible(fn (): bool => Acceso::puede('Update:Cai'))
                ->modalHeading('Datos fiscales de la factura')
                ->modalDescription('Van impresos en todas las facturas (Acuerdo 481-2017 del SAR). Sin razón social, RTN y dirección no se puede facturar.')
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalSubmitActionLabel('Guardar')
                ->fillForm(fn (): array => DatosFiscales::actual()->only([
                    'razon_social', 'nombre_comercial', 'rtn', 'direccion', 'telefono', 'correo', 'leyenda',
                ]))
                ->schema([
                    TextInput::make('razon_social')
                        ->label('Razón social')
                        ->helperText('Como está inscrita en el RTN.')
                        ->required()
                        ->maxLength(150),
                    TextInput::make('nombre_comercial')
                        ->label('Nombre comercial')
                        ->helperText('Si es distinto de la razón social; es el que sale grande.')
                        ->maxLength(150),
                    TextInput::make('rtn')
                        ->label('RTN')
                        ->required()
                        ->length(RTN::LONGITUD)
                        ->regex(RTN::REGEX)
                        ->validationMessages(['regex' => 'El RTN son 14 dígitos, sin guiones ni espacios.'])
                        ->placeholder('08011999123456'),
                    TextInput::make('telefono')
                        ->label('Teléfono')
                        ->tel()
                        ->maxLength(40),
                    TextInput::make('correo')
                        ->label('Correo')
                        ->email()
                        ->maxLength(120),
                    Textarea::make('direccion')
                        ->label('Dirección de la casa matriz')
                        ->helperText('La dirección de cada bodega (establecimiento) sale de Bodegas.')
                        ->required()
                        ->rows(2)
                        ->maxLength(255),
                    Textarea::make('leyenda')
                        ->label('Leyenda al pie (opcional)')
                        ->helperText('Ej. datos del autoimpresor o de la imprenta autorizada.')
                        ->rows(2)
                        ->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    DatosFiscales::actual()->update($data);

                    Notification::make()->title('Datos fiscales guardados')->success()->send();
                }),
            CreateAction::make()
                ->label('Nuevo CAI')
                ->modalWidth(Width::FourExtraLarge),
        ];
    }

    /**
     * Avisos: faltan datos fiscales o hay bodegas que no pueden facturar.
     */
    public function getSubheading(): ?string
    {
        $avisos = [];

        if (! DatosFiscales::actual()->estanCompletos()) {
            $avisos[] = 'Faltan los datos fiscales (botón "Datos fiscales").';
        }

        $sinCai = CaiResource::bodegasSinCai();

        if ($sinCai !== []) {
            $avisos[] = 'Sin CAI vigente, no pueden imprimir facturas: '.implode(', ', $sinCai).'.';
        }

        return $avisos === [] ? 'Todas las bodegas tienen CAI vigente.' : implode(' ', $avisos);
    }
}
