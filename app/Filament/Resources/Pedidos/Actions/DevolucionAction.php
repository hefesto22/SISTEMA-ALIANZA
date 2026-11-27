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
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View as ViewContract;

/**
 * "Devolución" en un pedido ya entregado (impreso), en tres pasos:
 *
 *  1. Qué pasó: regresó producto dañado (pérdida), o le faltó producto de
 *     su factura (y por qué: se le dio a otro cliente en un cambio, ni
 *     regresa ni es pérdida; se dañó en el camión, pérdida; no se subió,
 *     vuelve a la bodega). Lo que no se anota estaba bien.
 *  2. Qué regresó dañado o qué le faltó, producto por producto, en CJ (la
 *     presentación de la línea) y UN (unidades sueltas), como la factura.
 *  3. Qué se le da: lo mismo en la próxima carga (orden de cambio), ya se le
 *     cambió del camión (solo al devolver: no queda nada pendiente; al
 *     cliente al que al final le faltó se le registra el faltante) o su
 *     dinero (crédito, con nota de crédito si está encendida).
 *
 * Lo hace RegistrarDevolucion.
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

    /** Qué se le da: lo mismo pero ya se le dio en el momento con lo del camión (los otros: TipoDevolucion). */
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
            ->modalHeading('Devolución o faltante')
            ->modalDescription(fn (Pedido $record): string => "{$record->cliente->nombre} · pedido {$record->numero}")
            ->modalIcon(Heroicon::OutlinedArrowUturnLeft)
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Registrar')
            // Estilo de las opciones (tarjetas) y de las cantidades.
            ->modalContentFooter(fn (): ViewContract => view('filament.devoluciones.formulario'))
            ->fillForm(fn (Pedido $record): array => [
                'que'    => self::DEVOLVIO,
                'causa'  => self::POR_OTRO,
                'tipo'   => TipoDevolucion::Cambio->value,
                'lineas' => self::lineas($record),
            ])
            ->schema([
                // Qué pasó (y, si le faltó, por qué).
                Radio::make('que')
                    ->label('¿Qué pasó?')
                    ->options([
                        self::DEVOLVIO => 'Regresó producto dañado',
                        self::FALTO    => 'Le faltó producto',
                    ])
                    ->descriptions([
                        self::DEVOLVIO => 'Dañado o vencido: es pérdida para la empresa.',
                        self::FALTO    => 'No recibió todo lo que dice su factura.',
                    ])
                    ->extraAttributes(['class' => 'dev-tarjetas'])
                    ->required()
                    ->live()
                    // "Ya se le cambió del camión" no aplica a lo que le faltó.
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        if ($get('que') === self::FALTO && $get('tipo') === self::DEL_CAMION) {
                            $set('tipo', TipoDevolucion::Cambio->value);
                        }
                    }),
                Radio::make('causa')
                    ->label('¿Por qué le faltó?')
                    ->options([
                        self::POR_OTRO => 'Se le dio a otro cliente',
                        self::SE_DANO  => 'Se dañó en el camión',
                        self::NO_SALIO => 'No se subió al camión',
                    ])
                    ->descriptions([
                        self::POR_OTRO => 'Se usó en la ruta para un cambio. No regresa ni es pérdida.',
                        self::SE_DANO  => 'Es pérdida. Lo dañado regresa con el camión.',
                        self::NO_SALIO => 'Nunca salió: vuelve a contar en la bodega.',
                    ])
                    ->extraAttributes(['class' => 'dev-tarjetas'])
                    ->required(fn (Get $get): bool => $get('que') === self::FALTO)
                    ->visible(fn (Get $get): bool => $get('que') === self::FALTO),

                // Lo dañado o lo que le faltó, producto por producto, en cajas y en unidades (como en la factura).
                Repeater::make('lineas')
                    ->label(fn (Get $get): string => $get('que') === self::FALTO ? '¿Qué le faltó?' : '¿Qué regresó dañado?')
                    ->helperText(fn (Get $get): string => $get('que') === self::FALTO
                        ? 'Deje en blanco lo que sí recibió.'
                        : 'Deje en blanco lo que estaba bien.')
                    ->table([
                        TableColumn::make('Producto'),
                        TableColumn::make('Salió')->width('140px'),
                        TableColumn::make('CJ')->width('110px'),
                        TableColumn::make('UN')->width('110px'),
                    ])
                    ->schema([
                        Hidden::make('id'),
                        Hidden::make('producto')->dehydrated(false),
                        Hidden::make('codigo')->dehydrated(false),
                        Hidden::make('salio')->dehydrated(false),
                        Hidden::make('puede')->dehydrated(false),
                        Hidden::make('con_cj')->dehydrated(false),
                        Hidden::make('con_un')->dehydrated(false),
                        TextEntry::make('ver_producto')
                            ->hiddenLabel()
                            ->state(fn (Get $get): string => (string) $get('producto'))
                            ->helperText(fn (Get $get): string => (string) $get('codigo'))
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('ver_salio')
                            ->hiddenLabel()
                            ->state(fn (Get $get): string => (string) $get('salio'))
                            ->helperText(fn (Get $get): string => (string) $get('puede')),
                        self::cantidad('cj', 'con_cj'),
                        self::cantidad('un', 'con_un'),
                    ])
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false),

                // Qué se le da: una sola decisión.
                Radio::make('tipo')
                    ->label('¿Qué se le da?')
                    ->options(fn (Get $get): array => $get('que') === self::FALTO
                        ? [
                            TipoDevolucion::Cambio->value  => 'Se le lleva en la próxima carga',
                            TipoDevolucion::Credito->value => 'Su dinero',
                        ]
                        : [
                            TipoDevolucion::Cambio->value  => 'Lo mismo, en la próxima carga',
                            self::DEL_CAMION               => 'Ya se le cambió del camión',
                            TipoDevolucion::Credito->value => 'Su dinero',
                        ])
                    ->descriptions(fn (): array => [
                        TipoDevolucion::Cambio->value  => 'Sale una orden de cambio para imprimir y subir al camión. La factura no cambia.',
                        self::DEL_CAMION               => 'Ya lo recibió: no queda nada pendiente.',
                        TipoDevolucion::Credito->value => Configuracion::actual()->notas_credito
                            ? 'Nota de crédito por lo que pagó.'
                            : 'Crédito interno por lo que pagó.',
                    ])
                    ->extraAttributes(['class' => 'dev-tarjetas'])
                    ->required()
                    ->live(),

                TextInput::make('motivo')
                    ->label('Motivo (opcional)')
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

                    // CJ: en la presentación de la línea (la caja); UN: unidades sueltas (en una línea en unidades, lo mismo).
                    $cajas = is_numeric($fila['cj'] ?? null) ? (int) $fila['cj'] : 0;
                    $unidades = is_numeric($fila['un'] ?? null) ? (int) $fila['un'] : 0;

                    // Lo que regresó es dañado (pérdida). Lo que le faltó va como dañado, como bueno (no salió) o como que se le dio a otro.
                    $lineas[(int) $fila['id']] = match (true) {
                        ! $falto, $causa === self::SE_DANO => ['danadas' => $cajas, 'danadas_un' => $unidades],
                        $causa === self::NO_SALIO          => ['buenas' => $cajas, 'buenas_un' => $unidades],
                        default                            => ['faltan' => $cajas, 'faltan_un' => $unidades],
                    };
                }

                // "Ya se le cambió del camión" es un cambio que no deja nada por entregar.
                $delCamion = ! $falto && ($data['tipo'] ?? null) === self::DEL_CAMION;
                $tipo = $delCamion ? TipoDevolucion::Cambio : self::tipo($data['tipo'] ?? null);
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
     * Las líneas que salieron y todavía se pueden devolver, para el
     * formulario: el producto, en qué salió ("1 CJ12"), cuánto queda por
     * devolver ("son 12 UN"; "ya devolvió 3 UN · quedan 9 UN") y en qué se
     * anota: CJ si la línea es una caja (en su presentación) y UN si es en
     * unidades o la caja se puede abrir en sueltas.
     *
     * @return list<array{id: int, producto: string, codigo: string, salio: string, puede: string, con_cj: bool, con_un: bool, cj: null, un: null}>
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
                'id'       => $item->id,
                'producto' => $item->producto->descripcion.($item->bonificacion ? ' (regalo)' : ''),
                'codigo'   => (string) $item->producto->codigo,
                'salio'    => number_format($item->cantidadFacturable()).' '.$sigla,
                'puede'    => $ya > 0 ? "ya devolvió {$ya} {$en} · quedan {$cuantas} {$en}" : ($sueltas !== null ? "son {$cuantas} {$en}" : ''),
                'con_cj'   => $contenido > 1,
                'con_un'   => $contenido === 1 || $sueltas !== null,
                'cj'       => null,
                'un'       => null,
            ];
        }

        return $filas;
    }

    /**
     * La cantidad de la línea en cajas (CJ) o en unidades (UN). Si la línea
     * no se cuenta así (una en unidades no tiene CJ; una caja que no se abre,
     * UN), la casilla sale apagada con una raya.
     */
    private static function cantidad(string $campo, string $aplica): TextInput
    {
        return TextInput::make($campo)
            ->hiddenLabel()
            ->numeric()
            ->integer()
            ->minValue(0)
            ->extraInputAttributes(['class' => 'dev-numero'])
            ->disabled(fn (Get $get): bool => ! $get($aplica))
            ->placeholder(fn (Get $get): ?string => $get($aplica) ? null : '—');
    }
}
