<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Domain\Cargas\HojaDeCarga;
use App\Domain\Devoluciones\RegistrarDevolucion;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\EntregaFactura;
use App\Enums\EstadoPedido;
use App\Enums\TipoDevolucion;
use App\Filament\Resources\Cargas\Pages\ListCargas;
use App\Filament\Support\Acceso;
use App\Models\Carga;
use App\Models\CargaFactura;
use App\Models\Configuracion;
use App\Models\Devolucion;
use App\Models\Pedido;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * "Devolución" en un pedido ya entregado (impreso). Primero, qué pasó:
 *
 *  - Devolvió producto: de cada producto, cuántas regresan buenas (vuelven
 *    a la bodega) y cuántas dañadas (pérdida). En un cambio se dice cómo se
 *    le entrega: se le lleva en la próxima carga (orden de cambio) o ya se
 *    le dio en el momento con lo que llevaba el camión (no queda nada por
 *    entregarle; al cliente al que al final le falte se le registra el
 *    faltante).
 *  - Le faltó producto: no recibió todo lo de su factura. Por qué: se le dio
 *    a otro cliente en un cambio (ni regresa ni es pérdida), se dañó en el
 *    camión (pérdida) o no se subió (vuelve a contar en la bodega).
 *
 * Y qué se le da: lo mismo (cambio) o crédito (con nota de crédito si está
 * encendida en Configuración). Se anota en la presentación de cada línea y,
 * de una caja, también en unidades sueltas. Lo hace RegistrarDevolucion.
 *
 * Sale en Ver pedido; por cada factura, en Ver carga y en Liquidar
 * (deLaCarga()); y en la lista de cargas, al lado de Liquidar (enLaLista():
 * se escoge la factura y abre esta misma con deUnaCarga()): lo que el
 * cliente regresó o lo que le faltó con el que salió en la ruta.
 */
final class DevolucionAction
{
    public const DEVOLVIO = 'devolvio';

    public const FALTO = 'falto';

    public const PROXIMA_CARGA = 'proxima';

    public const DEL_CAMION = 'camion';

    public const POR_OTRO = 'otro';

    public const SE_DANO = 'danado';

    public const NO_SALIO = 'bodega';

    /** Por qué le faltó, en palabras (también es el motivo si no se escribe otro). */
    public const CAUSAS = [
        self::POR_OTRO => 'Se le dio a otro cliente en un cambio',
        self::SE_DANO  => 'Se dañó en el camión',
        self::NO_SALIO => 'No se subió al camión',
    ];

