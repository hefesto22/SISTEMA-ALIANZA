<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cargas\Schemas;

use App\Domain\Cargas\ArmarCarga;
use App\Domain\Cargas\FacturasParaCargar;
use App\Domain\Cargas\HojaDeCarga;
use App\Domain\Devoluciones\Reposiciones;
use App\Enums\EntregaFactura;
use App\Enums\EstadoFactura;
use App\Filament\Support\Acceso;
use App\Models\Bodega;
use App\Models\Carga;
use App\Models\Factura;
use App\Models\Reposicion;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Armar o cambiar una carga del camión: la salida (bodega, fecha y quién
 * sale: lleva la mercadería y regresa con el dinero), los cambios por
 * entregar que lleva (si hay) y las facturas.
 *
 * Las facturas se escogen en resources/views/filament/cargas/escoger-facturas.blade.php:
 * agrupadas por día y por vendedor (se marca un grupo entero de un toque),
 * con buscador, filtro por vendedor, "Impresas desde" y, arriba, la barra
 * fija con cuántas lleva, con cuánto sale y Guardar. Todo eso pasa en el
 * navegador; al servidor solo va "Impresas desde" (trae otra lista) y Guardar.
 * Lo guarda ArmarCarga.
 */
final class CargaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('La salida')
                    ->description('Quién sale a entregar y cobrar: lleva la mercadería y regresa con el dinero para la liquidación (no tiene que ser el que vendió).')
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
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
                                $set('cambios', []);
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
                            ->helperText('Lleva la mercadería y regresa con el dinero para la liquidación.'),
                    ]),

                Section::make('Cambios por entregar')
                    ->description('Lo que se le debe a un cliente por un cambio por cambio. Marque los que lleva el camión: salen de la bodega al guardar la carga y van en la hoja de carga.')
                    ->visible(fn (Get $get, ?Carga $record): bool => self::cambiosParaEscoger($get, $record)->isNotEmpty())
                    ->schema([
                        CheckboxList::make('cambios')
                            ->hiddenLabel()
                            ->options(fn (Get $get, ?Carga $record): array => self::cambiosParaEscoger($get, $record)
                                ->mapWithKeys(fn (Reposicion $cambio): array => [$cambio->id => $cambio->etiqueta()])
                                ->all())
                            ->descriptions(fn (Get $get, ?Carga $record): array => self::cambiosParaEscoger($get, $record)
                                ->filter(fn (Reposicion $cambio): bool => $cambio->nota !== null)
                                ->mapWithKeys(fn (Reposicion $cambio): array => [$cambio->id => (string) $cambio->nota])
                                ->all())
                            ->bulkToggleable()
                            ->default([]),
                    ]),

                Section::make('Facturas que lleva')
                    ->description('Marque las facturas impresas que van en el camión. No salen las que ya van en otra carga ni las de un día que ya salió en su ruta.')
                    ->schema([
                        Hidden::make('desde')
                            ->default(fn (): string => FacturasParaCargar::desdeDe()->toDateString())
                            ->dehydrated(false),
                        ViewField::make('facturas')
                            ->hiddenLabel()
                            ->view('filament.cargas.escoger-facturas')
                            ->viewData(fn (Get $get, ?Carga $record): array => self::paraEscoger($get, $record))
                            ->default([])
                            ->required(fn (Get $get): bool => ! filled($get('cambios')))
                            ->rule('array')
                            ->validationMessages(['required' => 'Marque las facturas que lleva el camión.']),
                    ]),
            ]);
    }

    /**
     * Lo del formulario, como lo pide ArmarCarga.
     *
     * @param array<string, mixed> $data
     *
     * @return array{bodega_id: int, fecha: string, repartidor_id: int, facturas: list<int>, cambios: list<int>}
     */
    public static function datos(array $data, User $usuario, ?Carga $carga = null): array
    {
        $bodega = $carga->bodega_id ?? ($usuario->veTodasLasBodegas() ? $data['bodega_id'] ?? null : $usuario->bodega_id);
        $fecha = $data['fecha'] ?? null;
        $repartidor = $data['repartidor_id'] ?? null;

        return [
            'bodega_id'     => is_numeric($bodega) ? (int) $bodega : 0,
            'fecha'         => is_string($fecha) && $fecha !== '' ? $fecha : today()->toDateString(),
            'repartidor_id' => is_numeric($repartidor) ? (int) $repartidor : 0,
            'facturas'      => self::ids($data['facturas'] ?? null),
            'cambios'       => self::ids($data['cambios'] ?? null),
        ];
    }

    /**
     * Los números de una lista marcada (los que no son números se ignoran).
     *
     * @return list<int>
     */
    public static function ids(mixed $lista): array
    {
        return array_values(array_map(intval(...), array_filter(is_array($lista) ? $lista : [], is_numeric(...))));
    }

    /**
     * Para cambiar una carga: lo que tiene (las facturas vigentes que lleva).
     *
     * @return array<string, mixed>
     */
    public static function llenar(Carga $carga): array
    {
        // Desde la más vieja que lleva, para que todas salgan en la lista.
        $desde = FacturasParaCargar::desdeDe($carga->fecha->toDateString());
        $primera = $carga->facturas()->where('facturas.estado', EstadoFactura::Vigente->value)->min('facturas.fecha_emision');

        if (is_string($primera) && Carbon::parse($primera)->lt($desde)) {
            $desde = Carbon::parse($primera)->startOfDay();
        }

        return [
            'bodega_id'     => $carga->bodega_id,
            'fecha'         => $carga->fecha->toDateString(),
            'desde'         => $desde->toDateString(),
            'repartidor_id' => $carga->repartidor_id,
            'cambios'       => $carga->cambios()
                ->where('estado', EntregaFactura::EnRuta->value)
                ->pluck('reposicion_id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all(),
            'facturas' => $carga->facturas()
                ->where('facturas.estado', EstadoFactura::Vigente->value)
                ->orderBy('carga_factura.id')
                ->pluck('facturas.id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all(),
        ];
    }

    /**
     * Las de esos días que todavía se pueden cargar, en una carga que sale
     * el día $fecha (hoy si no se dice): "Cargar camión" de Impresos, con
     * uno o varios días escogidos.
     *
     * @param list<string> $dias "2026-11-17", "2026-11-18"
     *
     * @return Collection<int, Factura>
     */
    public static function deLosDias(int $bodegaId, array $dias, ?string $fecha = null): Collection
    {
        if ($dias === []) {
            return new Collection;
        }

        return FacturasParaCargar::de($bodegaId, $fecha, desde: min($dias))
            ->filter(fn (Factura $factura): bool => in_array($factura->fecha_emision->toDateString(), $dias, true))
            ->values();
    }

    /**
     * Los cambios por entregar que puede llevar la carga (y, al cambiarla,
     * los que ya lleva).
     *
     * @return Collection<int, Reposicion>
     */
    public static function cambiosParaEscoger(Get $get, ?Carga $carga): Collection
    {
        $bodega = $carga->bodega_id ?? self::bodega($get);

        return $bodega === null ? new Collection : Reposiciones::paraCarga($bodega, $carga?->id);
    }

    /**
     * De los cambios por entregar, los de las rutas (o, sin ruta, de los
     * vendedores) de esas facturas: los que se marcan solos al cargar el
     * camión con días de Impresos.
     *
     * @param Collection<int, Factura> $facturas con su pedido
     *
     * @return Collection<int, Reposicion>
     */
    public static function cambiosDeLasRutas(int $bodegaId, Collection $facturas): Collection
    {
        $rutas = $facturas->map(fn (Factura $factura): string => self::claveDeRuta($factura->pedido->ruta_id, $factura->pedido->vendedor_id))->unique()->all();

        return Reposiciones::paraCarga($bodegaId)
            ->filter(fn (Reposicion $cambio): bool => $cambio->pedido !== null && in_array(self::claveDeRuta($cambio->pedido->ruta_id, $cambio->pedido->vendedor_id), $rutas, true))
            ->values();
    }

    private static function claveDeRuta(?int $rutaId, int $vendedorId): string
    {
        return $rutaId !== null ? "r{$rutaId}" : "v{$vendedorId}";
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
     * Lo que muestra la lista para escoger: las facturas por día (la más
     * reciente primero) y, dentro, por ruta y vendedor (CO22 · ALVARO; las
     * de pedidos sin ruta, al final); para el navegador, cada una con su
     * total, su ruta y vendedor y el texto en que se busca; y cuántas
     * quedaron sin cargar de días atrás.
     *
     * @return array{
     *     bodega: int|null,
     *     desde: string,
     *     hasta: string,
     *     clave: string,
     *     dias: list<array{clave: string, titulo: string, cuantas: int, total: float, vendedores: list<array{clave: string, ruta: string|null, nombre: string, cuantas: int, total: float, facturas: list<array{id: string, numero: string, cliente: string, municipio: string|null, total: float}>}>}>,
     *     grupos: array<string, list<string>>,
     *     mapa: array<int, array{t: float, v: string, b: string}>,
     *     vendedores: array<string, int>,
     *     anteriores: array{cuantas: int, desde: string|null, ultimo: string|null}
     * }
     */
    public static function paraEscoger(Get $get, ?Carga $carga): array
    {
        $bodega = $carga->bodega_id ?? self::bodega($get);
        $fecha = self::fecha($get);
        $desde = self::desde($get)->toDateString();
        $facturas = $bodega === null ? new Collection : FacturasParaCargar::de($bodega, $fecha, $carga?->id, $desde);

        /** @var array<string, array<string, list<Factura>>> $porDia */
        $porDia = [];

        /** @var array<string, array{ruta: string|null, nombre: string, etiqueta: string}> $deGrupo */
        $deGrupo = [];

        foreach ($facturas as $factura) {
            $ruta = $factura->pedido->ruta;
            $vendedor = (string) $factura->pedido->vendedor->name;
            // Por número de ruta y, dentro, por vendedor; sin ruta, al final.
            $grupo = sprintf('%05d|%s', $ruta->numero ?? 99999, $vendedor);

            $deGrupo[$grupo] ??= [
                'ruta'     => $ruta?->codigo(),
                'nombre'   => $vendedor,
                'etiqueta' => ($ruta?->codigo() ?? 'Sin ruta').' · '.$vendedor,
            ];
            $porDia[$factura->fecha_emision->toDateString()][$grupo][] = $factura;
        }

        krsort($porDia);
        ksort($deGrupo);

        $dias = [];
        $grupos = [];
        $mapa = [];
        $cuentas = [];

        foreach (array_keys($porDia) as $d => $dia) {
            $porVendedor = $porDia[$dia];
            ksort($porVendedor);
            $bloques = [];
            $idsDia = [];
            $totalDia = 0.0;

            foreach (array_keys($porVendedor) as $v => $grupo) {
                $info = $deGrupo[$grupo];
                $filas = [];
                $ids = [];
                $total = 0.0;

                foreach ($porVendedor[$grupo] as $factura) {
                    $id = (string) $factura->id;
                    $numero = HojaDeCarga::corto($factura->numero);
                    $municipio = $factura->pedido->cliente->municipio?->nombre;

                    $filas[] = ['id' => $id, 'numero' => $numero, 'cliente' => $factura->cliente_nombre, 'municipio' => $municipio, 'total' => $factura->total];
                    $mapa[$factura->id] = [
                        't' => $factura->total,
                        'v' => $info['etiqueta'],
                        'b' => Str::lower(Str::ascii(implode(' ', [$numero, $factura->numero, $factura->cliente_nombre, $info['etiqueta'], $municipio]))),
                    ];
                    $ids[] = $id;
                    $total += $factura->total;
                }

                $grupos["d{$d}v{$v}"] = $ids;
                $cuentas[$grupo] = ($cuentas[$grupo] ?? 0) + count($ids);
                $bloques[] = ['clave' => "d{$d}v{$v}", 'ruta' => $info['ruta'], 'nombre' => $info['nombre'], 'cuantas' => count($ids), 'total' => round($total, 2), 'facturas' => $filas];
                $idsDia = [...$idsDia, ...$ids];
                $totalDia += $total;
            }

            $grupos["d{$d}"] = $idsDia;
            $dias[] = [
                'clave'      => "d{$d}",
                'titulo'     => mb_ucfirst(Carbon::parse($dia)->translatedFormat('l d/m')),
                'cuantas'    => count($idsDia),
                'total'      => round($totalDia, 2),
                'vendedores' => $bloques,
            ];
        }

        // Para el filtro: "CO22 · ALVARO" => cuántas, en orden de ruta.
        $vendedores = [];

        foreach ($deGrupo as $grupo => $info) {
            $vendedores[$info['etiqueta']] = $cuentas[$grupo] ?? 0;
        }

        $anteriores = $bodega === null ? ['cuantas' => 0, 'desde' => null, 'ultimo' => null] : FacturasParaCargar::anteriores($bodega, $desde, $carga?->id, $fecha);
        // Si cambia la lista, el navegador la arma de nuevo.
        $clave = md5((string) json_encode([$bodega, $fecha, $desde, array_keys($mapa), $anteriores]));

        return [
            'bodega'     => $bodega,
            'desde'      => $desde,
            'hasta'      => $fecha,
            'clave'      => $clave,
            'dias'       => $dias,
            'grupos'     => $grupos,
            'mapa'       => $mapa,
            'vendedores' => $vendedores,
            'anteriores' => $anteriores,
        ];
    }

    /**
     * La fecha de salida escogida (hoy si está vacía).
     */
    private static function fecha(Get $get): string
    {
        $fecha = $get('fecha');

        return is_string($fecha) && $fecha !== '' ? Carbon::parse($fecha)->toDateString() : today()->toDateString();
    }

    /**
     * Desde qué día se buscan las facturas ("Impresas desde"; si está vacío,
     * los últimos días antes de la salida).
     */
    private static function desde(Get $get): Carbon
    {
        $desde = $get('desde');

        if (is_string($desde) && $desde !== '') {
            return Carbon::parse($desde)->startOfDay();
        }

        return FacturasParaCargar::desdeDe(self::fecha($get));
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
