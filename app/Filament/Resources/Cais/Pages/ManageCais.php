<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cais\Pages;

use App\Domain\ValueObjects\RTN;
use App\Filament\Resources\Cais\CaiResource;
use App\Filament\Support\Acceso;
use App\Models\Bodega;
use App\Models\Cai;
use App\Models\Configuracion;
use App\Models\DatosFiscales;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;

/**
 * CAI y datos fiscales. Arriba, lo que sale impreso en cada factura (los
 * datos fiscales, con su botón Editar) y con qué CAI factura cada bodega,
 * con los avisos (CAI de prueba, bodegas sin CAI, datos que faltan). Abajo,
 * cada CAI en una tarjeta (resources/views/filament/cais/).
 */
class ManageCais extends ManageRecords
{
    protected static string $resource = CaiResource::class;

    public function getTitle(): string
    {
        return 'CAI y datos fiscales';
    }

    public function getSubheading(): string
    {
        return 'Lo que sale impreso en cada factura y la numeración que autorizó el SAR.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo CAI')
                ->modalWidth(Width::FourExtraLarge),
        ];
    }

    /**
     * Se abre con el botón Editar de la tarjeta de datos fiscales.
     */
    public function datosFiscalesAction(): Action
    {
        return Action::make('datosFiscales')
            ->label('Editar')
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
            });
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                View::make('filament.cais.resumen')
                    ->viewData(fn (): array => $this->resumen()),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    /**
     * Lo de arriba: los datos fiscales (con lo que falta) y con qué CAI emite
     * cada bodega activa sus facturas y, si están encendidas, sus notas de
     * crédito. "prueba": alguna bodega emite con un CAI de prueba.
     *
     * @return array{fiscales: DatosFiscales, rtn: string|null, faltan: list<string>, edita: bool, notas: bool, bodegas: list<array{nombre: string, factura: Cai|null, nota: Cai|null}>, prueba: bool}
     */
    public function resumen(): array
    {
        $fiscales = DatosFiscales::actual();
        $notas = Configuracion::actual()->notas_credito;
        $bodegas = [];
        $prueba = false;

        foreach (Bodega::query()->activas()->orderBy('nombre')->get() as $bodega) {
            $factura = Cai::paraBodega($bodega->id)?->loadMissing('bodegas');
            $nota = $notas ? Cai::paraBodega($bodega->id, tipo: Cai::NOTA_CREDITO)?->loadMissing('bodegas') : null;
            $prueba = $prueba || $factura?->esDePrueba() === true || $nota?->esDePrueba() === true;

            $bodegas[] = ['nombre' => $bodega->nombre, 'factura' => $factura, 'nota' => $nota];
        }

        $rtn = $fiscales->rtn;

        return [
            'fiscales' => $fiscales,
            'rtn'      => $rtn !== null && preg_match(RTN::REGEX, $rtn) === 1 ? (new RTN($rtn))->formateado() : $rtn,
            'faltan'   => array_keys(array_filter([
                'razón social' => $fiscales->razon_social,
                'RTN'          => $rtn,
                'dirección'    => $fiscales->direccion,
            ], fn (?string $valor): bool => blank($valor))),
            'edita'   => Acceso::puede('Update:Cai'),
            'notas'   => $notas,
            'bodegas' => $bodegas,
            'prueba'  => $prueba,
        ];
    }
}
