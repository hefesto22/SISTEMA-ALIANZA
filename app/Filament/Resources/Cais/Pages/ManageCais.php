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
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;

/**
 * CAI y datos fiscales: debajo del título, en una línea, los datos fiscales
 * que salen en la factura; avisos solo si algo está mal (CAI de prueba,
 * bodegas sin CAI) y la tabla de los CAI.
 */
class ManageCais extends ManageRecords
{
    protected static string $resource = CaiResource::class;

    public function getTitle(): string
    {
        return 'CAI y datos fiscales';
    }

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
     * Los datos fiscales en una línea: razón social · RTN · dirección.
     */
    public function getSubheading(): string
    {
        $fiscales = DatosFiscales::actual();

        if (! $fiscales->estanCompletos()) {
            return 'Faltan los datos fiscales: sin razón social, RTN y dirección no se puede facturar (botón "Datos fiscales").';
        }

        $rtn = (string) $fiscales->rtn;

        return 'Se factura a nombre de '.collect([
            $fiscales->razon_social,
            'RTN '.(preg_match(RTN::REGEX, $rtn) === 1 ? (new RTN($rtn))->formateado() : $rtn),
            $fiscales->direccion,
        ])->filter()->implode(' · ');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                View::make('filament.cais.avisos')
                    ->viewData(fn (): array => ['avisos' => $this->avisos()])
                    ->visible(fn (): bool => $this->avisos() !== []),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    /**
     * Lo que está mal, en una línea cada cosa: alguna bodega emite con un CAI
     * de prueba, o hay bodegas sin CAI vigente de facturas o (si están
     * encendidas) de notas de crédito.
     *
     * @return list<array{color: string, texto: string}>
     */
    public function avisos(): array
    {
        $notas = Configuracion::actual()->notas_credito;
        $sinFactura = [];
        $sinNota = [];
        $prueba = false;

        foreach (Bodega::query()->activas()->orderBy('nombre')->get() as $bodega) {
            $factura = Cai::paraBodega($bodega->id);
            $nota = $notas ? Cai::paraBodega($bodega->id, tipo: Cai::NOTA_CREDITO) : null;

            if ($factura === null) {
                $sinFactura[] = $bodega->nombre;
            }

            if ($notas && $nota === null) {
                $sinNota[] = $bodega->nombre;
            }

            $prueba = $prueba || $factura?->esDePrueba() === true || $nota?->esDePrueba() === true;
        }

        $avisos = [];

        if ($prueba) {
            $avisos[] = ['color' => 'warning', 'texto' => 'Se factura con un CAI de prueba: lo que se imprime no vale ante el SAR. Agregue el CAI del SAR y desactive el de prueba.'];
        }

        if ($sinFactura !== []) {
            $avisos[] = ['color' => 'danger', 'texto' => 'Sin CAI de facturas vigente (no pueden imprimir): '.implode(', ', $sinFactura).'.'];
        }

        if ($sinNota !== []) {
            $avisos[] = ['color' => 'danger', 'texto' => 'Sin CAI de notas de crédito vigente (no registran devoluciones con crédito): '.implode(', ', $sinNota).'.'];
        }

        return $avisos;
    }
}