    public static function make(): Action
    {
        return Action::make('devolucion')
            ->label('Devolución')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (mixed $record): bool => $record instanceof Pedido && $record->estado === EstadoPedido::Impreso && (Acceso::usuario()?->can('create', Devolucion::class) ?? false))
            ->modalHeading(fn (Pedido $record): string => "Devolución o faltante · {$record->numero} · {$record->cliente->nombre}")
            ->modalDescription('Si el cliente regresó producto, «Devolvió producto». Si no recibió todo lo de su factura, «Le faltó producto». Se anota en cajas (CJ) o en unidades sueltas (UN).')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Registrar')
            ->fillForm(fn (Pedido $record): array => [
                'que'     => self::DEVOLVIO,
                'causa'   => self::POR_OTRO,
                'tipo'    => TipoDevolucion::Cambio->value,
                'entrega' => self::PROXIMA_CARGA,
                'lineas'  => self::lineas($record),
            ])
            ->schema([
                ToggleButtons::make('que')
                    ->label('Qué pasó')
                    ->options([
                        self::DEVOLVIO => 'Devolvió producto',
                        self::FALTO    => 'Le faltó producto',
                    ])
                    ->icons([
                        self::DEVOLVIO => Heroicon::OutlinedArrowUturnLeft,
                        self::FALTO    => Heroicon::OutlinedExclamationTriangle,
                    ])
                    ->inline()
                    ->required()
                    ->live()
                    ->helperText(fn (Get $get): string => $get('que') === self::FALTO
                        ? 'No recibió todo lo que dice su factura.'
                        : 'Regresó producto que ya se le había entregado.'),
                ToggleButtons::make('causa')
                    ->label('Por qué le faltó')
                    ->options(self::CAUSAS)
                    ->icons([
                        self::POR_OTRO => Heroicon::OutlinedUserGroup,
                        self::SE_DANO  => Heroicon::OutlinedExclamationCircle,
                        self::NO_SALIO => Heroicon::OutlinedHomeModern,
                    ])
                    ->inline()
                    ->required(fn (Get $get): bool => $get('que') === self::FALTO)
                    ->visible(fn (Get $get): bool => $get('que') === self::FALTO)
                    ->live()
                    ->helperText(fn (Get $get): string => match ($get('causa')) {
                        self::SE_DANO  => 'Es pérdida: sale en el reporte de producto dañado y en el acta de baja. El que salió trae lo dañado de regreso.',
                        self::NO_SALIO => 'Nunca salió de la bodega: vuelve a contar en la existencia.',
                        default        => 'Lo que era de él se le dio a otro cliente en un cambio en la ruta: no regresa a la bodega ni es pérdida (eso ya quedó en el cambio del otro).',
                    }),
                ToggleButtons::make('tipo')
                    ->label('Qué se le da al cliente')
                    ->options(TipoDevolucion::class)
                    ->icons([
                        TipoDevolucion::Cambio->value  => Heroicon::OutlinedArrowsRightLeft,
                        TipoDevolucion::Credito->value => Heroicon::OutlinedBanknotes,
                    ])
                    ->inline()
                    ->required()
                    ->live()
                    ->helperText(fn (Get $get): string => match (true) {
                        $get('que') === self::FALTO && self::esCambio($get) => 'Se le lleva lo que le faltó: sale su orden de cambio (CAM) en Pedidos → Por imprimir; impresa, se sube a la próxima carga. La factura no cambia.',
                        Configuracion::actual()->notas_credito              => 'Con crédito se emite la nota de crédito (CAI tipo 07) con los datos de la factura.',
                        default                                             => 'Con crédito queda como crédito interno: la nota de crédito se hace a mano (se enciende en Configuración).',
                    }),
                ToggleButtons::make('entrega')
                    ->label('Cómo se le entrega el cambio')
                    ->options([
                        self::PROXIMA_CARGA => 'Se le lleva en la próxima carga',
                        self::DEL_CAMION    => 'Se le dio en el momento, del camión',
                    ])
                    ->icons([
                        self::PROXIMA_CARGA => Heroicon::OutlinedTruck,
                        self::DEL_CAMION    => Heroicon::OutlinedCheckCircle,
                    ])
                    ->inline()
                    ->required(fn (Get $get): bool => self::esCambio($get) && $get('que') !== self::FALTO)
                    ->visible(fn (Get $get): bool => self::esCambio($get) && $get('que') !== self::FALTO)
                    ->live()
                    ->helperText(fn (Get $get): string => $get('entrega') === self::DEL_CAMION
                        ? 'Ya se le dio con lo que llevaba el camión: no queda nada por entregarle. Al cliente al que al final le falte, regístrelo en su factura con «Le faltó producto» (se le dio a otro cliente).'
                        : 'Se crea su orden de cambio (CAM): sale en Pedidos → Por imprimir; impresa, se sube a la próxima carga y sale de la bodega en ese momento.'),
                Repeater::make('lineas')
                    ->label(fn (Get $get): string => $get('que') === self::FALTO ? 'Lo que le faltó' : 'Lo que regresa')
                    ->table(fn (Get $get): array => $get('que') === self::FALTO
                        ? [
                            TableColumn::make('Producto'),
                            TableColumn::make('Salió')->width('150px'),
                            TableColumn::make('Le faltó')->width('230px'),
                        ]
                        : [
                            TableColumn::make('Producto'),
                            TableColumn::make('Salió')->width('150px'),
                            TableColumn::make('Buenas (regresan a la bodega)')->width('230px'),
                            TableColumn::make('Dañadas (pérdida)')->width('230px'),
                        ])
                    ->schema([
                        Hidden::make('id'),
                        Hidden::make('producto')->dehydrated(false),
                        Hidden::make('codigo')->dehydrated(false),
                        Hidden::make('salio')->dehydrated(false),
                        Hidden::make('puede')->dehydrated(false),
                        Hidden::make('sigla')->dehydrated(false),
                        Hidden::make('base')->dehydrated(false),
                        Hidden::make('caja')->dehydrated(false),
                        TextEntry::make('ver_producto')
                            ->hiddenLabel()
                            ->state(fn (Get $get): string => (string) $get('producto'))
                            ->helperText(fn (Get $get): string => (string) $get('codigo'))
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('ver_salio')
                            ->hiddenLabel()
                            ->state(fn (Get $get): string => (string) $get('salio'))
                            ->helperText(fn (Get $get): string => (string) $get('puede')),
                        // La tercera columna: buenas o, en un faltante, lo que le faltó.
                        FusedGroup::make([
                            ...self::cantidades('buenas', fn (Get $get): bool => $get('../../que') !== self::FALTO),
                            ...self::cantidades('falto', fn (Get $get): bool => $get('../../que') === self::FALTO),
                        ])
                            ->hiddenLabel()
                            ->columns(fn (Get $get): int => $get('caja') ? 2 : 1),
                        FusedGroup::make(self::cantidades('danadas', fn (): bool => true))
                            ->hiddenLabel()
                            ->columns(fn (Get $get): int => $get('caja') ? 2 : 1)
                            ->visible(fn (Get $get): bool => $get('../../que') !== self::FALTO),
                    ])
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false),
                TextInput::make('motivo')
                    ->label('Motivo')
                    ->placeholder(fn (Get $get): string => $get('que') === self::FALTO ? 'Si no escribe nada, queda el porqué de arriba' : 'Ej. venían golpeados')
                    ->maxLength(200),
            ])
            ->action(function (array $data, Pedido $record): void {
                $usuario = Acceso::usuario();

                if ($usuario === null) {
                    return;
                }

                $falto = ($data['que'] ?? null) === self::FALTO;
                $causa = is_string($data['causa'] ?? null) && array_key_exists($data['causa'], self::CAUSAS) ? $data['causa'] : self::POR_OTRO;
                $lineas = [];

                foreach ((array) ($data['lineas'] ?? []) as $fila) {
                    if (! is_array($fila) || ! is_numeric($fila['id'] ?? null)) {
                        continue;
                    }

                    $cuantas = fn (string $campo): int => is_numeric($fila[$campo] ?? null) ? (int) $fila[$campo] : 0;

                    // En un faltante, lo que le faltó va como dañado, como bueno (no salió) o como que se le dio a otro.
                    $lineas[(int) $fila['id']] = match (true) {
                        ! $falto                  => ['buenas' => $cuantas('buenas'), 'danadas' => $cuantas('danadas'), 'buenas_un' => $cuantas('buenas_un'), 'danadas_un' => $cuantas('danadas_un')],
                        $causa === self::SE_DANO  => ['danadas' => $cuantas('falto'), 'danadas_un' => $cuantas('falto_un')],
                        $causa === self::NO_SALIO => ['buenas' => $cuantas('falto'), 'buenas_un' => $cuantas('falto_un')],
                        default                   => ['faltan' => $cuantas('falto'), 'faltan_un' => $cuantas('falto_un')],
                    };
                }

                $tipo = self::tipo($data['tipo'] ?? null);
                $delCamion = ! $falto && $tipo === TipoDevolucion::Cambio && ($data['entrega'] ?? null) === self::DEL_CAMION;
                $motivo = trim(is_string($data['motivo'] ?? null) ? $data['motivo'] : '');

                try {
                    $devolucion = app(RegistrarDevolucion::class)->ejecutar(
                        $record,
                        $tipo,
                        $lineas,
                        $usuario,
                        $motivo !== '' ? $motivo : ($falto ? 'Le faltó: '.self::CAUSAS[$causa] : null),
                        falto: $falto,
                        delCamion: $delCamion,
                    );
                } catch (ReglaNegocioException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(($falto ? 'Faltante ' : 'Devolución ').$devolucion->numero.' registrada')
                    ->body(match (true) {
                        $devolucion->conNota()                        => "Nota de crédito N° {$devolucion->nota_numero} por ".lempiras($devolucion->total).'.',
                        $devolucion->tipo === TipoDevolucion::Credito => 'Crédito interno por '.lempiras($devolucion->total).'.',
                        $delCamion                                    => 'Ya se le dio del camión. Al cliente al que al final le falte, regístrelo en su factura con «Le faltó producto».',
                        $falto                                        => "Orden de cambio {$devolucion->reposicion?->numero} con lo que le faltó: imprímala en Pedidos → Por imprimir; impresa, se sube a la próxima carga.",
                        default                                       => "Orden de cambio {$devolucion->reposicion?->numero}: imprímala en Pedidos → Por imprimir; impresa, se sube a la próxima carga.",
                    })
                    ->success()
                    ->actions([
                        Action::make('imprimirDevolucion')
                            ->label($devolucion->conNota() ? 'Imprimir nota de crédito' : 'Imprimir constancia')
                            ->url(route('devoluciones.imprimir', $devolucion), shouldOpenInNewTab: true),
                    ])
                    ->persistent()
                    ->send();
            });
    }

    /**
     * La misma, para una factura de la carga (argumento "factura": su id):
     * en Ver carga y en Liquidar, al lado de cada factura. Solo de las
     * facturas que lleva esa carga.
     */
    public static function deLaCarga(Carga $carga): Action
    {
        return self::make()
            ->record(fn (array $arguments): ?Pedido => self::pedidoDe($carga, $arguments));
    }

    /**
     * La misma, desde la lista de cargas (argumentos "carga" y "factura"):
     * solo de una carga en ruta que el usuario ve y de una factura que lleva.
     */
    public static function deUnaCarga(): Action
    {
        return self::make()
            ->record(function (array $arguments): ?Pedido {
                $usuario = Acceso::usuario();
                $carga = $usuario !== null && is_numeric($arguments['carga'] ?? null)
                    ? Carga::query()->visiblesPara($usuario)->whereKey((int) $arguments['carga'])->first()
                    : null;

                return $carga instanceof Carga && $carga->enRuta() ? self::pedidoDe($carga, $arguments) : null;
            });
    }

    /**
     * "Devolución" en la lista de cargas, al lado de Liquidar (solo en ruta):
     * se escoge la factura del cliente que devolvió (si lleva una sola, va
     * directo) y se abre el formulario de la devolución de esa factura
     * ("devolucion" de la página, deUnaCarga()).
     */
    public static function enLaLista(): Action
    {
        return Action::make('devolver')
            ->label('Devolución')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->button()
            ->size('sm')
            ->visible(fn (Carga $record): bool => $record->enRuta()
                && (Acceso::usuario()?->can('create', Devolucion::class) ?? false)
                && self::facturasDe($record) !== [])
            ->modalHeading(fn (Carga $record): string => 'Devolución · '.$record->numero())
            ->modalDescription('Escoja la factura del cliente que devolvió.')
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Siguiente')
            ->modalHidden(fn (Carga $record): bool => count(self::facturasDe($record)) === 1)
            ->fillForm(function (Carga $record): array {
                $facturas = self::facturasDe($record);

                return ['factura' => count($facturas) === 1 ? array_key_first($facturas) : null];
            })
            ->schema([
                Select::make('factura')
                    ->label('Factura')
                    ->options(fn (Carga $record): array => self::facturasDe($record))
                    ->searchable()
                    ->required(),
            ])
            ->action(function (Carga $record, array $data, ListCargas $livewire): void {
                $factura = is_numeric($data['factura'] ?? null) ? (int) $data['factura'] : null;

                if ($factura === null || ! array_key_exists($factura, self::facturasDe($record))) {
                    Notification::make()->title('Esa factura ya no va en la carga.')->warning()->send();

                    return;
                }

                $livewire->replaceMountedAction('devolucion', ['carga' => $record->id, 'factura' => $factura]);
            });
    }

    /**
     * Las facturas que lleva la carga y se pueden devolver (en ruta, vigentes,
     * de un pedido impreso): [factura_id => "PULPERIA LA ROCA · Fact. 1234 · L. 500.00"].
     *
     * @return array<int, string>
     */
    public static function facturasDe(Carga $carga): array
    {
        $opciones = [];

        foreach ($carga->renglones()->where('estado', EntregaFactura::EnRuta->value)->with('factura.pedido.cliente')->get() as $renglon) {
            $factura = $renglon->factura;

            if ($factura->estaAnulada() || $factura->pedido->estado !== EstadoPedido::Impreso) {
                continue;
            }

            $opciones[$factura->id] = implode(' · ', [$factura->pedido->cliente->nombre, 'Fact. '.HojaDeCarga::corto($factura->numero), lempiras($factura->total)]);
        }

        return $opciones;
    }

    /**
     * El pedido de la factura (argumento "factura") que lleva la carga.
     *
     * @param array<string, mixed> $arguments
     */
    private static function pedidoDe(Carga $carga, array $arguments): ?Pedido
    {
        $renglon = is_numeric($arguments['factura'] ?? null)
            ? $carga->renglones()->where('factura_id', (int) $arguments['factura'])->with('factura.pedido')->first()
            : null;

        return $renglon instanceof CargaFactura ? $renglon->factura->pedido : null;
    }

    /**
     * Lo que se escogió en "Qué se le da al cliente": el campo entrega el
     * enum (TipoDevolucion), no el texto.
     */
    private static function tipo(mixed $valor): TipoDevolucion
    {
        if ($valor instanceof TipoDevolucion) {
            return $valor;
        }

        return TipoDevolucion::tryFrom(is_string($valor) ? $valor : '') ?? TipoDevolucion::Cambio;
    }

    /**
     * Si en el formulario se escogió cambio.
     */
    private static function esCambio(Get $get): bool
    {
        $tipo = $get('tipo');

        return ($tipo instanceof TipoDevolucion ? $tipo->value : $tipo) === TipoDevolucion::Cambio->value;
    }

    /**
     * Las líneas que salieron y todavía se pueden devolver, para el
     * formulario: el producto, en qué salió ("1 CJ12"), cuánto queda por
     * devolver ("son 12 UN"; "ya devolvió 3 UN · quedan 9 UN") y en qué se
     * anota: la presentación de la línea (sigla) y, de una caja, las
     * unidades sueltas (base).
     *
     * @return list<array{id: int, producto: string, codigo: string, salio: string, puede: string, sigla: string, base: string, caja: bool, buenas: null, danadas: null, falto: null, buenas_un: null, danadas_un: null, falto_un: null}>
     */
    public static function lineas(Pedido $pedido): array
    {
        $devueltas = RegistrarDevolucion::devueltas($pedido);
        $filas = [];

        foreach ($pedido->items()->with(['producto.presentaciones.unidadMedida', 'presentacion.unidadMedida'])->orderBy('id')->get() as $item) {
            $puede = RegistrarDevolucion::puede($item, $devueltas);

            if ($puede <= 0) {
                continue;
            }

            $sigla = $item->presentacion->sigla();
            $sueltas = RegistrarDevolucion::sueltas($item);
            $contenido = max(1, $item->presentacion->contenido);
            $devuelto = $devueltas[$item->id] ?? 0;
            // Sin base (o en unidades), lo que queda se cuenta en la presentación de la línea.
            [$cuantas, $ya, $en] = $sueltas === null && $contenido > 1
                ? [intdiv($puede, $contenido), intdiv($devuelto, $contenido), $sigla]
                : [$puede, $devuelto, ($sueltas ?? $item->presentacion)->sigla()];

            $filas[] = [
                'id'         => $item->id,
                'producto'   => $item->producto->descripcion.($item->bonificacion ? ' (regalo)' : ''),
                'codigo'     => (string) $item->producto->codigo,
                'salio'      => number_format($item->cantidadFacturable()).' '.$sigla,
                'puede'      => $ya > 0 ? "ya devolvió {$ya} {$en} · quedan {$cuantas} {$en}" : ($sueltas !== null ? "son {$cuantas} {$en}" : ''),
                'sigla'      => $sigla,
                'base'       => $sueltas?->sigla() ?? '',
                'caja'       => $sueltas !== null,
                'buenas'     => null,
                'danadas'    => null,
                'falto'      => null,
                'buenas_un'  => null,
                'danadas_un' => null,
                'falto_un'   => null,
            ];
        }

        return $filas;
    }

    /**
     * Buenas, dañadas o lo que le faltó de una línea: en su presentación
     * (CJ12, UN…) y, si es una caja, también unidades sueltas (UN); van
     * juntas en la misma celda (FusedGroup).
     *
     * @param Closure(Get): bool $visible
     *
     * @return list<TextInput>
     */
    private static function cantidades(string $campo, Closure $visible): array
    {
        return [
            TextInput::make($campo)
                ->hiddenLabel()
                ->numeric()
                ->integer()
                ->minValue(0)
                ->placeholder('0')
                ->suffix(fn (Get $get): string => (string) $get('sigla'))
                ->visible($visible),
            TextInput::make($campo.'_un')
                ->hiddenLabel()
                ->numeric()
                ->integer()
                ->minValue(0)
                ->placeholder('0')
                ->suffix(fn (Get $get): string => (string) $get('base'))
                ->visible(fn (Get $get): bool => (bool) $get('caja') && $visible($get)),
        ];
    }
}
