<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cais;

use App\Filament\Resources\Cais\Pages\ManageCais;
use App\Models\Bodega;
use App\Models\Cai;
use App\Support\Texto;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * CAI del SAR: rango de facturas autorizado, fecha límite y a qué bodegas
 * sirve. Se puede tener uno global (sin bodegas), uno por bodega o uno
 * compartido por varias bodegas (comparten el correlativo).
 *
 * Una vez que un CAI tiene facturas, ya no se cambian el CAI, el
 * establecimiento, el punto de emisión ni el inicio del rango.
 *
 * @extends \Filament\Resources\Resource<Cai>
 */
class CaiResource extends Resource
{
    protected static ?string $model = Cai::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $recordTitleAttribute = 'cai';

    protected static ?string $modelLabel = 'CAI';

    protected static ?string $pluralModelLabel = 'CAI y datos fiscales';

    protected static ?string $navigationLabel = 'CAI y datos fiscales';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return 'Administración';
    }

    public static function form(Schema $schema): Schema
    {
        $usado = fn (?Cai $record): bool => $record !== null && $record->facturas()->exists();

        return $schema
            ->columns(3)
            ->components([
                TextInput::make('cai')
                    ->label('CAI')
                    ->placeholder('XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX')
                    ->required()
                    ->maxLength(37)
                    ->mutateStateForValidationUsing(fn (?string $state): ?string => filled($state) ? Texto::mayusculas($state) : $state)
                    ->regex(Cai::FORMATO)
                    ->validationMessages(['regex' => 'El CAI va en 6 grupos separados por guion: XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX (números y letras de la A a la F).'])
                    ->unique(ignoreRecord: true)
                    ->disabled($usado)
                    ->extraInputAttributes(['class' => 'font-mono'])
                    ->columnSpanFull(),
                TextInput::make('establecimiento')
                    ->required()
                    ->default('000')
                    ->length(3)
                    ->regex('/^\d{3}$/')
                    ->helperText('3 dígitos. 000 = casa matriz.')
                    ->disabled($usado),
                TextInput::make('punto_emision')
                    ->label('Punto de emisión')
                    ->required()
                    ->default('001')
                    ->length(3)
                    ->regex('/^\d{3}$/')
                    ->helperText('3 dígitos, como lo autorizó el SAR.')
                    ->disabled($usado),
                Select::make('tipo_documento')
                    ->label('Documento')
                    ->options([Cai::FACTURA => '01 · Factura'])
                    ->default(Cai::FACTURA)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->disabled($usado),
                TextInput::make('rango_desde')
                    ->label('Rango: desde')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(Cai::CORRELATIVO_MAXIMO)
                    ->required()
                    ->live(onBlur: true)
                    ->helperText('Primer correlativo autorizado (ej. 1).')
                    ->disabled($usado),
                TextInput::make('rango_hasta')
                    ->label('Rango: hasta')
                    ->numeric()
                    ->integer()
                    ->minValue(fn (Get $get, ?Cai $record): int => max((int) $get('rango_desde'), $record !== null ? $record->siguiente - 1 : 1))
                    ->maxValue(Cai::CORRELATIVO_MAXIMO)
                    ->required()
                    ->helperText('Último correlativo autorizado (ej. 5000).')
                    ->rule(fn (Get $get, ?Cai $record): Closure => self::sinChoqueDeRango($get, $record)),
                DatePicker::make('fecha_limite')
                    ->label('Fecha límite de emisión')
                    ->required()
                    ->displayFormat('d/m/Y')
                    ->helperText('Después de esta fecha ya no se factura con este CAI.'),
                Select::make('bodegas')
                    ->label('Bodegas que lo usan')
                    ->relationship('bodegas', 'nombre', fn (Builder $query): Builder => $query->orderBy('nombre'))
                    ->multiple()
                    ->preload()
                    ->placeholder('Global: todas las bodegas')
                    ->helperText('Vacío = global: sirve a todas las bodegas que no tengan un CAI propio vigente. Si escoge varias, comparten la numeración.')
                    ->columnSpanFull(),
                TextInput::make('notas')
                    ->maxLength(255)
                    ->placeholder('Ej. solicitud SAR del 10/10/2026')
                    ->columnSpan(2),
                Toggle::make('activo')
                    ->default(true)
                    ->inline(false)
                    ->helperText('Inactivo: no se usa aunque tenga números.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cai')
                    ->label('CAI')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Cai $record): ?string => $record->notas),
                TextColumn::make('rango')
                    ->label('Rango autorizado')
                    ->state(fn (Cai $record): string => $record->numero($record->rango_desde))
                    ->description(fn (Cai $record): string => 'al '.$record->numero($record->rango_hasta))
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('usados')
                    ->label('Usados')
                    ->state(fn (Cai $record): string => number_format($record->usados()).' de '.number_format($record->totalNumeros()))
                    ->description(fn (Cai $record): string => $record->estaAgotado()
                        ? 'Ya no quedan números'
                        : 'Siguiente: '.$record->numero($record->siguiente))
                    ->color(fn (Cai $record): ?string => $record->disponibles() <= max(10, (int) ($record->totalNumeros() * 0.05)) ? 'warning' : null),
                TextColumn::make('fecha_limite')
                    ->label('Fecha límite')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn (Cai $record): string => $record->estaVencido() ? 'Vencido' : 'Vence '.$record->fecha_limite->copy()->endOfDay()->diffForHumans())
                    ->color(fn (Cai $record): ?string => match (true) {
                        $record->estaVencido()                           => 'danger',
                        $record->fecha_limite->lte(today()->addDays(30)) => 'warning',
                        default                                          => null,
                    }),
                TextColumn::make('bodegas.nombre')
                    ->label('Bodegas')
                    ->badge()
                    ->color('gray')
                    ->placeholder('Global: todas'),
                TextColumn::make('situacion')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (Cai $record): string => $record->situacion())
                    ->color(fn (string $state): string => match ($state) {
                        'Vigente'  => 'success',
                        'Inactivo' => 'gray',
                        default    => 'danger',
                    }),
            ])
            ->defaultSort('fecha_limite', 'desc')
            ->emptyStateHeading('Sin CAI')
            ->emptyStateDescription('Agregue el CAI que le dio el SAR. Sin un CAI vigente no se pueden imprimir facturas.')
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::FourExtraLarge),
                DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return Cai::query()->with('bodegas');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCais::route('/'),
        ];
    }

    /**
     * Bodegas activas que hoy no tienen con qué facturar.
     *
     * @return list<string>
     */
    public static function bodegasSinCai(): array
    {
        return array_values(Bodega::query()
            ->activas()
            ->orderBy('nombre')
            ->get()
            ->filter(fn (Bodega $bodega): bool => Cai::paraBodega($bodega->id) === null)
            ->map(fn (Bodega $bodega): string => $bodega->nombre)
            ->all());
    }

    /**
     * Dos CAI del mismo establecimiento, punto de emisión y documento no
     * pueden tener números en común.
     */
    private static function sinChoqueDeRango(Get $get, ?Cai $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
            $desde = (int) $get('rango_desde');
            $hasta = (int) $value;

            if ($desde < 1 || $hasta < $desde) {
                if ($hasta < $desde) {
                    $fail('El rango termina antes de empezar: revise "desde" y "hasta".');
                }

                return;
            }

            $tipo = $get('tipo_documento');

            $query = Cai::query()
                ->where('establecimiento', (string) $get('establecimiento'))
                ->where('punto_emision', (string) $get('punto_emision'))
                ->where('tipo_documento', filled($tipo) ? (string) $tipo : Cai::FACTURA)
                ->where('rango_desde', '<=', $hasta)
                ->where('rango_hasta', '>=', $desde);

            if ($record !== null) {
                $query->whereKeyNot($record->id);
            }

            $choca = $query->first();

            if ($choca !== null) {
                $fail("Ese rango choca con el CAI {$choca->cai} ({$choca->numero($choca->rango_desde)} al {$choca->numero($choca->rango_hasta)}).");
            }
        };
    }
}
