<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cargas\Schemas;

use App\Domain\Cargas\ArmarCarga;
use App\Domain\Cargas\FacturasParaCargar;
use App\Enums\EstadoFactura;
use App\Filament\Support\Acceso;
use App\Models\Bodega;
use App\Models\Carga;
use App\Models\Factura;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Component;
use WeakMap;

/**
 * Armar o cambiar una carga del camión: la salida (bodega, fecha, quién
 * sale, camión) y las facturas que lleva, escogidas de una lista que se
 * busca por número, cliente, vendedor o municipio. Arriba de la lista, con
 * cuántas facturas y con cuánto sale. Lo guarda ArmarCarga.
 */
final class CargaForm
{
    /**
     * Lo consultado en la petición, por pantalla abierta (cada petición trae otra).
     *
     * @var WeakMap<Component, array<string, Collection<int, Factura>>>|null
     */
    private static ?WeakMap $consultadas = null;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('La salida')
                    ->description('Quién sale a entregar y cobrar (no tiene que ser el que vendió) y en qué camión.')
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                    ->schema([
                        Select::make('bodega_id')
                            ->label('Bodega')
                            ->options(fn (): array => Bodega::query()->orderBy('nombre')->pluck('nombre', 'id')->all())
                            ->visible(fn (): bool => Acceso::veTodo())
                            ->disabled(fn (?Carga $record): bool => $record !== null)
                            ->required(fn (): bool => Acceso::veTodo())
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('repartidor_id', null);
                                $set('facturas', []);
                            }),
                        DatePicker::make('fecha')
                            ->label('Fecha de salida')
                            ->default(fn (): string => today()->toDateString())
                            ->maxDate(fn (): string => today()->addDays(2)->toDateString())
                            ->required()
                            ->live(),
                        Select::make('repartidor_id')
                            ->label('Quién sale')
                            ->options(fn (Get $get): array => self::repartidores(self::bodega($get)))
                            ->searchable()
                            ->required()
                            ->helperText('Entrega las facturas y trae el dinero.'),
                        TextInput::make('camion')
                            ->label('Camión o placa')
                            ->placeholder('Ej. PAB 1234 (opcional)')
                            ->maxLength(40),
                    ]),

                Section::make('Facturas que lleva')
                    ->description('Las impresas de los últimos '.FacturasParaCargar::DIAS.' días que no van en otra carga. Busque por número, cliente, vendedor o municipio; "Seleccionar todas" marca las que se ven.')
                    ->schema([
                        TextEntry::make('lleva')
                            ->hiddenLabel()
                            ->state(fn (Get $get, ?Carga $record, Component $livewire): string => self::lleva($get, $record, $livewire))
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold),
                        TextEntry::make('sin_facturas')
                            ->hiddenLabel()
                            ->state(fn (Get $get): string => self::bodega($get) === null
                                ? 'Escoja la bodega para ver sus facturas.'
                                : 'No hay facturas impresas para cargar: las de los últimos '.FacturasParaCargar::DIAS.' días ya van en una carga o ya se entregaron.')
                            ->color('gray')
                            ->visible(fn (Get $get, ?Carga $record, Component $livewire): bool => self::facturas($get, $record, $livewire)->isEmpty()),
                        CheckboxList::make('facturas')
                            ->hiddenLabel()
                            ->options(fn (Get $get, ?Carga $record, Component $livewire): array => self::facturas($get, $record, $livewire)->mapWithKeys(fn (Factura $factura): array => [$factura->id => FacturasParaCargar::etiqueta($factura)])->all())
                            ->descriptions(fn (Get $get, ?Carga $record, Component $livewire): array => self::facturas($get, $record, $livewire)->mapWithKeys(fn (Factura $factura): array => [$factura->id => FacturasParaCargar::descripcion($factura)])->all())
                            ->searchable()
                            ->searchPrompt('Buscar número, cliente, vendedor o municipio…')
                            ->noSearchResultsMessage('Ninguna factura coincide.')
                            ->bulkToggleable()
                            ->columns(['default' => 1, 'lg' => 2, '2xl' => 3])
                            ->required()
                            ->validationMessages(['required' => 'Escoja las facturas que lleva el camión.'])
                            ->live()
                            ->visible(fn (Get $get, ?Carga $record, Component $livewire): bool => self::facturas($get, $record, $livewire)->isNotEmpty()),
                    ]),
            ]);
    }

    /**
     * Lo del formulario, como lo pide ArmarCarga.
     *
     * @param array<string, mixed> $data
     *
     * @return array{bodega_id: int, fecha: string, repartidor_id: int, camion: string|null, facturas: list<int>}
     */
    public static function datos(array $data, User $usuario, ?Carga $carga = null): array
    {
        $bodega = $carga->bodega_id ?? ($usuario->veTodasLasBodegas() ? $data['bodega_id'] ?? null : $usuario->bodega_id);
        $fecha = $data['fecha'] ?? null;
        $repartidor = $data['repartidor_id'] ?? null;
        $camion = $data['camion'] ?? null;
        $facturas = $data['facturas'] ?? null;

        return [
            'bodega_id'     => is_numeric($bodega) ? (int) $bodega : 0,
            'fecha'         => is_string($fecha) && $fecha !== '' ? $fecha : today()->toDateString(),
            'repartidor_id' => is_numeric($repartidor) ? (int) $repartidor : 0,
            'camion'        => is_string($camion) ? $camion : null,
            'facturas'      => array_values(array_map(intval(...), array_filter(is_array($facturas) ? $facturas : [], is_numeric(...)))),
        ];
    }

    /**
     * Para cambiar una carga: lo que tiene (las facturas vigentes que lleva).
     *
     * @return array<string, mixed>
     */
    public static function llenar(Carga $carga): array
    {
        return [
            'bodega_id'     => $carga->bodega_id,
            'fecha'         => $carga->fecha->toDateString(),
            'repartidor_id' => $carga->repartidor_id,
            'camion'        => $carga->camion,
            'facturas'      => $carga->facturas()
                ->where('facturas.estado', EstadoFactura::Vigente->value)
                ->orderBy('carga_factura.id')
                ->pluck('facturas.id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all(),
        ];
    }

    /**
     * Las de un día que todavía se pueden cargar (el botón "Cargar camión" de Impresos).
     *
     * @return list<string>
     */
    public static function delDia(int $bodegaId, string $dia): array
    {
        return array_values(FacturasParaCargar::de($bodegaId)
            ->filter(fn (Factura $factura): bool => $factura->fecha_emision->toDateString() === $dia)
            ->map(fn (Factura $factura): string => (string) $factura->id)
            ->all());
    }

    /**
     * La bodega de la carga: la escogida (administración) o la del usuario.
     */
    public static function bodega(Get $get): ?int
    {
        $usuario = Acceso::usuario();

        if ($usuario === null) {
            return null;
        }

        $bodega = $usuario->veTodasLasBodegas() ? $get('bodega_id') : $usuario->bodega_id;

        return is_numeric($bodega) ? (int) $bodega : null;
    }

    /**
     * Los usuarios activos de la bodega, para "Quién sale".
     *
     * @return array<int, string>
     */
    public static function repartidores(?int $bodegaId): array
    {
        if ($bodegaId === null) {
            return [];
        }

        /** @var array<int, string> $usuarios */
        $usuarios = User::query()
            ->where('is_active', true)
            ->where('bodega_id', $bodegaId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        return $usuarios;
    }

    /**
     * Las que se pueden escoger (una sola consulta por bodega, fecha y carga en cada petición).
     *
     * @return Collection<int, Factura>
     */
    private static function facturas(Get $get, ?Carga $carga, Component $livewire): Collection
    {
        $bodega = $carga->bodega_id ?? self::bodega($get);

        if ($bodega === null) {
            return new Collection;
        }

        $fecha = is_string($get('fecha')) && $get('fecha') !== '' ? Carbon::parse($get('fecha'))->toDateString() : today()->toDateString();
        $clave = $bodega.'|'.$fecha.'|'.($carga->id ?? 0);

        $memoria = self::$consultadas;

        if ($memoria === null) {
            /** @var WeakMap<Component, array<string, Collection<int, Factura>>> $memoria */
            $memoria = new WeakMap;
            self::$consultadas = $memoria;
        }

        $deEsta = $memoria[$livewire] ?? [];

        if (! isset($deEsta[$clave])) {
            $deEsta[$clave] = FacturasParaCargar::de($bodega, $fecha, $carga?->id);
            $memoria[$livewire] = $deEsta;
        }

        return $deEsta[$clave];
    }

    /**
     * "Lleva 12 facturas · sale con L 23,450.00".
     */
    private static function lleva(Get $get, ?Carga $carga, Component $livewire): string
    {
        $escogidas = array_map(intval(...), array_filter((array) $get('facturas'), is_numeric(...)));
        $lleva = self::facturas($get, $carga, $livewire)->whereIn('id', $escogidas);
        $total = (float) $lleva->sum('total');
        $cuantas = $lleva->count();

        return match ($cuantas) {
            0       => 'Todavía no lleva facturas',
            1       => 'Lleva 1 factura · sale con '.lempiras($total),
            default => "Lleva {$cuantas} facturas · sale con ".lempiras($total),
        };
    }

    /**
     * Arma o cambia la carga con lo del formulario.
     *
     * @param array<string, mixed> $data
     */
    public static function armar(?Carga $carga, array $data, User $usuario): Carga
    {
        return app(ArmarCarga::class)->ejecutar($carga, self::datos($data, $usuario, $carga), $usuario);
    }
}
